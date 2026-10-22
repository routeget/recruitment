<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeliverSignedWebhookJob;
use App\Models\ApiIdempotencyRecord;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\LegalEntity;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\ApiAndMcpPlatformService;
use App\Services\AuditService;
use App\Support\WebhookSsrfGuard;
use Database\Seeders\AlphaSafeBootstrapSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class RemediationSecurityAndIntegrityTest extends TestCase
{
    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AlphaSafeBootstrapSeeder::class);

        $this->tenantA = Tenant::query()->where('slug', 'acme-demo')->firstOrFail();
        $this->tenantB = Tenant::query()->where('slug', 'globex-demo')->firstOrFail();
        $this->adminA = User::query()->where('email', 'admin@acme-demo.test')->firstOrFail();
    }

    public function test_urap_rem_002_api_and_mcp_machine_principal_enforces_least_privilege_without_human_user_inheritance(): void
    {
        $platformService = app(ApiAndMcpPlatformService::class);

        // 1. Create an API client in Tenant A with ONLY 'mcp.use' scope (no candidates.view, no requisitions.view)
        // Even though Tenant A has a human tenant_admin ($this->adminA), the machine principal MUST NOT inherit their privileges!
        $mcpOnlyCreated = $platformService->createApiClient(
            name: 'MCP Transport-Only Client',
            scopes: ['mcp.use'],
            tenantId: (int) $this->tenantA->id,
            actor: $this->adminA,
        );
        $mcpOnlyBearer = $mcpOnlyCreated['client']->client_id.':'.$mcpOnlyCreated['raw_secret'];

        // Tool listing should return 0 authorized tools for a client with only 'mcp.use'
        $this->withHeaders(['Authorization' => 'Bearer '.$mcpOnlyBearer])
            ->getJson('/mcp/v1/tools')
            ->assertStatus(200)
            ->assertJsonPath('data.principal_type', 'API_CLIENT')
            ->assertJsonCount(0, 'data.tools');

        // Invoking candidate.search with only 'mcp.use' MUST be denied with 403 MCP_PERMISSION_DENIED
        $this->withHeaders(['Authorization' => 'Bearer '.$mcpOnlyBearer])
            ->postJson('/mcp/v1/invoke', [
                'tool' => 'candidate.search',
                'arguments' => ['query' => ''],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'MCP_PERMISSION_DENIED');

        // Invoking application.get with only 'mcp.use' MUST be denied with 403 MCP_PERMISSION_DENIED
        $appA = Application::withoutGlobalScopes()->where('tenant_id', $this->tenantA->id)->firstOrFail();
        $this->withHeaders(['Authorization' => 'Bearer '.$mcpOnlyBearer])
            ->postJson('/mcp/v1/invoke', [
                'tool' => 'application.get',
                'arguments' => ['application_id' => $appA->id],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'MCP_PERMISSION_DENIED');

        // Verify audit record attributes the denial to API_CLIENT with actor_user_id = null
        $deniedAudit = AuditEvent::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('action', 'mcp.tool_forbidden')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('API_CLIENT', $deniedAudit->actor_type);
        $this->assertNull($deniedAudit->actor_user_id);
        $this->assertSame($mcpOnlyCreated['client']->client_id, $deniedAudit->actor_identifier);

        // 2. Create a least-privilege API client with ['mcp.use', 'candidates.view']
        $candidateReaderCreated = $platformService->createApiClient(
            name: 'MCP Candidate Reader',
            scopes: ['mcp.use', 'candidates.view'],
            tenantId: (int) $this->tenantA->id,
            actor: $this->adminA,
        );
        $candidateReaderBearer = $candidateReaderCreated['client']->client_id.':'.$candidateReaderCreated['raw_secret'];

        $this->withHeaders(['Authorization' => 'Bearer '.$candidateReaderBearer])
            ->postJson('/mcp/v1/invoke', [
                'tool' => 'candidate.search',
                'arguments' => ['query' => ''],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.tool', 'candidate.search')
            ->assertJsonPath('data.tenant_id', $this->tenantA->id);

        $this->withHeaders(['Authorization' => 'Bearer '.$candidateReaderBearer])
            ->postJson('/mcp/v1/invoke', [
                'tool' => 'application.get',
                'arguments' => ['application_id' => $appA->id],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.result.application.id', $appA->id);

        // Still denied on analytics.summary because 'reports.view' scope was not granted
        $this->withHeaders(['Authorization' => 'Bearer '.$candidateReaderBearer])
            ->postJson('/mcp/v1/invoke', [
                'tool' => 'analytics.summary',
                'arguments' => [],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'MCP_PERMISSION_DENIED');

        // 3. Disabled API client is rejected with 401 UNAUTHENTICATED_API_CLIENT
        $candidateReaderCreated['client']->update(['is_active' => false]);
        $this->withHeaders(['Authorization' => 'Bearer '.$candidateReaderBearer])
            ->getJson('/mcp/v1/tools')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED_API_CLIENT');

        // 4. Tenant with ZERO human users: machine API client works without falling back to User::firstOrFail()
        $headlessTenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'headless-api-tenant',
            'name' => 'Headless Machine-Only Tenant',
            'status' => 'ACTIVE',
        ]);
        $le = LegalEntity::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $headlessTenant->id,
            'code' => 'HDLS-US',
            'name' => 'Headless US Inc.',
            'country_code' => 'US',
            'currency_code' => 'USD',
        ]);
        Department::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $headlessTenant->id,
            'legal_entity_id' => $le->id,
            'code' => 'ENG',
            'name' => 'Engineering',
        ]);
        Location::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $headlessTenant->id,
            'code' => 'NYC',
            'name' => 'New York',
            'city' => 'New York',
            'country_code' => 'US',
        ]);

        $this->assertSame(0, User::query()->where('current_tenant_id', $headlessTenant->id)->count());

        $headlessClientCreated = $platformService->createApiClient(
            name: 'Headless Tenant Requisition Bot',
            scopes: ['requisitions.create', 'requisitions.view'],
            tenantId: (int) $headlessTenant->id,
            actor: $this->adminA,
        );
        $headlessBearer = $headlessClientCreated['client']->client_id.':'.$headlessClientCreated['raw_secret'];

        $reqResp = $this->withHeaders([
            'Authorization' => 'Bearer '.$headlessBearer,
            'Idempotency-Key' => 'headless-idem-001',
        ])->postJson('/api/v1/requisitions', [
            'title' => 'Principal Security Engineer',
            'headcount' => 1,
        ]);

        $reqResp->assertStatus(201)
            ->assertJsonPath('meta.tenant_id', $headlessTenant->id);

        $reqAudit = AuditEvent::withoutGlobalScopes()
            ->where('tenant_id', $headlessTenant->id)
            ->where('action', 'requisition_created')
            ->firstOrFail();
        $this->assertSame('API_CLIENT', $reqAudit->actor_type);
        $this->assertNull($reqAudit->actor_user_id);
        $this->assertSame($headlessClientCreated['client']->client_id, $reqAudit->actor_identifier);
    }

    public function test_urap_rem_003_atomic_idempotency_handles_race_conflicts_and_failed_callback_rollback(): void
    {
        $platformService = app(ApiAndMcpPlatformService::class);

        $executions = 0;
        $first = $platformService->executeIdempotent(
            (int) $this->tenantA->id,
            'atomic-key-001',
            'POST',
            '/api/v1/requisitions',
            ['b' => 2, 'a' => 1],
            function () use (&$executions): array {
                $executions++;

                return ['status' => 201, 'body' => ['created_id' => 777]];
            }
        );

        $this->assertFalse($first['replayed']);
        $this->assertSame(1, $executions);

        // Identical payload with different key ordering -> canonicalized hash matches, replays without executing callback
        $replay = $platformService->executeIdempotent(
            (int) $this->tenantA->id,
            'atomic-key-001',
            'POST',
            '/api/v1/requisitions',
            ['a' => 1, 'b' => 2],
            function () use (&$executions): array {
                $executions++;

                return ['status' => 201, 'body' => ['created_id' => 888]];
            }
        );

        $this->assertTrue($replay['replayed']);
        $this->assertSame(777, $replay['body']['created_id']);
        $this->assertSame(1, $executions);

        // Simulate an in-flight concurrent request ('IN_PROGRESS' reservation) -> deterministic 409 conflict without running callback
        $inFlightRecord = ApiIdempotencyRecord::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'idempotency_key' => 'in-flight-key-002',
            'method' => 'POST',
            'path' => '/api/v1/requisitions',
            'request_hash' => hash('sha256', 'POST|/api/v1/requisitions|{"a":1}'),
            'status' => 'IN_PROGRESS',
            'response_status' => 0,
            'response_body' => [],
            'expires_at' => now()->addHours(24),
        ]);

        try {
            $platformService->executeIdempotent(
                (int) $this->tenantA->id,
                'in-flight-key-002',
                'POST',
                '/api/v1/requisitions',
                ['a' => 1],
                function () use (&$executions): array {
                    $executions++;

                    return ['status' => 201, 'body' => []];
                }
            );
            $this->fail('Expected RuntimeException for concurrent IN_PROGRESS idempotency record.');
        } catch (RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertStringContainsString('IDEMPOTENCY_CONFLICT', $e->getMessage());
        }
        $this->assertSame(1, $executions);
        $inFlightRecord->delete();

        // Failed callback rolls back the reservation so subsequent retry succeeds cleanly
        try {
            $platformService->executeIdempotent(
                (int) $this->tenantA->id,
                'rollback-key-003',
                'POST',
                '/api/v1/requisitions',
                ['title' => 'Transient Failure Role'],
                function (): array {
                    throw new RuntimeException('Simulated downstream failure inside callback');
                }
            );
            $this->fail('Expected exception from callback.');
        } catch (RuntimeException) {
            // Expected
        }

        $this->assertFalse(
            ApiIdempotencyRecord::withoutGlobalScopes()
                ->where('tenant_id', $this->tenantA->id)
                ->where('idempotency_key', 'rollback-key-003')
                ->exists()
        );
    }

    public function test_urap_rem_004_webhook_delivery_lifecycle_ssrf_defenses_and_encrypted_secret_at_rest(): void
    {
        $platformService = app(ApiAndMcpPlatformService::class);
        $ssrfGuard = app(WebhookSsrfGuard::class);

        // 1. SSRF Guard blocks non-HTTPS, loopback, RFC1918 private, link-local metadata, IPv6 loopback, and DNS rebinding
        $prohibitedUrls = [
            'http://hooks.example.org/plain-http',
            'https://localhost/webhook',
            'https://sub.localhost/webhook',
            'https://internal.local/webhook',
            'https://127.0.0.1/webhook',
            'https://10.0.0.15/webhook',
            'https://172.16.5.4/webhook',
            'https://192.168.1.20/webhook',
            'https://169.254.169.254/latest/meta-data/',
            'https://[::1]/webhook',
            'https://metadata.google.internal/computeMetadata/v1/',
        ];

        foreach ($prohibitedUrls as $unsafeUrl) {
            try {
                $platformService->createWebhookSubscription(
                    name: 'Unsafe Hook',
                    targetUrl: $unsafeUrl,
                    subscribedEvents: ['offer.accepted'],
                    tenantId: (int) $this->tenantA->id,
                    actor: $this->adminA,
                );
                $this->fail("Expected SSRF guard to block unsafe URL: {$unsafeUrl}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('WEBHOOK_SSRF_BLOCKED', $e->getMessage());
            }
        }

        // Test DNS rebinding where an external hostname resolves to 127.0.0.1
        $ssrfGuard->setDnsResolver(static fn (string $host): array => $host === 'rebind.example.org' ? ['127.0.0.1'] : ['93.184.216.34']);
        try {
            $ssrfGuard->assertSafeTargetUrl('https://rebind.example.org/hook');
            $this->fail('Expected DNS rebinding to 127.0.0.1 to be blocked.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('WEBHOOK_SSRF_BLOCKED', $e->getMessage());
        } finally {
            $ssrfGuard->setDnsResolver(static fn (): array => ['93.184.216.34']);
        }

        // 2. Encrypted signing_secret at rest
        $sub = $platformService->createWebhookSubscription(
            name: 'Verified External Receiver',
            targetUrl: 'https://receiver.example.org/webhooks',
            subscribedEvents: ['offer.accepted'],
            tenantId: (int) $this->tenantA->id,
            actor: $this->adminA,
        );

        $rawDbSecret = (string) DB::table('webhook_subscriptions')->where('id', $sub->id)->value('signing_secret');
        $this->assertStringStartsWith('whsec_', (string) $sub->signing_secret);
        $this->assertStringNotContainsString('whsec_', $rawDbSecret, 'Signing secret must be encrypted at rest in DB.');
        $this->assertArrayNotHasKey('signing_secret', $sub->toArray(), 'Signing secret must be hidden in array/JSON serialization.');

        // 3. Queue::fake() proves dispatchSignedWebhook starts in PENDING state and NEVER fabricates DELIVERED
        Queue::fake();
        $pendingDelivery = $platformService->dispatchSignedWebhook($sub, 'offer.accepted', ['offer_id' => 101], $this->adminA);
        $this->assertSame('PENDING', $pendingDelivery->status);
        $this->assertNull($pendingDelivery->response_status);
        $this->assertNull($pendingDelivery->delivered_at);
        $this->assertSame(0, $pendingDelivery->attempt_count);
        Queue::assertPushed(DeliverSignedWebhookJob::class, fn (DeliverSignedWebhookJob $job) => $job->webhookDeliveryId === $pendingDelivery->id);

        // 4. Process DeliverSignedWebhookJob against 200 OK receiver -> transitions to DELIVERED and verifies HMAC signature
        $capturedHeaders = [];
        $simulatedStatus = 200;
        $simulatedBody = ['ok' => true];
        $simulatedHeaders = [];

        Http::fake(function ($request) use (&$capturedHeaders, &$simulatedStatus, &$simulatedBody, &$simulatedHeaders) {
            $capturedHeaders = $request->headers();

            return Http::response($simulatedBody, $simulatedStatus, $simulatedHeaders);
        });

        (new DeliverSignedWebhookJob((int) $pendingDelivery->id))->handle($ssrfGuard, app(AuditService::class));

        $pendingDelivery->refresh();
        $this->assertSame('DELIVERED', $pendingDelivery->status);
        $this->assertSame(200, $pendingDelivery->response_status);
        $this->assertSame(1, $pendingDelivery->attempt_count);
        $this->assertNotNull($pendingDelivery->delivered_at);
        $this->assertNotEmpty($capturedHeaders['X-URAP-Signature-256'][0] ?? '');

        // 5. Non-2xx (503) response -> transitions to FAILED_RETRYABLE
        $failedDelivery = WebhookDelivery::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'webhook_subscription_id' => $sub->id,
            'event_id' => 'evt_fail_503',
            'event_type' => 'offer.accepted',
            'payload' => ['event_id' => 'evt_fail_503'],
            'signature_header' => 't=123,v1=abc',
            'status' => 'PENDING',
            'attempt_count' => 0,
        ]);

        $simulatedStatus = 503;
        $simulatedBody = ['error' => 'upstream overloaded'];
        $simulatedHeaders = [];

        (new DeliverSignedWebhookJob((int) $failedDelivery->id))->handle($ssrfGuard, app(AuditService::class));
        $failedDelivery->refresh();
        $this->assertSame('FAILED_RETRYABLE', $failedDelivery->status);
        $this->assertSame(503, $failedDelivery->response_status);
        $this->assertSame('WEBHOOK_HTTP_ERROR', $failedDelivery->error_code);

        // 6. 302 Redirect response -> blocked and marked FAILED_PERMANENT with WEBHOOK_REDIRECT_PROHIBITED
        $redirectDelivery = WebhookDelivery::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'webhook_subscription_id' => $sub->id,
            'event_id' => 'evt_redir_302',
            'event_type' => 'offer.accepted',
            'payload' => ['event_id' => 'evt_redir_302'],
            'signature_header' => 't=123,v1=abc',
            'status' => 'PENDING',
            'attempt_count' => 0,
        ]);

        $simulatedStatus = 302;
        $simulatedBody = '';
        $simulatedHeaders = ['Location' => 'http://169.254.169.254/latest/meta-data/'];

        (new DeliverSignedWebhookJob((int) $redirectDelivery->id))->handle($ssrfGuard, app(AuditService::class));
        $redirectDelivery->refresh();
        $this->assertSame('FAILED_PERMANENT', $redirectDelivery->status);
        $this->assertSame(302, $redirectDelivery->response_status);
        $this->assertSame('WEBHOOK_REDIRECT_PROHIBITED', $redirectDelivery->error_code);

        $ssrfGuard->setDnsResolver(null);
    }

    public function test_urap_rem_005_and_007_setup_status_and_readiness_health_probes_report_dependency_degradation(): void
    {
        // /setup/status returns 403 PUBLIC_SETUP_LOCKED
        $this->getJson('/setup/status')
            ->assertStatus(403)
            ->assertJsonPath('status', 'INITIALIZED_LOCKED')
            ->assertJsonPath('error_code', 'PUBLIC_SETUP_LOCKED')
            ->assertJsonPath('public_setup_enabled', false);

        // /health and /health/ready return 200 OK when dependencies are operational
        $this->getJson('/health')
            ->assertStatus(200)
            ->assertJsonPath('status', 'OK')
            ->assertJsonPath('checks.cache_redis', 'OPERATIONAL');

        $this->getJson('/health/ready')
            ->assertStatus(200)
            ->assertJsonPath('status', 'OK')
            ->assertJsonPath('probe_type', 'readiness');

        // Simulate Cache/Redis failure -> /health and /health/ready MUST return 503 DEGRADED
        Cache::shouldReceive('put')->once()->andThrow(new RuntimeException('Simulated Redis connection failure'));

        $this->getJson('/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'DEGRADED')
            ->assertJsonPath('checks.cache_redis', 'DEGRADED');
    }
}
