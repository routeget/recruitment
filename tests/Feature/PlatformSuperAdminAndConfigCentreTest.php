<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Services\AiGatewayService;
use App\Services\ApiAndMcpPlatformService;
use App\Services\DynamicsHrConnectorService;
use App\Services\PlatformAdminService;
use App\Services\PlatformConfigService;
use Database\Seeders\AlphaSafeBootstrapSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class PlatformSuperAdminAndConfigCentreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AlphaSafeBootstrapSeeder::class);
    }

    public function test_cli_provisioning_refuses_synthetic_emails_weak_passwords_and_ambiguous_promotions_and_is_idempotent(): void
    {
        /** @var PlatformAdminService $adminService */
        $adminService = app(PlatformAdminService::class);

        // 1. Baseline: only synthetic .test demo superadmin exists; --require-real fails
        $statusBefore = $adminService->inspectSuperAdminStatus();
        $this->assertFalse($statusBefore['real_superadmin_provisioned']);
        $this->assertSame(0, $statusBefore['active_real_superadmin_count']);
        $this->assertSame(1, $statusBefore['active_demo_superadmin_count']);

        $exitCode = Artisan::call('platform:admin-status', ['--require-real' => true]);
        $this->assertSame(1, $exitCode);

        // 2. Synthetic .test email is strictly refused
        $exitSynthetic = Artisan::call('platform:admin-provision', [
            '--email' => 'fake.superadmin@example.test',
            '--name' => 'Synthetic Attempt',
            '--operator' => 'sec-ops',
            '--reason' => 'Testing synthetic email rejection rule',
            '--no-interaction' => true,
        ]);
        $this->assertSame(1, $exitSynthetic);
        $this->assertDatabaseHas('audit_events', [
            'module' => 'platform_admin',
            'action' => 'platform_superadmin.provision_rejected',
            'outcome' => 'DENIED',
            'error_code' => 'SYNTHETIC_EMAIL_FORBIDDEN',
        ]);

        // 3. Known demo password or weak password is refused
        try {
            $adminService->provisionSuperAdmin(
                email: 'real.superadmin@tringers.com',
                name: 'Real Platform Owner',
                plainPassword: 'AlphaDemo#2026!',
                operatorIdentifier: 'cli-operator',
                reason: 'Initial production Super Admin provisioning',
            );
            $this->fail('Expected demo password to be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('PASSWORD_POLICY_FAILED', $e->getMessage());
        }

        // 4. Existing tenant account is not silently promoted without --promote-existing
        $tenantAdmin = User::where('email', 'admin@acme-demo.test')->firstOrFail();
        $tenantAdmin->update(['email' => 'alice.vance@acme-enterprise.com']);

        $exitConflict = Artisan::call('platform:admin-provision', [
            '--email' => 'alice.vance@acme-enterprise.com',
            '--name' => 'Alice Vance',
            '--operator' => 'cli-operator',
            '--reason' => 'Attempted silent promotion without flag',
            '--no-interaction' => true,
        ]);
        $this->assertSame(1, $exitConflict);
        $this->assertFalse($tenantAdmin->fresh()->isActivePlatformSuperAdmin());
        $this->assertDatabaseHas('audit_events', [
            'module' => 'platform_admin',
            'action' => 'platform_superadmin.promotion_rejected',
            'outcome' => 'DENIED',
            'error_code' => 'EXISTING_TENANT_ACCOUNT_CONFLICT',
        ]);

        // 5. Provisioning a genuine Platform Super Admin succeeds and is audited without leaking secrets
        $strongSecret = 'Sup3rAdm!n#Vault9824$Xy';
        $result = $adminService->provisionSuperAdmin(
            email: 'chief.security@tringers.com',
            name: 'Chief Security Officer',
            plainPassword: $strongSecret,
            operatorIdentifier: 'ssh-root-operator',
            reason: 'Authorized production Platform Super Admin bootstrap',
        );

        $this->assertSame('PROVISIONED', $result['action']);
        $this->assertSame('ch***@tringers.com', $result['masked_email']);

        $realAdmin = User::where('email', 'chief.security@tringers.com')->firstOrFail();
        $this->assertTrue($realAdmin->isActivePlatformSuperAdmin());
        $this->assertFalse($realAdmin->isSyntheticDemoIdentity());
        $this->assertSame('platform_super_admin', $realAdmin->platform_role);
        $this->assertTrue(Hash::check($strongSecret, $realAdmin->password));

        // Verify audit record has no raw password or hash
        $auditEvent = AuditEvent::where('uuid', $result['audit_event_uuid'])->firstOrFail();
        $this->assertSame('platform_superadmin.provisioned', $auditEvent->action);
        $this->assertStringNotContainsString($strongSecret, (string) json_encode($auditEvent->metadata));

        // Status command now reports real Super Admin provisioned
        $this->assertSame(0, Artisan::call('platform:admin-status', ['--require-real' => true]));

        // 6. Duplicate invocation is an idempotent no-op
        $dupResult = $adminService->provisionSuperAdmin(
            email: 'chief.security@tringers.com',
            name: 'Chief Security Officer',
            plainPassword: null,
            operatorIdentifier: 'ssh-root-operator',
            reason: 'Idempotent retry verification check',
        );
        $this->assertSame('IDEMPOTENT_NOOP', $dupResult['action']);
        $this->assertSame(1, User::where('email', 'chief.security@tringers.com')->count());
    }

    public function test_last_super_admin_protection_and_self_revoke_prohibition_and_suspended_recovery(): void
    {
        /** @var PlatformAdminService $adminService */
        $adminService = app(PlatformAdminService::class);

        $adminService->provisionSuperAdmin(
            email: 'primary.ops@tringers.com',
            name: 'Primary Super Admin',
            plainPassword: 'Pr1mary#SuperAdm!n9921',
            operatorIdentifier: 'cli-operator',
            reason: 'Provision primary real Super Admin',
        );
        $primary = User::where('email', 'primary.ops@tringers.com')->firstOrFail();

        // 1. Self-revocation is forbidden
        try {
            $adminService->revokeSuperAdmin(
                targetUser: $primary,
                operatorIdentifier: $primary->email,
                reason: 'Attempting self revocation',
                actorUser: $primary,
            );
            $this->fail('Expected SELF_REVOKE_FORBIDDEN exception.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SELF_REVOKE_FORBIDDEN', $e->getMessage());
        }

        // 2. Last real Super Admin protection blocks CLI revocation when no other real Super Admin exists
        $exitRevokeLast = Artisan::call('platform:admin-revoke', [
            '--email' => 'primary.ops@tringers.com',
            '--operator' => 'cli-operator',
            '--reason' => 'Attempting to revoke the only real Super Admin',
            '--no-interaction' => true,
        ]);
        $this->assertSame(1, $exitRevokeLast);
        $this->assertTrue($primary->fresh()->isActivePlatformSuperAdmin());
        $this->assertDatabaseHas('audit_events', [
            'module' => 'platform_admin',
            'action' => 'platform_superadmin.revoke_blocked_last_admin',
            'outcome' => 'DENIED',
            'error_code' => 'LAST_SUPERADMIN_PROTECTION_TRIGGERED',
        ]);

        // 3. Provision a second real Super Admin; revoking + suspending the first now succeeds
        $adminService->provisionSuperAdmin(
            email: 'secondary.ops@tringers.com',
            name: 'Secondary Super Admin',
            plainPassword: 'Sec0ndary#SuperAdm!n8842',
            operatorIdentifier: 'cli-operator',
            reason: 'Provision secondary real Super Admin for redundancy',
        );
        $secondary = User::where('email', 'secondary.ops@tringers.com')->firstOrFail();

        $revokeResult = $adminService->revokeSuperAdmin(
            targetUser: $primary,
            operatorIdentifier: $secondary->email,
            reason: 'Planned rotation to secondary Super Admin with suspension',
            suspendAccount: true,
            actorUser: $secondary,
        );
        $this->assertSame('REVOKED', $revokeResult['action']);
        $this->assertFalse($primary->fresh()->isActivePlatformSuperAdmin());
        $this->assertSame('SUSPENDED', $primary->fresh()->status);

        // 4. Suspended account cannot log in
        $loginResponse = $this->post('/login', [
            'email' => 'primary.ops@tringers.com',
            'password' => 'Pr1mary#SuperAdm!n9921',
        ]);
        $loginResponse->assertSessionHasErrors('email');
        $this->assertGuest();

        // 5. Suspended account cannot be re-provisioned without --recover-suspended
        $exitSuspended = Artisan::call('platform:admin-provision', [
            '--email' => 'primary.ops@tringers.com',
            '--name' => 'Primary Super Admin',
            '--operator' => 'cli-operator',
            '--reason' => 'Attempt recovery without recover-suspended flag',
            '--promote-existing' => true,
            '--no-interaction' => true,
        ]);
        $this->assertSame(1, $exitSuspended);

        // 6. Explicit --recover-suspended restores the account
        $exitRecovered = Artisan::call('platform:admin-provision', [
            '--email' => 'primary.ops@tringers.com',
            '--name' => 'Primary Super Admin',
            '--operator' => 'cli-operator',
            '--reason' => 'Approved break-glass recovery of suspended administrator',
            '--recover-suspended' => true,
            '--promote-existing' => true,
            '--no-interaction' => true,
        ]);
        $this->assertSame(0, $exitRecovered);
        $this->assertTrue($primary->fresh()->isActivePlatformSuperAdmin());
    }

    public function test_authorization_matrix_denies_guest_tenant_admin_recruiter_candidate_suspended_and_api_mcp_principals(): void
    {
        // 1. Unauthenticated guest -> redirected to login / 401 JSON
        $this->get('/platform-admin')->assertRedirect('/login');
        $this->getJson('/platform-admin')->assertStatus(401);
        $this->post('/platform-admin/settings/branding', ['reason' => 'Unauthorized mutation'])->assertRedirect('/login');

        // 2. Mass assignment cannot set is_platform_superadmin
        $massAssigned = User::create([
            'name' => 'Attacker User',
            'email' => 'attacker@acme-corp.com',
            'password' => Hash::make('AttackerPass#2026!'),
            'is_platform_superadmin' => true,
        ]);
        $this->assertFalse($massAssigned->fresh()->isActivePlatformSuperAdmin());

        // 3. Tenant Admin is denied all /platform-admin routes and does not see Platform Configuration Centre link
        $tenantAdmin = User::where('email', 'admin@acme-demo.test')->firstOrFail();
        $dashboardHtml = $this->actingAs($tenantAdmin)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringNotContainsString('Platform Configuration Centre', (string) $dashboardHtml);

        $this->actingAs($tenantAdmin)->get('/platform-admin')->assertStatus(403);
        $this->actingAs($tenantAdmin)->getJson('/platform-admin')->assertStatus(403)->assertJsonPath('error.code', 'PLATFORM_SUPERADMIN_REQUIRED');
        $this->actingAs($tenantAdmin)->post('/platform-admin/settings/branding', [
            'settings' => ['platform.display_name' => 'Hijacked Name'],
            'reason' => 'Tenant admin attempting global mutation',
        ])->assertStatus(403);
        $this->actingAs($tenantAdmin)->post('/platform-admin/tenants', [
            'name' => 'Rogue Tenant',
            'slug' => 'rogue-tenant',
            'reason' => 'Unauthorized tenant creation',
        ])->assertStatus(403);

        // 4. Tenant Admin cannot grant platform_superadmin role via tenant IAM form
        $this->actingAs($tenantAdmin)->post('/admin/tenants-iam/members', [
            'name' => 'Escalated User',
            'email' => 'escalated@acme-corp.com',
            'role_slug' => 'platform_superadmin',
        ])->assertStatus(403);

        // 5. A user who holds the tenant-scoped 'platform_superadmin' Role in tenant_memberships but has is_platform_superadmin = false is DENIED
        $fakeRoleHolder = User::create([
            'name' => 'Tenant Role Only',
            'email' => 'role.only@acme-corp.com',
            'password' => Hash::make('RoleOnlyPass#2026!'),
            'current_tenant_id' => $tenantAdmin->current_tenant_id,
            'mfa_enabled' => true,
        ]);
        $superRole = Role::where('slug', 'platform_superadmin')->firstOrFail();
        TenantMembership::create([
            'tenant_id' => $tenantAdmin->current_tenant_id,
            'user_id' => $fakeRoleHolder->id,
            'role_id' => $superRole->id,
            'status' => 'ACTIVE',
        ]);
        $this->assertFalse($fakeRoleHolder->fresh()->isActivePlatformSuperAdmin());
        $this->actingAs($fakeRoleHolder)->get('/platform-admin')->assertStatus(403);

        // 6. Recruiter is denied
        $recruiter = User::where('email', 'recruiter@acme-demo.test')->firstOrFail();
        $this->actingAs($recruiter)->get('/platform-admin')->assertStatus(403);

        // 7. API / MCP machine principal bearer token cannot access /platform-admin
        $tenant = Tenant::where('slug', 'acme-demo')->firstOrFail();
        $clientRes = app(ApiAndMcpPlatformService::class)->createApiClient(
            'Machine Bot',
            ['requisitions:read', 'mcp:tools:read'],
            (int) $tenant->id,
            $tenantAdmin
        );
        auth()->logout();
        $bearer = $clientRes['client']->client_id.':'.$clientRes['raw_secret'];
        $this->withHeaders(['Authorization' => 'Bearer '.$bearer, 'Accept' => 'application/json'])
            ->getJson('/platform-admin')
            ->assertStatus(401);
    }

    public function test_configuration_centre_sections_validation_optimistic_locking_runtime_effects_and_secret_masking(): void
    {
        /** @var PlatformAdminService $adminService */
        $adminService = app(PlatformAdminService::class);
        $adminService->provisionSuperAdmin(
            email: 'global.superadmin@tringers.com',
            name: 'Global Super Admin',
            plainPassword: 'Gl0bal#SuperAdm!n2026$',
            operatorIdentifier: 'cli-operator',
            reason: 'Provision Super Admin for Configuration Centre verification',
        );
        $superAdmin = User::where('email', 'global.superadmin@tringers.com')->firstOrFail();

        // Create a webhook subscription with a secret to verify it never leaks into HTML
        $tenant = Tenant::where('slug', 'acme-demo')->firstOrFail();
        $tenantAdmin = User::where('email', 'admin@acme-demo.test')->firstOrFail();
        $sub = WebhookSubscription::create([
            'tenant_id' => $tenant->id,
            'name' => 'Acme ERP Hook',
            'target_url' => 'https://hooks.acme-enterprise.com/events',
            'signing_secret' => 'whsec_SUPER_SECRET_DO_NOT_LEAK_999',
            'subscribed_events' => ['offer.accepted'],
            'is_active' => true,
        ]);

        // 1. All 11 sections render for active Platform Super Admin and never expose secrets
        foreach (['overview', 'branding', 'tenants', 'admins', 'auth-sso', 'ai-governance', 'api-mcp', 'integrations', 'security', 'audit', 'readiness'] as $tab) {
            $resp = $this->actingAs($superAdmin)->get('/platform-admin?tab='.$tab);
            $resp->assertOk();
            $html = (string) $resp->getContent();
            $this->assertStringContainsString('Platform Administration &amp; Configuration Centre', $html);
            $this->assertStringNotContainsString('whsec_SUPER_SECRET_DO_NOT_LEAK_999', $html);
            $this->assertStringNotContainsString((string) config('app.key'), $html);
        }

        // 2. Arbitrary/unregistered key is rejected with no partial writes
        $this->actingAs($superAdmin)->post('/platform-admin/settings/branding', [
            'settings' => [
                'platform.display_name' => 'Should Not Persist',
                'arbitrary.injected_key' => 'evil_value',
            ],
            'reason' => 'Testing arbitrary key rejection',
        ])->assertSessionHasErrors('settings');
        $this->assertSame('Unified Recruitment AI Platform', app(PlatformConfigService::class)->getString('platform.display_name'));

        // 3. Valid branding update persists, increments version, audits before/after, and updates live UI + /careers
        $this->actingAs($superAdmin)->post('/platform-admin/settings/branding', [
            'settings' => [
                'platform.display_name' => 'Tringers Enterprise Recruitment Cloud',
                'platform.brand_code' => 'TERC',
                'platform.support_email' => 'ops-desk@tringers.com',
                'platform.default_timezone' => 'UTC',
                'platform.default_currency' => 'USD',
                'platform.career_portal_banner' => 'Governed Enterprise Careers — Verified Listings',
            ],
            'expected_versions' => [
                'platform.display_name' => 1,
            ],
            'reason' => 'Updated global brand identity and career portal banner',
        ])->assertRedirect(route('platform-admin.index', ['tab' => 'branding']));

        $this->assertSame('Tringers Enterprise Recruitment Cloud', app(PlatformConfigService::class)->getString('platform.display_name'));
        $careersHtml = (string) $this->get('/careers')->assertOk()->getContent();
        $this->assertStringContainsString('Governed Enterprise Careers — Verified Listings', $careersHtml);
        $this->assertStringContainsString('Tringers Enterprise Recruitment Cloud', $careersHtml);

        // 4. Optimistic locking rejects stale expected_version
        $this->actingAs($superAdmin)->post('/platform-admin/settings/branding', [
            'settings' => [
                'platform.display_name' => 'Stale Overwrite Attempt',
                'platform.brand_code' => 'TERC',
                'platform.support_email' => 'ops-desk@tringers.com',
                'platform.default_timezone' => 'UTC',
                'platform.default_currency' => 'USD',
                'platform.career_portal_banner' => 'Governed Enterprise Careers — Verified Listings',
            ],
            'expected_versions' => [
                'platform.display_name' => 1, // Current version is now 2!
            ],
            'reason' => 'Testing optimistic lock conflict detection',
        ])->assertSessionHasErrors('version');
        $this->assertSame('Tringers Enterprise Recruitment Cloud', app(PlatformConfigService::class)->getString('platform.display_name'));

        // 5. Global AI Kill Switch immediately halts AiGatewayService::executeTask
        $this->actingAs($superAdmin)->post('/platform-admin/settings/ai_governance', [
            'settings' => [
                'ai.global_kill_switch' => '1',
                'ai.global_monthly_budget_cap_minor' => 200000,
                'ai.mandatory_pii_redaction' => '1',
                'ai.default_primary_provider' => 'claude',
            ],
            'reason' => 'Engaging global AI emergency kill switch for verification',
        ])->assertRedirect(route('platform-admin.index', ['tab' => 'ai-governance']));

        try {
            app(AiGatewayService::class)->executeTask(
                'job_description_draft',
                'deterministic_fake',
                'Draft a Senior Engineer role',
                $tenantAdmin,
                (int) $tenant->id
            );
            $this->fail('Expected AI_GLOBAL_KILL_SWITCH_ENGAGED exception.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('AI_GLOBAL_KILL_SWITCH_ENGAGED', $e->getMessage());
        }

        // 6. Disabling api.global_mcp_enabled immediately causes /mcp/v1/tools to return 503 MCP_GLOBALLY_DISABLED
        $this->actingAs($superAdmin)->post('/platform-admin/settings/api_mcp', [
            'settings' => [
                'api.global_mcp_enabled' => '0',
                'webhooks.global_delivery_enabled' => '0',
                'webhooks.max_subscriptions_per_tenant' => 10,
            ],
            'reason' => 'Pausing global MCP server and outbound webhooks for maintenance',
        ])->assertRedirect(route('platform-admin.index', ['tab' => 'api-mcp']));

        $this->actingAs($tenantAdmin)->getJson('/mcp/v1/tools')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'MCP_GLOBALLY_DISABLED');

        $delivery = app(ApiAndMcpPlatformService::class)->dispatchSignedWebhook(
            $sub,
            'offer.accepted',
            ['offer_id' => 101],
            $tenantAdmin
        );
        $this->assertSame('PAUSED_BY_PLATFORM_POLICY', $delivery->status);

        // 7. Dynamics global live-writes safety gate prevents enabling real_employee_creation_enabled
        $dynConfig = app(DynamicsHrConnectorService::class)->ensureConfigForTenant((int) $tenant->id);
        $updatedDyn = app(DynamicsHrConnectorService::class)->updateConfig($dynConfig, [
            'real_employee_creation_enabled' => true,
        ], $tenantAdmin);
        $this->assertFalse($updatedDyn->real_employee_creation_enabled);
        $this->assertSame('BLOCKED_BY_PLATFORM_SAFETY_GATE', $updatedDyn->last_health_status);

        // 8. Tenant Lifecycle creation & suspension + retention floor check
        $this->actingAs($superAdmin)->post('/platform-admin/tenants', [
            'name' => 'Northwind Aviation Ltd',
            'slug' => 'northwind-aviation',
            'default_currency' => 'GBP',
            'retention_days' => 30, // Below floor of 180!
            'reason' => 'Attempting below-floor retention',
        ])->assertSessionHasErrors('retention_days');

        $this->actingAs($superAdmin)->post('/platform-admin/tenants', [
            'name' => 'Northwind Aviation Ltd',
            'slug' => 'northwind-aviation',
            'default_currency' => 'GBP',
            'retention_days' => 365,
            'reason' => 'Onboarding Northwind Aviation enterprise tenant',
        ])->assertRedirect(route('platform-admin.index', ['tab' => 'tenants']));

        $northwind = Tenant::where('slug', 'northwind-aviation')->firstOrFail();
        $this->assertFalse($northwind->is_demo);
        $this->assertSame('ACTIVE', $northwind->status);

        $this->actingAs($superAdmin)->post("/platform-admin/tenants/{$northwind->id}/lifecycle", [
            'status' => 'SUSPENDED',
            'ai_kill_switch' => '1',
            'mcp_enabled' => '0',
            'sso_required' => '0',
            'retention_days' => 365,
            'reason' => 'Suspending Northwind tenant for security review',
        ])->assertRedirect(route('platform-admin.index', ['tab' => 'tenants']));

        $this->assertSame('SUSPENDED', $northwind->fresh()->status);
        $this->assertTrue($northwind->fresh()->ai_kill_switch);
    }
}
