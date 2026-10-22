<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\HrHandoverGateNotSatisfiedException;
use App\Models\ApiClient;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\Department;
use App\Models\HrHandover;
use App\Models\HrValidationCase;
use App\Models\JobPosting;
use App\Models\LegalEntity;
use App\Models\Location;
use App\Models\Requisition;
use App\Models\ResumeParseJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApiAndMcpPlatformService;
use App\Services\AuditService;
use App\Services\CandidateAndApplicationService;
use App\Services\OfferAndHrHandoverService;
use App\Services\PlatformConfigService;
use App\Services\RequisitionService;
use App\Services\ResumeParsingService;
use App\Services\TenantContextService;
use App\Support\ApiClientPrincipal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class ApiV1AndMcpController extends Controller
{
    public function __construct(
        private readonly ApiAndMcpPlatformService $platformService,
        private readonly RequisitionService $requisitionService,
        private readonly CandidateAndApplicationService $candidateAppService,
        private readonly OfferAndHrHandoverService $handoverService,
        private readonly ResumeParsingService $resumeParsingService,
        private readonly TenantContextService $tenantContext,
        private readonly AuditService $audit,
    ) {}

    public function listRequisitions(Request $request): JsonResponse
    {
        [$tenantId] = $this->resolveApiAuthContext($request, 'requisitions.view');

        $items = Requisition::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->limit(25)
            ->get();

        return $this->envelope(['items' => $items]);
    }

    public function createRequisition(Request $request): JsonResponse
    {
        [$tenantId, $actor] = $this->resolveApiAuthContext($request, 'requisitions.create');

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');
        if ($idempotencyKey === '') {
            return $this->errorEnvelope('IDEMPOTENCY_KEY_REQUIRED', 'Mutating API requests require an Idempotency-Key header.', 400);
        }

        $payload = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'headcount' => ['required', 'integer', 'min:1', 'max:100'],
            'employment_type' => ['nullable', 'string', 'max:40'],
            'workplace_type' => ['nullable', 'string', 'max:40'],
            'salary_min_minor' => ['nullable', 'integer'],
            'salary_max_minor' => ['nullable', 'integer'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'justification' => ['nullable', 'string'],
        ]);

        try {
            $outcome = $this->platformService->executeIdempotent(
                $tenantId,
                $idempotencyKey,
                'POST',
                '/api/v1/requisitions',
                $payload,
                function () use ($tenantId, $actor, $payload): array {
                    $le = LegalEntity::withoutGlobalScopes()->where('tenant_id', $tenantId)->firstOrFail();
                    $dept = Department::withoutGlobalScopes()->where('tenant_id', $tenantId)->firstOrFail();
                    $loc = Location::withoutGlobalScopes()->where('tenant_id', $tenantId)->firstOrFail();

                    $req = $this->requisitionService->createRequisition([
                        'legal_entity_id' => $le->id,
                        'department_id' => $dept->id,
                        'location_id' => $loc->id,
                        'hiring_manager_user_id' => $actor instanceof User ? $actor->id : null,
                        'title' => $payload['title'],
                        'employment_type' => $payload['employment_type'] ?? 'FULL_TIME',
                        'headcount' => (int) $payload['headcount'],
                        'budget_reference' => 'BUD-API-'.date('Y'),
                        'salary_min' => (int) (($payload['salary_min_minor'] ?? 12000000) / 100),
                        'salary_max' => (int) (($payload['salary_max_minor'] ?? 16000000) / 100),
                        'currency_code' => $payload['currency_code'] ?? 'USD',
                        'justification' => $payload['justification'] ?? 'Created via REST API v1',
                    ], $actor);

                    return [
                        'status' => 201,
                        'body' => [
                            'id' => $req->id,
                            'uuid' => $req->uuid,
                            'requisition_number' => $req->requisition_number,
                            'title' => $req->title,
                            'status' => $req->status,
                        ],
                    ];
                }
            );

            return $this->envelope($outcome['body'], $outcome['status'], ['idempotent_replay' => $outcome['replayed']]);
        } catch (RuntimeException $e) {
            return $this->errorEnvelope('IDEMPOTENCY_CONFLICT', $e->getMessage(), 409);
        }
    }

    public function listJobs(Request $request): JsonResponse
    {
        [$tenantId] = $this->resolveApiAuthContext($request, 'jobs.manage');

        $items = JobPosting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->limit(25)
            ->get();

        return $this->envelope(['items' => $items]);
    }

    public function listCandidates(Request $request): JsonResponse
    {
        [$tenantId] = $this->resolveApiAuthContext($request, 'candidates.view');

        $query = Candidate::withoutGlobalScopes()
            ->where('tenant_id', $tenantId);

        if ($request->filled('q')) {
            $q = trim($request->string('q')->toString());
            if ($q !== '') {
                $query->where(function ($sub) use ($q) {
                    $sub->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('current_title', 'like', "%{$q}%")
                        ->orWhere('skills', 'like', "%{$q}%");
                });
            }
        }

        if ($request->filled('skill')) {
            $skill = trim($request->string('skill')->toString());
            if ($skill !== '') {
                $query->where('skills', 'like', "%{$skill}%");
            }
        }

        $items = $query->latest('id')->limit(25)->get();

        return $this->envelope(['items' => $items]);
    }

    public function createCandidate(Request $request): JsonResponse
    {
        [$tenantId, $actor] = $this->resolveApiAuthContext($request, 'candidates.manage');

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');
        if ($idempotencyKey === '') {
            return $this->errorEnvelope('IDEMPOTENCY_KEY_REQUIRED', 'Mutating API requests require an Idempotency-Key header.', 400);
        }

        $payload = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'primary_email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'consent_accepted' => ['required', 'boolean'],
        ]);

        try {
            $outcome = $this->platformService->executeIdempotent(
                $tenantId,
                $idempotencyKey,
                'POST',
                '/api/v1/candidates',
                $payload,
                function () use ($tenantId, $actor, $payload): array {
                    $candidate = $this->candidateAppService->upsertCandidateWithConsent([
                        'first_name' => $payload['first_name'],
                        'last_name' => $payload['last_name'],
                        'email' => $payload['primary_email'],
                        'phone' => $payload['phone'] ?? null,
                        'source_channel' => 'API_V1',
                        'consent_given' => (bool) $payload['consent_accepted'],
                        'consent_version' => 'v2.0',
                    ], $tenantId, $actor instanceof User ? $actor : null);

                    return [
                        'status' => 201,
                        'body' => [
                            'id' => $candidate->id,
                            'uuid' => $candidate->uuid,
                            'primary_email' => $candidate->email,
                            'consent_accepted' => $candidate->consent_given,
                        ],
                    ];
                }
            );

            return $this->envelope($outcome['body'], $outcome['status'], ['idempotent_replay' => $outcome['replayed']]);
        } catch (RuntimeException $e) {
            return $this->errorEnvelope('IDEMPOTENCY_CONFLICT', $e->getMessage(), 409);
        }
    }

    public function listCandidateResumeParsesApi(Request $request, int $candidateId): JsonResponse
    {
        [$tenantId] = $this->resolveApiAuthContext($request, 'candidates.view');

        $candidate = Candidate::withoutGlobalScopes()->findOrFail($candidateId);
        if ((int) $candidate->tenant_id !== $tenantId) {
            return $this->errorEnvelope('TENANT_SCOPE_VIOLATION', 'Cross-tenant access to candidate resume parses is prohibited.', 403);
        }

        $items = ResumeParseJob::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('candidate_id', $candidate->id)
            ->latest('id')
            ->limit(25)
            ->get();

        return $this->envelope(['items' => $items]);
    }

    public function parseCandidateDocumentApi(Request $request, int $candidateId, int $documentId): JsonResponse
    {
        [$tenantId, $actor] = $this->resolveApiAuthContext($request, 'candidates.manage');

        $candidate = Candidate::withoutGlobalScopes()->findOrFail($candidateId);
        $doc = CandidateDocument::withoutGlobalScopes()->findOrFail($documentId);

        if ((int) $candidate->tenant_id !== $tenantId || (int) $doc->tenant_id !== $tenantId || (int) $doc->candidate_id !== (int) $candidate->id) {
            return $this->errorEnvelope('TENANT_SCOPE_VIOLATION', 'Cross-tenant or mismatched candidate document parsing is prohibited.', 403);
        }

        $forceReprocess = (bool) $request->boolean('force_reprocess', false);
        $parseJob = $this->resumeParsingService->parseDocument(
            candidate: $candidate,
            document: $doc,
            applicationId: $doc->application_id,
            actor: $actor instanceof User ? $actor : null,
            forceReprocess: $forceReprocess,
        );

        return $this->envelope([
            'parse_job_id' => $parseJob->id,
            'uuid' => $parseJob->uuid,
            'status' => $parseJob->status,
            'schema_version' => $parseJob->schema_version,
            'provider_key' => $parseJob->provider_key,
            'model_name' => $parseJob->model_name,
            'ocr_used' => $parseJob->ocr_used,
            'is_scanned_document' => $parseJob->is_scanned_document,
            'prompt_injection_detected' => $parseJob->prompt_injection_detected,
            'parsed_data' => $parseJob->getEffectiveResumeData(),
            'missing_fields' => $parseJob->missing_fields,
            'error_code' => $parseJob->error_code,
            'error_message' => $parseJob->error_message,
        ]);
    }

    public function reviewCandidateResumeParseApi(Request $request, int $candidateId, int $parseJobId): JsonResponse
    {
        [$tenantId, $actor] = $this->resolveApiAuthContext($request, 'candidates.manage');

        $candidate = Candidate::withoutGlobalScopes()->findOrFail($candidateId);
        $parseJob = ResumeParseJob::withoutGlobalScopes()->findOrFail($parseJobId);

        if ((int) $candidate->tenant_id !== $tenantId || (int) $parseJob->tenant_id !== $tenantId || (int) $parseJob->candidate_id !== (int) $candidate->id) {
            return $this->errorEnvelope('TENANT_SCOPE_VIOLATION', 'Cross-tenant access to candidate resume parse review is prohibited.', 403);
        }

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'location' => ['nullable', 'string', 'max:120'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'current_title' => ['nullable', 'string', 'max:150'],
            'total_years_experience' => ['nullable', 'integer', 'min:0', 'max:60'],
            'professional_summary' => ['nullable', 'string', 'max:3000'],
            'skills' => ['nullable', 'string', 'max:1000'],
        ]);

        $updatedJob = $this->resumeParsingService->applyCorrections(
            parseJob: $parseJob,
            candidate: $candidate,
            correctedPayload: $validated,
            reviewer: $actor instanceof User ? $actor : null,
            isCandidateReview: false,
        );

        return $this->envelope([
            'parse_job_id' => $updatedJob->id,
            'status' => $updatedJob->status,
            'effective_data' => $updatedJob->getEffectiveResumeData(),
        ]);
    }

    public function evaluateApplicationMatchApi(Request $request, int $applicationId): JsonResponse
    {
        [$tenantId, $actor] = $this->resolveApiAuthContext($request, 'candidates.manage');

        $application = Application::withoutGlobalScopes()->with(['candidate', 'jobPosting'])->findOrFail($applicationId);
        if ((int) $application->tenant_id !== $tenantId) {
            return $this->errorEnvelope('TENANT_SCOPE_VIOLATION', 'Cross-tenant access to application AI matching is prohibited.', 403);
        }

        $updated = $this->resumeParsingService->evaluateApplicationMatch(
            application: $application,
            candidate: $application->candidate,
            actor: $actor instanceof User ? $actor : null,
        );

        return $this->envelope([
            'application_id' => $updated->id,
            'application_number' => $updated->application_number,
            'ai_match_score' => $updated->ai_match_score,
            'ai_match_data' => $updated->ai_match_data,
            'ai_match_reviewed' => $updated->ai_match_reviewed,
        ]);
    }

    public function listApplications(Request $request): JsonResponse
    {
        [$tenantId] = $this->resolveApiAuthContext($request, 'candidates.view');

        $items = Application::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['candidate:id,first_name,last_name,email', 'jobPosting:id,title,slug'])
            ->latest('id')
            ->limit(25)
            ->get();

        return $this->envelope(['items' => $items]);
    }

    public function listHrHandovers(Request $request): JsonResponse
    {
        [$tenantId] = $this->resolveApiAuthContext($request, 'requisitions.view');

        $items = HrHandover::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with('externalEmployeeReference')
            ->latest('id')
            ->limit(25)
            ->get();

        return $this->envelope(['items' => $items]);
    }

    public function dispatchHrHandoverApi(Request $request, string $caseId): JsonResponse
    {
        [$tenantId, $actor] = $this->resolveApiAuthContext($request, 'hr_handover.dispatch');

        /** @var HrValidationCase $case */
        $case = HrValidationCase::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($caseId);

        $idempotencyKey = (string) ($request->header('Idempotency-Key') ?: $request->input('idempotency_key', ''));

        try {
            $handover = $this->handoverService->dispatchHandover($case, $actor, $idempotencyKey ?: null);

            return $this->envelope([
                'handover_id' => $handover->id,
                'status' => $handover->status,
                'external_employee_id' => $handover->external_employee_id,
                'idempotency_key' => $handover->idempotency_key,
            ]);
        } catch (HrHandoverGateNotSatisfiedException $e) {
            return $this->errorEnvelope($e->errorCode, $e->getMessage(), 409);
        }
    }

    public function listMcpTools(Request $request): JsonResponse
    {
        if (! app(PlatformConfigService::class)->getBool('api.global_mcp_enabled')) {
            return $this->errorEnvelope('MCP_GLOBALLY_DISABLED', 'Model Context Protocol endpoints are disabled by platform governance policy.', 503);
        }

        [$tenantId, $actor] = $this->resolveApiAuthContext($request, 'mcp.use');

        $tenant = Tenant::withoutGlobalScopes()->find($tenantId);
        if ($tenant !== null && ! $tenant->mcp_enabled) {
            return $this->errorEnvelope('MCP_DISABLED_FOR_TENANT', 'Model Context Protocol endpoints are disabled for this tenant.', 403);
        }

        $tools = [];
        foreach (ApiAndMcpPlatformService::ALLOWLISTED_MCP_TOOLS as $name => $meta) {
            if ($actor->hasPermission($meta['required_permission'], $tenantId) || $actor->hasRole('tenant_admin', $tenantId)) {
                $tools[] = array_merge(['name' => $name], $meta);
            }
        }

        return $this->envelope([
            'protocol' => 'mcp/v1',
            'tenant_id' => $tenantId,
            'principal_type' => $actor instanceof ApiClientPrincipal ? 'API_CLIENT' : 'USER',
            'tools' => $tools,
            'prohibited_tools_enforced' => ApiAndMcpPlatformService::PROHIBITED_MCP_TOOLS,
        ]);
    }

    public function invokeMcpTool(Request $request): JsonResponse
    {
        if (! app(PlatformConfigService::class)->getBool('api.global_mcp_enabled')) {
            return $this->errorEnvelope('MCP_GLOBALLY_DISABLED', 'Model Context Protocol endpoints are disabled by platform governance policy.', 503);
        }

        [$tenantId, $actor] = $this->resolveApiAuthContext($request, 'mcp.use');

        $tenant = Tenant::withoutGlobalScopes()->find($tenantId);
        if ($tenant !== null && ! $tenant->mcp_enabled) {
            return $this->errorEnvelope('MCP_DISABLED_FOR_TENANT', 'Model Context Protocol endpoints are disabled for this tenant.', 403);
        }

        $validated = $request->validate([
            'tool' => ['required', 'string', 'max:80'],
            'arguments' => ['nullable', 'array'],
        ]);

        try {
            $output = $this->platformService->invokeMcpTool(
                $validated['tool'],
                $validated['arguments'] ?? [],
                $actor,
                $tenantId,
            );

            return $this->envelope($output);
        } catch (InvalidArgumentException $e) {
            return $this->errorEnvelope('MCP_TOOL_PROHIBITED', $e->getMessage(), 422);
        } catch (AuthorizationException $e) {
            return $this->errorEnvelope('MCP_PERMISSION_DENIED', $e->getMessage(), 403);
        }
    }

    /**
     * Resolves caller identity and tenant strictly from Bearer API Client credentials or authenticated session.
     * Machine principals return an ApiClientPrincipal and NEVER inherit human User roles or fallback to any User record.
     *
     * @return array{0: int, 1: User|ApiClientPrincipal}
     */
    private function resolveApiAuthContext(Request $request, string $requiredScope): array
    {
        $bearer = $request->bearerToken();
        if (! empty($bearer) && str_contains($bearer, ':')) {
            [$clientId, $rawSecret] = explode(':', $bearer, 2);
            /** @var ApiClient|null $client */
            $client = ApiClient::withoutGlobalScopes()
                ->where('client_id', $clientId)
                ->first();

            if ($client === null || ! $client->is_active || ! hash_equals($client->client_secret_hash, hash('sha256', $rawSecret))) {
                $this->audit->record(
                    module: 'api_platform',
                    action: 'api_client.auth_failed',
                    outcome: 'DENIED',
                    errorCode: 'UNAUTHENTICATED_API_CLIENT',
                    metadata: ['client_id' => $clientId],
                    tenantId: $client ? (int) $client->tenant_id : null,
                    actorType: 'API_CLIENT',
                    actorIdentifier: $clientId,
                );

                abort(response()->json([
                    'error' => ['code' => 'UNAUTHENTICATED_API_CLIENT', 'message' => 'Invalid or inactive API client credentials.'],
                ], 401));
            }

            $principal = new ApiClientPrincipal($client);
            $tenantId = $principal->getTenantId();

            $tenant = Tenant::withoutGlobalScopes()->find($tenantId);
            if ($tenant === null || $tenant->status !== 'ACTIVE') {
                $this->audit->record(
                    module: 'api_platform',
                    action: 'api_client.tenant_suspended',
                    outcome: 'DENIED',
                    errorCode: 'TENANT_SUSPENDED',
                    metadata: ['client_id' => $clientId, 'tenant_status' => $tenant?->status],
                    tenantId: $tenantId,
                    actorType: 'API_CLIENT',
                    actorIdentifier: $clientId,
                );

                abort(response()->json([
                    'error' => ['code' => 'TENANT_SUSPENDED', 'message' => 'Tenant is suspended or inactive; API access is blocked.'],
                ], 403));
            }

            $this->tenantContext->setActiveTenantId($tenantId);

            if (! $principal->hasScope($requiredScope)) {
                $this->audit->record(
                    module: 'api_platform',
                    action: 'api_client.scope_denied',
                    outcome: 'DENIED',
                    errorCode: 'INSUFFICIENT_API_SCOPE',
                    entityType: 'api_client',
                    entityId: (string) $client->id,
                    metadata: ['required_scope' => $requiredScope, 'client_scopes' => $client->scopes],
                    tenantId: $tenantId,
                    actorType: 'API_CLIENT',
                    actorIdentifier: $principal->getClientId(),
                );

                abort(response()->json([
                    'error' => ['code' => 'INSUFFICIENT_API_SCOPE', 'message' => "API client lacks required scope '{$requiredScope}'."],
                ], 403));
            }

            $client->update(['last_used_at' => now()]);

            return [$tenantId, $principal];
        }

        $user = $request->user();
        if ($user === null) {
            abort(response()->json([
                'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication required.'],
            ], 401));
        }

        $tenantId = (int) ($this->tenantContext->getActiveTenantId() ?? $user->current_tenant_id);
        $tenant = Tenant::withoutGlobalScopes()->find($tenantId);
        if ($tenant === null || $tenant->status !== 'ACTIVE') {
            abort(response()->json([
                'error' => ['code' => 'TENANT_SUSPENDED', 'message' => 'Tenant is suspended or inactive; API access is blocked.'],
            ], 403));
        }

        if (! $user->hasPermission($requiredScope, $tenantId) && ! $user->hasRole('tenant_admin', $tenantId)) {
            abort(response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => "Caller lacks required permission '{$requiredScope}'."],
            ], 403));
        }

        return [$tenantId, $user];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $extraMeta
     */
    private function envelope(array $data, int $status = 200, array $extraMeta = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => array_merge([
                'correlation_id' => $this->tenantContext->getCorrelationId(),
                'tenant_id' => $this->tenantContext->getActiveTenantId(),
                'timestamp' => now()->toIso8601String(),
            ], $extraMeta),
            'errors' => [],
        ], $status);
    }

    private function errorEnvelope(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'data' => null,
            'meta' => [
                'correlation_id' => $this->tenantContext->getCorrelationId(),
                'timestamp' => now()->toIso8601String(),
            ],
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
