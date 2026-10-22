<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverSignedWebhookJob;
use App\Models\ApiClient;
use App\Models\ApiIdempotencyRecord;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\HrValidationCase;
use App\Models\JobPosting;
use App\Models\Requisition;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Support\ApiClientPrincipal;
use App\Support\WebhookSsrfGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ApiAndMcpPlatformService
{
    public const ALLOWLISTED_MCP_TOOLS = [
        'requisition.list' => [
            'category' => 'read',
            'required_permission' => 'requisitions.view',
            'requires_confirmation' => false,
            'description' => 'List tenant-scoped workforce requisitions with status and headcount.',
        ],
        'job.list' => [
            'category' => 'read',
            'required_permission' => 'jobs.manage',
            'requires_confirmation' => false,
            'description' => 'List tenant-scoped job postings and publication states.',
        ],
        'candidate.search' => [
            'category' => 'read',
            'required_permission' => 'candidates.view',
            'requires_confirmation' => false,
            'description' => 'Search tenant-scoped candidates by name or email with consent status.',
        ],
        'application.get' => [
            'category' => 'read',
            'required_permission' => 'candidates.view',
            'requires_confirmation' => false,
            'description' => 'Inspect a tenant-scoped application and its stage history.',
        ],
        'hr_validation.status' => [
            'category' => 'read',
            'required_permission' => 'requisitions.view',
            'requires_confirmation' => false,
            'description' => 'Inspect the HR validation & handover safety gate status for an application.',
        ],
        'analytics.summary' => [
            'category' => 'read',
            'required_permission' => 'reports.view',
            'requires_confirmation' => false,
            'description' => 'Retrieve tenant-scoped recruitment funnel and handover health counts.',
        ],
    ];

    public const PROHIBITED_MCP_TOOLS = [
        'sql.raw',
        'shell.exec',
        'filesystem.read',
        'secrets.inspect',
        'hr.create_employee_unapproved',
    ];

    public function __construct(
        private readonly AuditService $auditService,
        private readonly WebhookSsrfGuard $ssrfGuard,
    ) {}

    /**
     * @param  array<int, string>  $scopes
     * @return array{client: ApiClient, raw_secret: string}
     */
    public function createApiClient(string $name, array $scopes, int $tenantId, User $actor): array
    {
        $clientId = 'urap_cli_'.Str::lower(Str::random(16));
        $rawSecret = 'urap_sec_'.Str::random(32);

        $client = ApiClient::query()->create([
            'tenant_id' => $tenantId,
            'name' => trim($name),
            'client_id' => $clientId,
            'client_secret_hash' => hash('sha256', $rawSecret),
            'scopes' => array_values(array_unique($scopes)),
            'rate_limit_per_minute' => 60,
            'is_active' => true,
        ]);

        $this->auditService->record(
            module: 'api_platform',
            action: 'api_client.created',
            entityType: 'api_client',
            entityId: (string) $client->id,
            metadata: ['client_id' => $clientId, 'scopes' => $client->scopes],
            tenantId: $tenantId,
            actorUserId: (int) $actor->id,
        );

        return ['client' => $client, 'raw_secret' => $rawSecret];
    }

    /**
     * Enforces atomic, concurrency-safe Idempotency-Key semantics:
     * - Reserves `(tenant_id, idempotency_key)` inside a transaction with row locking (`lockForUpdate()`)
     * - Handles unique-constraint race condition deterministically without unhandled 500s
     * - Same key + same request_hash -> returns cached status & body without re-running callback
     * - Same key + different request_hash -> throws RuntimeException('IDEMPOTENCY_CONFLICT', 409)
     * - Rolls back reservation if callback fails
     *
     * @param  array<string, mixed>  $requestPayload
     * @param  callable(): array{status: int, body: array<string, mixed>}  $callback
     * @return array{status: int, body: array<string, mixed>, replayed: bool}
     */
    public function executeIdempotent(
        int $tenantId,
        string $idempotencyKey,
        string $method,
        string $path,
        array $requestPayload,
        callable $callback,
    ): array {
        $canonicalPayload = $this->canonicalizeArray($requestPayload);
        $requestHash = hash('sha256', strtoupper($method).'|'.$path.'|'.json_encode($canonicalPayload));

        return DB::transaction(function () use ($tenantId, $idempotencyKey, $method, $path, $requestHash, $callback): array {
            /** @var ApiIdempotencyRecord|null $existing */
            $existing = ApiIdempotencyRecord::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $this->resolveExistingIdempotencyRecord($existing, $requestHash);
            }

            try {
                /** @var ApiIdempotencyRecord $reservation */
                $reservation = ApiIdempotencyRecord::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'idempotency_key' => $idempotencyKey,
                    'method' => strtoupper($method),
                    'path' => $path,
                    'request_hash' => $requestHash,
                    'status' => 'IN_PROGRESS',
                    'response_status' => 0,
                    'response_body' => [],
                    'expires_at' => now()->addHours(24),
                ]);
            } catch (QueryException $e) {
                // Recover deterministically from concurrent unique constraint race on (tenant_id, idempotency_key)
                if ($this->isUniqueConstraintViolation($e)) {
                    /** @var ApiIdempotencyRecord|null $raced */
                    $raced = ApiIdempotencyRecord::withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($raced !== null) {
                        return $this->resolveExistingIdempotencyRecord($raced, $requestHash);
                    }
                }

                throw $e;
            }

            $result = $callback();

            $reservation->update([
                'status' => 'COMPLETED',
                'response_status' => (int) $result['status'],
                'response_body' => (array) $result['body'],
            ]);

            return [
                'status' => (int) $result['status'],
                'body' => (array) $result['body'],
                'replayed' => false,
            ];
        });
    }

    /**
     * @param  array<int, string>  $subscribedEvents
     */
    public function createWebhookSubscription(string $name, string $targetUrl, array $subscribedEvents, int $tenantId, User $actor): WebhookSubscription
    {
        $this->ssrfGuard->assertSafeTargetUrl($targetUrl);

        $signingSecret = 'whsec_'.Str::random(32);

        $sub = WebhookSubscription::query()->create([
            'tenant_id' => $tenantId,
            'name' => trim($name),
            'target_url' => trim($targetUrl),
            'signing_secret' => $signingSecret,
            'subscribed_events' => $subscribedEvents,
            'is_active' => true,
        ]);

        $this->auditService->record(
            module: 'api_platform',
            action: 'webhook_subscription.created',
            entityType: 'webhook_subscription',
            entityId: (string) $sub->id,
            metadata: ['target_url' => $sub->target_url, 'subscribed_events' => $subscribedEvents],
            tenantId: $tenantId,
            actorUserId: (int) $actor->id,
        );

        return $sub;
    }

    /**
     * Enqueues a signed outbound webhook delivery in PENDING state and dispatches DeliverSignedWebhookJob.
     * Never marks a delivery as DELIVERED until a real outbound HTTP 2xx response is received.
     *
     * @param  array<string, mixed>  $data
     */
    public function dispatchSignedWebhook(WebhookSubscription $subscription, string $eventType, array $data, ?User $actor = null): WebhookDelivery
    {
        $eventId = 'evt_'.Str::lower(Str::random(20));
        $timestamp = (string) now()->timestamp;

        $envelope = [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'tenant_id' => $subscription->tenant_id,
            'occurred_at' => now()->toIso8601String(),
            'data' => $data,
        ];

        $rawJson = (string) json_encode($envelope);
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$rawJson, (string) $subscription->signing_secret);

        $delivery = WebhookDelivery::query()->create([
            'tenant_id' => $subscription->tenant_id,
            'webhook_subscription_id' => $subscription->id,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'payload' => $envelope,
            'signature_header' => $signature,
            'response_status' => null,
            'attempt_count' => 0,
            'status' => 'PENDING',
            'queued_at' => now(),
            'delivered_at' => null,
        ]);

        $this->auditService->record(
            module: 'api_platform',
            action: 'webhook.queued',
            entityType: 'webhook_delivery',
            entityId: (string) $delivery->id,
            metadata: [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'subscription_id' => $subscription->id,
                'status' => 'PENDING',
            ],
            tenantId: (int) $subscription->tenant_id,
            actorUserId: $actor ? (int) $actor->id : null,
        );

        DeliverSignedWebhookJob::dispatch((int) $delivery->id);

        return $delivery->fresh();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function invokeMcpTool(
        string $toolName,
        array $arguments,
        User|ApiClientPrincipal $actor,
        int $tenantId,
    ): array {
        $actorUserId = $actor instanceof User ? (int) $actor->id : null;
        $actorType = $actor instanceof ApiClientPrincipal ? $actor->getActorType() : 'USER';
        $actorIdentifier = $actor instanceof ApiClientPrincipal ? $actor->getActorIdentifier() : $actor->email;

        if (in_array($toolName, self::PROHIBITED_MCP_TOOLS, true) || ! array_key_exists($toolName, self::ALLOWLISTED_MCP_TOOLS)) {
            $this->auditService->record(
                module: 'mcp_server',
                action: 'mcp.tool_denied',
                outcome: 'DENIED',
                errorCode: 'MCP_TOOL_PROHIBITED',
                entityType: 'mcp_tool',
                entityId: $toolName,
                metadata: ['tool_name' => $toolName],
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                actorType: $actorType,
                actorIdentifier: $actorIdentifier,
            );

            throw new InvalidArgumentException("MCP tool '{$toolName}' is not allowlisted or is explicitly prohibited (MCP_TOOL_PROHIBITED).");
        }

        // Enforce explicit per-tool least-privilege scope / permission (never inherited from any human user)
        $toolMeta = self::ALLOWLISTED_MCP_TOOLS[$toolName];
        $requiredPerm = $toolMeta['required_permission'];

        $authorized = $actor instanceof ApiClientPrincipal
            ? ($actor->hasScope('mcp.use') && $actor->hasPermission($requiredPerm, $tenantId))
            : ($actor->hasPermission($requiredPerm, $tenantId) || $actor->hasRole('tenant_admin', $tenantId));

        if (! $authorized) {
            $this->auditService->record(
                module: 'mcp_server',
                action: 'mcp.tool_forbidden',
                outcome: 'DENIED',
                errorCode: 'MCP_PERMISSION_DENIED',
                entityType: 'mcp_tool',
                entityId: $toolName,
                metadata: [
                    'required_permission' => $requiredPerm,
                    'actor_type' => $actorType,
                ],
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                actorType: $actorType,
                actorIdentifier: $actorIdentifier,
            );

            throw new AuthorizationException("Caller lacks required permission '{$requiredPerm}' for MCP tool '{$toolName}'.");
        }

        $result = match ($toolName) {
            'requisition.list' => [
                'items' => Requisition::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->latest('id')
                    ->limit(10)
                    ->get(['id', 'uuid', 'requisition_number', 'title', 'status', 'headcount'])
                    ->toArray(),
            ],
            'job.list' => [
                'items' => JobPosting::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->latest('id')
                    ->limit(10)
                    ->get(['id', 'uuid', 'slug', 'title', 'status'])
                    ->toArray(),
            ],
            'candidate.search' => [
                'items' => Candidate::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->when(! empty($arguments['query']), function ($q) use ($arguments) {
                        $term = '%'.$arguments['query'].'%';
                        $q->where(function ($sub) use ($term) {
                            $sub->where('first_name', 'like', $term)
                                ->orWhere('last_name', 'like', $term)
                                ->orWhere('email', 'like', $term);
                        });
                    })
                    ->latest('id')
                    ->limit(10)
                    ->get(['id', 'uuid', 'candidate_code', 'first_name', 'last_name', 'email', 'consent_given'])
                    ->toArray(),
            ],
            'application.get' => [
                'application' => Application::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->with(['candidate:id,first_name,last_name,email', 'jobPosting:id,title,slug'])
                    ->findOrFail((int) ($arguments['application_id'] ?? 0))
                    ->toArray(),
            ],
            'hr_validation.status' => [
                'cases' => HrValidationCase::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->get(['id', 'application_id', 'status', 'checklist_version', 'invalidated_at'])
                    ->toArray(),
            ],
            default => [
                'requisitions_count' => Requisition::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
                'published_jobs_count' => JobPosting::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('status', 'PUBLISHED_PUBLIC')->count(),
                'candidates_count' => Candidate::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
                'applications_count' => Application::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
            ],
        };

        $this->auditService->record(
            module: 'mcp_server',
            action: 'mcp.tool_invoked',
            entityType: 'mcp_tool',
            entityId: $toolName,
            metadata: [
                'tool_name' => $toolName,
                'required_permission' => $requiredPerm,
            ],
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            actorType: $actorType,
            actorIdentifier: $actorIdentifier,
        );

        return [
            'tool' => $toolName,
            'tenant_id' => $tenantId,
            'result' => $result,
        ];
    }

    /**
     * @return array{status: int, body: array<string, mixed>, replayed: bool}
     */
    private function resolveExistingIdempotencyRecord(ApiIdempotencyRecord $existing, string $requestHash): array
    {
        if (! hash_equals($existing->request_hash, $requestHash)) {
            throw new RuntimeException(
                'Idempotency-Key was already used with a different request payload (IDEMPOTENCY_CONFLICT).',
                409
            );
        }

        if ($existing->status === 'IN_PROGRESS') {
            throw new RuntimeException(
                'A concurrent request with this Idempotency-Key is currently in progress (IDEMPOTENCY_CONFLICT).',
                409
            );
        }

        return [
            'status' => (int) $existing->response_status,
            'body' => (array) $existing->response_body,
            'replayed' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function canonicalizeArray(array $data): array
    {
        ksort($data);
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->canonicalizeArray($v);
            }
        }

        return $data;
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000'
            || $sqlState === '23505'
            || $driverCode === 1062
            || str_contains(strtolower($e->getMessage()), 'unique')
            || str_contains(strtolower($e->getMessage()), 'duplicate entry');
    }
}
