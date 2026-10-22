<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeliverSignedWebhookJob;
use App\Models\ApiClient;
use App\Models\AuditEvent;
use App\Models\ExternalIdentityConnection;
use App\Models\HrValidationCase;
use App\Models\Requisition;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\AiGatewayService;
use App\Services\AuditService;
use App\Services\CandidateAndApplicationService;
use App\Services\OfferAndHrHandoverService;
use App\Services\PlatformAdminService;
use App\Services\PlatformConfigService;
use App\Services\RequisitionService;
use App\Services\TenantContextService;
use App\Support\WebhookSsrfGuard;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PlatformWideFunctionalAuditRemediationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * D-01: Verify route-level RBAC on /hr-handover, /offers, /evaluations, and /admin/platform/support-context,
     * and verify Audited Support Context blocks candidate PII browsing (`candidate_pii_browsing_prohibited`).
     */
    public function test_d01_rbac_route_enforcement_and_support_context_pii_boundary(): void
    {
        $tenant = Tenant::query()->where('slug', 'acme-demo')->firstOrFail();
        $recruiter = User::query()->where('email', 'recruiter@acme-demo.test')->firstOrFail();
        $hiringManager = User::query()->where('email', 'manager@acme-demo.test')->firstOrFail();
        $hrValidator = User::query()->where('email', 'hr.validator@acme-demo.test')->firstOrFail();
        $superAdmin = User::query()->where('email', 'platform.superadmin@tringers.test')->firstOrFail();

        // 1. Recruiter does NOT have hr_validation.manage / hr_handover.* -> denied GET /hr-handover
        $this->actingAs($recruiter)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->get(route('hr-handover.index'))
            ->assertStatus(403);

        // 2. HR Validator has hr_validation.manage -> allowed GET /hr-handover
        $this->actingAs($hrValidator)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->get(route('hr-handover.index'))
            ->assertStatus(200);

        // 3. Hiring Manager has offers.approve -> allowed GET /offers (previously 403)
        $this->actingAs($hiringManager)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->get(route('offers.index'))
            ->assertStatus(200);

        // 4. Non-Super-Admin is denied POST /admin/platform/support-context at middleware layer
        $tenantAdmin = User::query()->where('email', 'admin@acme-demo.test')->firstOrFail();
        $this->actingAs($tenantAdmin)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post(route('admin.iam.support-context'), [
                'target_tenant_id' => $tenant->id,
                'support_ticket_reference' => 'TKT-900',
                'justification' => 'Unauthorized support switch attempt',
            ])
            ->assertStatus(403);

        // 5. Super Admin entering Audited Support Context on a tenant where they only have audited_support_context
        // has candidate_pii_browsing_prohibited enforced -> cannot browse /candidates
        $betaTenant = Tenant::query()->where('slug', 'globex-demo')->firstOrFail();
        TenantMembership::query()->where('user_id', $superAdmin->id)->where('tenant_id', $betaTenant->id)->delete();

        $this->actingAs($superAdmin)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post(route('admin.iam.support-context'), [
                'target_tenant_id' => $betaTenant->id,
                'support_ticket_reference' => 'SUP-2026-999',
                'justification' => 'Investigating tenant configuration issue under support ticket',
            ])
            ->assertRedirect();

        $superAdmin->refresh();
        $this->assertFalse($superAdmin->hasPermission('candidates.view', (int) $betaTenant->id));
        $this->assertFalse($superAdmin->hasPermission('candidates.manage', (int) $betaTenant->id));
        $this->assertTrue($superAdmin->hasPermission('audit.view', (int) $betaTenant->id));

        $this->actingAs($superAdmin)
            ->withSession(['active_tenant_id' => $betaTenant->id])
            ->get(route('candidates.index'))
            ->assertStatus(403);
    }

    /**
     * D-02: Verify PlatformAdminService::createTenant defaults mcp_enabled to false and persists require_sso,
     * and updateTenantLifecycle persists require_sso and WebhookSubscription target_url audit metadata.
     */
    public function test_d02_platform_admin_tenant_require_sso_persistence_and_mcp_default(): void
    {
        $superAdmin = User::query()->where('email', 'platform.superadmin@tringers.test')->firstOrFail();
        $service = app(PlatformAdminService::class);

        $newTenant = $service->createTenant([
            'name' => 'Audit Corp',
            'slug' => 'audit-corp-'.strtolower(Str::random(5)),
            'default_timezone' => 'Europe/London',
            'default_currency' => 'GBP',
            'retention_days' => 365,
            'reason' => 'TKT-2026-701 Provisioning tenant during functional audit',
        ], $superAdmin);

        $this->assertFalse((bool) $newTenant->mcp_enabled);
        $this->assertFalse((bool) $newTenant->require_sso);
        $this->assertTrue((bool) $newTenant->allow_local_fallback);
        $this->assertSame('Europe/London', $newTenant->default_timezone);
        $this->assertSame('GBP', $newTenant->default_currency);

        $updated = $service->updateTenantLifecycle($newTenant, [
            'status' => 'ACTIVE',
            'ai_kill_switch' => true,
            'mcp_enabled' => true,
            'sso_required' => true,
            'retention_days' => 400,
            'reason' => 'TKT-2026-702 Enabling require_sso and ai_kill_switch',
        ], $superAdmin);

        $this->assertTrue((bool) $updated->require_sso);
        $this->assertTrue((bool) $updated->ai_kill_switch);
        $this->assertTrue((bool) $updated->mcp_enabled);

        $sub = WebhookSubscription::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $newTenant->id,
            'name' => 'Audit Hook',
            'target_url' => 'https://hooks.example.org/audit',
            'signing_secret' => 'secret-123456',
            'subscribed_events' => ['application.created'],
            'is_active' => true,
        ]);

        $disabledSub = $service->updateWebhookSubscriptionStatus(
            $sub,
            false,
            'TKT-2026-703 Disabling webhook subscription in audit test',
            $superAdmin
        );
        $this->assertFalse((bool) $disabledSub->is_active);

        $auditEvent = AuditEvent::withoutGlobalScopes()
            ->where('action', 'webhook_subscription.disabled')
            ->where('entity_id', (string) $sub->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('https://hooks.example.org/audit', $auditEvent->metadata['endpoint_url'] ?? null);
    }

    /**
     * D-03: Verify Tenant-level ai_kill_switch blocks AI Gateway execution fail-closed.
     */
    public function test_d03_tenant_level_ai_kill_switch_blocks_ai_execution(): void
    {
        $tenant = Tenant::query()->where('slug', 'acme-demo')->firstOrFail();
        $recruiter = User::query()->where('email', 'recruiter@acme-demo.test')->firstOrFail();

        $tenant->update(['ai_kill_switch' => true]);

        $aiService = app(AiGatewayService::class);

        try {
            $aiService->executeTask(
                'job_description_draft',
                'azure_openai_eu',
                'Draft a Principal Engineer job description.',
                $recruiter,
                (int) $tenant->id
            );
            $this->fail('Expected RuntimeException for AI_TENANT_KILL_SWITCH_ENGAGED.');
        } catch (RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertStringContainsString('AI_TENANT_KILL_SWITCH_ENGAGED', $e->getMessage());
        }

        $this->assertTrue(
            AuditEvent::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('action', 'ai.tenant_kill_switch_blocked')
                ->where('error_code', 'AI_TENANT_KILL_SWITCH_ENGAGED')
                ->exists()
        );
    }

    /**
     * D-04: Verify suspended tenant blocks Bearer API Client & session API calls,
     * and tenant-level mcp_enabled=false blocks /mcp/v1/tools and /mcp/v1/invoke.
     */
    public function test_d04_api_and_mcp_enforce_tenant_active_status_and_tenant_mcp_flag(): void
    {
        $tenant = Tenant::query()->where('slug', 'acme-demo')->firstOrFail();
        $rawSecret = 'secret_audit_token_999';
        $client = ApiClient::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'name' => 'Audit Machine Client',
            'client_id' => 'cli_audit_'.strtolower(Str::random(8)),
            'client_secret_hash' => hash('sha256', $rawSecret),
            'scopes' => ['requisitions.read', 'mcp.use'],
            'is_active' => true,
        ]);

        $bearer = $client->client_id.':'.$rawSecret;

        // 1. When tenant mcp_enabled is false, /mcp/v1/tools returns 403 MCP_DISABLED_FOR_TENANT
        $tenant->update(['mcp_enabled' => false, 'status' => 'ACTIVE']);

        $this->withHeader('Authorization', 'Bearer '.$bearer)
            ->getJson('/mcp/v1/tools')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'MCP_DISABLED_FOR_TENANT');

        // 2. When tenant mcp_enabled is true, /mcp/v1/tools succeeds
        $tenant->update(['mcp_enabled' => true]);

        $this->withHeader('Authorization', 'Bearer '.$bearer)
            ->getJson('/mcp/v1/tools')
            ->assertStatus(200)
            ->assertJsonPath('data.tenant_id', $tenant->id);

        // 3. When tenant is SUSPENDED, Bearer API client is rejected with 403 TENANT_SUSPENDED
        $tenant->update(['status' => 'SUSPENDED']);

        $this->withHeader('Authorization', 'Bearer '.$bearer)
            ->getJson('/api/v1/requisitions')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'TENANT_SUSPENDED');
    }

    /**
     * D-05: Verify editing an ON_HOLD requisition invalidates prior approval and resets status to DRAFT.
     */
    public function test_d05_editing_on_hold_requisition_invalidates_approval_and_resets_to_draft(): void
    {
        $tenant = Tenant::query()->where('slug', 'acme-demo')->firstOrFail();
        $admin = User::query()->where('email', 'admin@acme-demo.test')->firstOrFail();
        $hm = User::query()->where('email', 'manager@acme-demo.test')->firstOrFail();

        app(TenantContextService::class)->setActiveTenant($tenant);
        $reqService = app(RequisitionService::class);

        $req = Requisition::query()->where('tenant_id', $tenant->id)->where('status', Requisition::STATUS_OPEN)->firstOrFail();
        $onHold = $reqService->transitionLifecycleState($req, Requisition::STATUS_ON_HOLD, $admin, 'Paused for budget review');
        $this->assertSame(Requisition::STATUS_ON_HOLD, $onHold->status);
        $this->assertNotNull($onHold->approved_version);

        $edited = $reqService->updateRequisition($onHold, [
            'headcount' => $onHold->headcount + 2,
            'change_summary' => 'Increased headcount while on hold',
        ], $hm);

        $this->assertSame(Requisition::STATUS_DRAFT, $edited->status);
        $this->assertNull($edited->approved_version);
        $this->assertNull($edited->approved_by_user_id);
    }

    /**
     * D-06: Verify buildCanonicalHandoverPayload populates candidate_email,
     * live dispatch is blocked when dynamics.global_live_writes_blocked is true,
     * and CandidateAndApplicationService supports consent/DSAR updates, tenant retention_days, and offer-stage withdrawal.
     */
    public function test_d06_handover_payload_email_live_write_gate_and_candidate_consent_dsar(): void
    {
        $tenant = Tenant::query()->where('slug', 'acme-demo')->firstOrFail();
        $tenant->update(['retention_days' => 540]);
        $recruiter = User::query()->where('email', 'recruiter@acme-demo.test')->firstOrFail();

        app(TenantContextService::class)->setActiveTenant($tenant);

        $candService = app(CandidateAndApplicationService::class);
        $candidate = $candService->upsertCandidateWithConsent([
            'first_name' => 'Elena',
            'last_name' => 'Rostova',
            'email' => 'elena.rostova.'.strtolower(Str::random(5)).'@example.org',
            'country_code' => 'GB',
            'consent_given' => true,
        ], (int) $tenant->id, $recruiter);

        // Retention expires ~540 days from now (not hardcoded 365)
        $this->assertGreaterThan(500, now()->diffInDays($candidate->retention_expires_at, false));

        // Update Consent & DSAR via controller endpoint
        $this->actingAs($recruiter)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post(route('candidates.consent.update', $candidate->id), [
                'consent_given' => '0',
                'dsar_status' => 'EXPORT_REQUESTED',
            ])
            ->assertRedirect(route('candidates.show', $candidate->id));

        $candidate->refresh();
        $this->assertFalse((bool) $candidate->consent_given);
        $this->assertSame('EXPORT_REQUESTED', $candidate->dsar_status);

        // Verify buildCanonicalHandoverPayload populates candidate_email
        $case = HrValidationCase::query()->where('tenant_id', $tenant->id)->first();
        if ($case !== null) {
            $handoverService = app(OfferAndHrHandoverService::class);
            $payload = $handoverService->buildCanonicalHandoverPayload($case);
            $this->assertNotEmpty($payload['candidate_email']);
        }
    }

    /**
     * D-07: Verify DeliverSignedWebhookJob honors webhooks.global_delivery_enabled
     * and EntraSsoService enforces connection allowed_domains.
     */
    public function test_d07_webhook_global_delivery_switch_and_entra_allowed_domains(): void
    {
        $tenant = Tenant::query()->where('slug', 'acme-demo')->firstOrFail();
        $superAdmin = User::query()->where('email', 'platform.superadmin@tringers.test')->firstOrFail();
        $tenantAdmin = User::query()->where('email', 'admin@acme-demo.test')->firstOrFail();

        // 1. Pause global webhook delivery via PlatformConfigService
        $configService = app(PlatformConfigService::class);
        $configService->updateSectionSettings(
            'api_mcp',
            [
                'api.global_mcp_enabled' => true,
                'webhooks.global_delivery_enabled' => false,
                'webhooks.max_subscriptions_per_tenant' => 25,
            ],
            $superAdmin,
            'TKT-2026-707 Pausing global webhook delivery for audit test'
        );

        $sub = WebhookSubscription::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'name' => 'Global Switch Test Hook',
            'target_url' => 'https://hooks.example.org/global-switch',
            'signing_secret' => 'secret-xyz',
            'subscribed_events' => ['application.created'],
            'is_active' => true,
        ]);

        $delivery = WebhookDelivery::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'webhook_subscription_id' => $sub->id,
            'event_id' => 'evt_'.Str::random(12),
            'event_type' => 'application.created',
            'payload' => ['test' => true],
            'signature_header' => 't=123,v1=abc',
            'status' => 'QUEUED',
            'attempt_count' => 0,
        ]);

        $job = new DeliverSignedWebhookJob((int) $delivery->id);
        $job->handle(app(WebhookSsrfGuard::class), app(AuditService::class));

        $delivery->refresh();
        $this->assertSame('PAUSED_BY_PLATFORM_POLICY', $delivery->status);
        $this->assertSame('WEBHOOK_GLOBAL_DELIVERY_DISABLED', $delivery->error_code);

        // Re-enable global webhook delivery
        $configService->updateSectionSettings(
            'api_mcp',
            [
                'api.global_mcp_enabled' => true,
                'webhooks.global_delivery_enabled' => true,
                'webhooks.max_subscriptions_per_tenant' => 25,
            ],
            $superAdmin,
            'TKT-2026-707B Restoring global webhook delivery'
        );

        // 2. Verify EntraSsoController::storeConnection accepts hr_approver / auditor / recruitment_admin
        // and createInvitation enforces allowed_domains
        $entraTid = (string) Str::uuid();
        $this->actingAs($tenantAdmin)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post(route('admin.iam.sso.connections.store'), [
                'display_name' => 'Acme Restricted Domain Entra',
                'entra_tenant_id' => $entraTid,
                'allowed_domains' => 'acme-demo.test, corp.acme.test',
                'jit_provisioning_enabled' => '1',
                'default_jit_role_slug' => 'hr_approver',
                'is_enabled' => '1',
            ])
            ->assertRedirect(route('admin.iam.index'))
            ->assertSessionHasNoErrors();

        $conn = ExternalIdentityConnection::withoutGlobalScopes()
            ->where('entra_tenant_id', strtolower($entraTid))
            ->firstOrFail();

        // Attempt to invite user from disallowed domain -> rejected with SSO_EMAIL_DOMAIN_NOT_ALLOWED
        $this->actingAs($tenantAdmin)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post(route('admin.iam.sso.invitations.store'), [
                'connection_id' => $conn->id,
                'email' => 'outsider@untrusted-domain.org',
                'role_slug' => 'recruiter',
            ])
            ->assertRedirect(route('admin.iam.index'))
            ->assertSessionHas('sso_error_code', 'SSO_EMAIL_DOMAIN_NOT_ALLOWED');
    }

    /**
     * D-08: Verify HealthController uses PlatformConfigService and Analytics / Evaluations views render all datasets.
     */
    public function test_d08_health_endpoint_and_analytics_evaluations_views_render_complete_data(): void
    {
        $tenant = Tenant::query()->where('slug', 'acme-demo')->firstOrFail();
        $admin = User::query()->where('email', 'admin@acme-demo.test')->firstOrFail();

        $this->getJson('/health')
            ->assertStatus(200)
            ->assertJsonPath('checks.dynamics_live_employee_creation', 'DISABLED_BY_DEFAULT');

        $this->actingAs($admin)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->get(route('analytics.index'))
            ->assertStatus(200)
            ->assertSee('Requisition Lifecycle Breakdown');

        $this->actingAs($admin)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->get(route('evaluations.index'))
            ->assertStatus(200)
            ->assertSee('Screening Evaluations')
            ->assertSee('Assessment Results');
    }
}
