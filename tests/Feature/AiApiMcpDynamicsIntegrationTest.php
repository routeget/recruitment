<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiProviderConfig;
use App\Models\AiTaskRun;
use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\AiGatewayService;
use App\Services\ApiAndMcpPlatformService;
use Database\Seeders\AlphaSafeBootstrapSeeder;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class AiApiMcpDynamicsIntegrationTest extends TestCase
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

    public function test_provider_neutral_ai_gateway_governance_redaction_kill_switch_budget_and_prohibited_tasks(): void
    {
        $aiService = app(AiGatewayService::class);

        // 1. Execute structured task across Claude, Gemini, OpenAI, and DeterministicFake adapters with PII redaction
        foreach (['claude', 'gemini', 'openai', 'deterministic_fake'] as $providerKey) {
            $run = $aiService->executeTask(
                taskType: 'candidate_screening',
                providerKey: $providerKey,
                rawInputText: 'Candidate contact: secret.person@example.com, SSN 123-45-6789, token sk-1234567890abcdef. Strong Laravel & MariaDB architecture.',
                actor: $this->adminA,
                tenantId: (int) $this->tenantA->id,
            );

            $this->assertTrue($run->schema_valid);
            $this->assertFalse($run->human_reviewed);
            $this->assertStringContainsString('[REDACTED_EMAIL]', $run->redacted_input_summary);
            $this->assertStringContainsString('[REDACTED_SSN]', $run->redacted_input_summary);
            $this->assertStringContainsString('[REDACTED_SECRET]', $run->redacted_input_summary);
            $this->assertStringNotContainsString('secret.person@example.com', $run->redacted_input_summary);
        }

        // 2. Human review sign-off
        $latestRun = AiTaskRun::withoutGlobalScopes()->where('tenant_id', $this->tenantA->id)->latest('id')->firstOrFail();
        $reviewed = $aiService->reviewTaskRun($latestRun, $this->adminA);
        $this->assertTrue($reviewed->human_reviewed);
        $this->assertSame($this->adminA->id, $reviewed->reviewed_by_user_id);

        // 3. Prohibited autonomous task is blocked with AI_PROHIBITED_USE
        try {
            $aiService->executeTask(
                taskType: 'hr_handover_approval',
                providerKey: 'claude',
                rawInputText: 'Attempt autonomous HR handover approval',
                actor: $this->adminA,
                tenantId: (int) $this->tenantA->id,
            );
            $this->fail('Expected InvalidArgumentException for prohibited AI task.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('AI_PROHIBITED_USE', $e->getMessage());
        }

        // 4. Kill switch blocks provider invocation with 409 AI_KILL_SWITCH_ENGAGED
        $claudeConfig = AiProviderConfig::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('provider_key', 'claude')
            ->firstOrFail();

        $aiService->updateProviderConfig($claudeConfig, [
            'kill_switch_engaged' => true,
            'is_enabled' => true,
            'monthly_budget_minor' => 50000,
        ], $this->adminA);

        try {
            $aiService->executeTask(
                taskType: 'job_description_draft',
                providerKey: 'claude',
                rawInputText: 'Draft a Senior Backend Engineer role.',
                actor: $this->adminA,
                tenantId: (int) $this->tenantA->id,
            );
            $this->fail('Expected RuntimeException for kill switch.');
        } catch (RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertStringContainsString('AI_KILL_SWITCH_ENGAGED', $e->getMessage());
        }

        // 5. Budget cap blocks provider invocation with 429 AI_BUDGET_EXCEEDED
        $aiService->updateProviderConfig($claudeConfig, [
            'kill_switch_engaged' => false,
            'is_enabled' => true,
            'monthly_budget_minor' => 10, // Less than current spend
        ], $this->adminA);

        try {
            $aiService->executeTask(
                taskType: 'job_description_draft',
                providerKey: 'claude',
                rawInputText: 'Draft a Senior Backend Engineer role.',
                actor: $this->adminA,
                tenantId: (int) $this->tenantA->id,
            );
            $this->fail('Expected RuntimeException for budget exceeded.');
        } catch (RuntimeException $e) {
            $this->assertSame(429, $e->getCode());
            $this->assertStringContainsString('AI_BUDGET_EXCEEDED', $e->getMessage());
        }
    }

    public function test_rest_api_v1_idempotency_conflict_webhooks_mcp_allowlist_and_dynamics_connector(): void
    {
        $platformService = app(ApiAndMcpPlatformService::class);

        // 1. Create scoped API client for Tenant A
        $created = $platformService->createApiClient(
            name: 'Tenant A ERP Client',
            scopes: ['requisitions.view', 'requisitions.create', 'candidates.view', 'candidates.manage', 'mcp.use'],
            tenantId: (int) $this->tenantA->id,
            actor: $this->adminA,
        );
        $bearerToken = $created['client']->client_id.':'.$created['raw_secret'];

        // 2. Call POST /api/v1/requisitions with Idempotency-Key
        $firstResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$bearerToken,
            'Idempotency-Key' => 'idem-req-001',
        ])->postJson('/api/v1/requisitions', [
            'title' => 'Staff Distributed Systems Engineer',
            'headcount' => 2,
            'employment_type' => 'FULL_TIME',
        ]);

        $firstResponse->assertStatus(201)
            ->assertJsonPath('meta.idempotent_replay', false);

        $createdReqId = $firstResponse->json('data.id');

        // 3. Replay identical request with same Idempotency-Key -> returns cached result without creating duplicate
        $replayResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$bearerToken,
            'Idempotency-Key' => 'idem-req-001',
        ])->postJson('/api/v1/requisitions', [
            'title' => 'Staff Distributed Systems Engineer',
            'headcount' => 2,
            'employment_type' => 'FULL_TIME',
        ]);

        $replayResponse->assertStatus(201)
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('data.id', $createdReqId);

        // 4. Replay same Idempotency-Key with DIFFERENT payload -> fails with 409 IDEMPOTENCY_CONFLICT
        $conflictResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$bearerToken,
            'Idempotency-Key' => 'idem-req-001',
        ])->postJson('/api/v1/requisitions', [
            'title' => 'Modified Payload Title',
            'headcount' => 5,
            'employment_type' => 'FULL_TIME',
        ]);

        $conflictResponse->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

        // 5. Outbound signed webhook subscription & HMAC-SHA256 delivery
        $sub = $platformService->createWebhookSubscription(
            name: 'Compliance SIEM Hook',
            targetUrl: 'https://siem.example.org/webhooks/urap',
            subscribedEvents: ['offer.accepted', 'hr.handover.succeeded'],
            tenantId: (int) $this->tenantA->id,
            actor: $this->adminA,
        );
        $delivery = $platformService->dispatchSignedWebhook($sub, 'hr.handover.succeeded', ['worker' => 'WRK-1001'], $this->adminA);
        $this->assertStringStartsWith('t=', $delivery->signature_header);
        $this->assertStringContainsString(',v1=', $delivery->signature_header);
        $this->assertSame(1, WebhookDelivery::withoutGlobalScopes()->where('id', $delivery->id)->count());

        // 6. MCP Server (/mcp/v1/tools and /mcp/v1/invoke)
        $this->withHeaders(['Authorization' => 'Bearer '.$bearerToken])
            ->getJson('/mcp/v1/tools')
            ->assertStatus(200)
            ->assertJsonPath('data.protocol', 'mcp/v1');

        $this->withHeaders(['Authorization' => 'Bearer '.$bearerToken])
            ->postJson('/mcp/v1/invoke', [
                'tool' => 'requisition.list',
                'arguments' => [],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.tool', 'requisition.list')
            ->assertJsonPath('data.tenant_id', $this->tenantA->id);

        // Prohibited MCP tools (sql.raw, hr.create_employee_unapproved) are hard-blocked with 422 MCP_TOOL_PROHIBITED
        $this->withHeaders(['Authorization' => 'Bearer '.$bearerToken])
            ->postJson('/mcp/v1/invoke', [
                'tool' => 'sql.raw',
                'arguments' => ['query' => 'SELECT * FROM users'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'MCP_TOOL_PROHIBITED');

        $this->withHeaders(['Authorization' => 'Bearer '.$bearerToken])
            ->postJson('/mcp/v1/invoke', [
                'tool' => 'hr.create_employee_unapproved',
                'arguments' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'MCP_TOOL_PROHIBITED');

        // 7. Verify UI consoles render 200 OK
        $this->actingAs($this->adminA)
            ->withSession(['active_tenant_id' => $this->tenantA->id])
            ->get('/ai')
            ->assertStatus(200)
            ->assertSee('Provider-Neutral AI Gateway');

        $this->actingAs($this->adminA)
            ->withSession(['active_tenant_id' => $this->tenantA->id])
            ->get('/integrations/api')
            ->assertStatus(200)
            ->assertSee('Allowlisted MCP Server Tools');

        $this->actingAs($this->adminA)
            ->withSession(['active_tenant_id' => $this->tenantA->id])
            ->get('/integrations/dynamics')
            ->assertStatus(200)
            ->assertSee('Canonical Field-Ownership Matrix');

        $this->assertTrue(
            AuditEvent::withoutGlobalScopes()
                ->where('tenant_id', $this->tenantA->id)
                ->where('action', 'mcp.tool_denied')
                ->exists()
        );
    }
}
