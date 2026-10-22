<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\JobPosting;
use App\Models\Requisition;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AlphaSafeBootstrapSeeder;
use LogicException;
use Tests\TestCase;

class TenantIsolationAndAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AlphaSafeBootstrapSeeder::class);
    }

    public function test_health_endpoint_returns_secret_safe_status_and_security_headers(): void
    {
        $response = $this->getJson('/health');

        $response->assertStatus(200)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertJsonPath('status', 'OK')
            ->assertJsonPath('checks.database', 'CONNECTED_SCHEMA_READY')
            ->assertJsonPath('checks.hr_handover_safety_gate', 'ENFORCED');
    }

    public function test_public_setup_route_is_locked_and_disabled(): void
    {
        $response = $this->getJson('/setup/status');
        $response->assertStatus(403)
            ->assertJsonPath('public_setup_enabled', false);
    }

    public function test_user_can_authenticate_and_resolves_trusted_tenant_context(): void
    {
        $response = $this->post('/login', [
            'email' => 'admin@acme-demo.test',
            'password' => 'AlphaDemo#2026!',
        ]);

        $response->assertRedirect(route('organization.home'));
        $this->assertAuthenticated();

        $dashboard = $this->get('/dashboard');
        $dashboard->assertStatus(200)
            ->assertSee('Acme Global Tech (Demo)')
            ->assertSee('tenant_admin');
    }

    public function test_user_cannot_switch_to_unauthorized_tenant(): void
    {
        $acmeAdmin = User::where('email', 'admin@acme-demo.test')->firstOrFail();
        $globexTenant = Tenant::where('slug', 'globex-demo')->firstOrFail();

        $response = $this->actingAs($acmeAdmin)->post('/tenant/switch', [
            'tenant_id' => $globexTenant->id,
        ]);

        $response->assertStatus(403);
        $this->assertSame(
            Tenant::where('slug', 'acme-demo')->value('id'),
            $acmeAdmin->fresh()->current_tenant_id
        );
    }

    public function test_role_without_iam_permission_cannot_access_iam_admin(): void
    {
        $recruiter = User::where('email', 'recruiter@acme-demo.test')->firstOrFail();

        $response = $this->actingAs($recruiter)->get('/admin/tenants-iam');
        $response->assertStatus(403);
    }

    public function test_audit_events_are_append_only_and_reject_mutation_or_deletion(): void
    {
        $this->post('/login', [
            'email' => 'admin@acme-demo.test',
            'password' => 'AlphaDemo#2026!',
        ]);

        $event = AuditEvent::firstOrFail();

        $this->expectException(LogicException::class);
        $event->update(['outcome' => 'TAMPERED']);
    }

    public function test_public_root_is_aggregate_career_portal_and_tenant_slug_route_is_scoped(): void
    {
        $acmeTenant = Tenant::where('slug', 'acme-demo')->firstOrFail();
        $globexTenant = Tenant::where('slug', 'globex-demo')->firstOrFail();
        $globexRequisition = Requisition::withoutTenantScope()
            ->where('tenant_id', $globexTenant->id)
            ->firstOrFail();

        // Publish a Globex job to verify aggregate vs tenant-scoped filtering
        $globexJob = JobPosting::withoutTenantScope()->create([
            'tenant_id' => $globexTenant->id,
            'requisition_id' => $globexRequisition->id,
            'department_id' => $globexRequisition->department_id,
            'location_id' => $globexRequisition->location_id,
            'code' => 'JOB-GLX-900',
            'slug' => 'globex-principal-cloud-engineer',
            'title' => 'Globex Principal Cloud Engineer',
            'work_mode' => 'REMOTE',
            'employment_type' => 'FULL_TIME',
            'summary' => 'Lead cloud architecture at Globex.',
            'responsibilities' => 'Build distributed cloud systems.',
            'qualifications' => '5+ years cloud architecture.',
            'status' => JobPosting::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $root = $this->get('/');
        $root->assertOk()
            ->assertSee('Explore Approved Open Positions')
            ->assertSee('Senior AI Platform Architect (Demo)')
            ->assertSee('Globex Principal Cloud Engineer');

        $careers = $this->get('/careers');
        $careers->assertOk()
            ->assertSee('Explore Approved Open Positions')
            ->assertSee('Senior AI Platform Architect (Demo)')
            ->assertSee('Globex Principal Cloud Engineer');

        // Tenant portal shows only acme-demo vacancies, even if ?tenant=globex-demo is passed in query string
        $tenantPortal = $this->get('/acme-demo?tenant=globex-demo');
        $tenantPortal->assertOk()
            ->assertSee('Explore Open Positions at Acme Global Tech (Demo)')
            ->assertSee('Senior AI Platform Architect (Demo)')
            ->assertDontSee('Globex Principal Cloud Engineer');

        // Job detail scoping: acme job on acme-demo works, on globex-demo returns 404
        $acmeJob = JobPosting::withoutTenantScope()
            ->where('tenant_id', $acmeTenant->id)
            ->where('status', JobPosting::STATUS_PUBLISHED)
            ->firstOrFail();

        $this->get("/acme-demo/jobs/{$acmeJob->slug}")
            ->assertOk()
            ->assertSee($acmeJob->title);

        $this->get("/globex-demo/jobs/{$acmeJob->slug}")
            ->assertNotFound();

        // Application submission scoping: applying to acme job under globex-demo returns 404
        $this->post("/globex-demo/jobs/{$acmeJob->slug}/apply", [
            'first_name' => 'Cross',
            'last_name' => 'Tenant',
            'email' => 'cross.tenant@example.com',
            'country_code' => 'US',
            'consent_given' => '1',
        ])->assertNotFound();

        $this->post("/acme-demo/jobs/{$acmeJob->slug}/apply", [
            'first_name' => 'Valid',
            'last_name' => 'Applicant',
            'email' => 'valid.applicant@example.com',
            'country_code' => 'US',
            'consent_given' => '1',
        ])->assertRedirect(route('careers.show', ['tenantSlug' => 'acme-demo', 'jobSlug' => $acmeJob->slug]));

        // Suspended tenant returns 404 on tenant portal and is excluded from aggregate portal and sitemap
        $globexTenant->update(['status' => 'SUSPENDED']);
        $this->get('/globex-demo')->assertNotFound();
        $this->get("/globex-demo/jobs/{$globexJob->slug}")->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('Globex Principal Cloud Engineer');
        $this->get('/sitemap.xml')->assertOk()->assertDontSee($globexJob->slug);

        $this->get('/does-not-exist')->assertNotFound();
    }

    public function test_role_based_login_redirection_and_untrusted_intended_url_protection(): void
    {
        // 1. Platform Super Admin redirects to /platformadmin even if session url.intended points to /organization
        $this->withSession(['url.intended' => url('/organization')])
            ->post('/login', [
                'email' => 'platform.superadmin@tringers.test',
                'password' => 'AlphaDemo#2026!',
            ])
            ->assertRedirect(route('platform-admin.index'));
        $this->post('/logout');

        // 2. All active tenant personas redirect to /organization even if session url.intended points to /platformadmin
        $tenantEmails = [
            'admin@acme-demo.test',
            'recruiter@acme-demo.test',
            'manager@acme-demo.test',
            'hr.validator@acme-demo.test',
            'hr.approver@acme-demo.test',
            'auditor@acme-demo.test',
        ];

        foreach ($tenantEmails as $email) {
            $this->withSession(['url.intended' => url('/platformadmin')])
                ->post('/login', [
                    'email' => $email,
                    'password' => 'AlphaDemo#2026!',
                ])
                ->assertRedirect(route('organization.home'));
            $this->post('/logout');
        }

        // 3. Suspended account is denied login
        $suspendedUser = User::where('email', 'recruiter@acme-demo.test')->firstOrFail();
        $suspendedUser->forceFill(['status' => 'SUSPENDED'])->save();
        $this->post('/login', [
            'email' => 'recruiter@acme-demo.test',
            'password' => 'AlphaDemo#2026!',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();
        $suspendedUser->forceFill(['status' => 'ACTIVE'])->save();

        // 4. Unassociated user (no active tenant membership and not platform admin) is denied login
        $orphanUser = User::create([
            'name' => 'Orphan User',
            'email' => 'orphan.user@acme-demo.test',
            'password' => bcrypt('AlphaDemo#2026!'),
            'status' => 'ACTIVE',
            'mfa_enabled' => true,
        ]);
        $this->post('/login', [
            'email' => $orphanUser->email,
            'password' => 'AlphaDemo#2026!',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_platform_admin_and_tenant_namespaces_are_isolated(): void
    {
        // Unauthenticated requests redirect to /login
        $this->get('/platformadmin')->assertRedirect(route('login'));
        $this->get('/organization')->assertRedirect(route('login'));

        $tenantUser = User::where('email', 'recruiter@acme-demo.test')->firstOrFail();
        $platformSuperAdmin = User::where('email', 'platform.superadmin@tringers.test')->firstOrFail();

        $this->actingAs($tenantUser)->get('/platformadmin')->assertForbidden();
        $this->actingAs($tenantUser)->get('/platformadmin/integrations/api')->assertForbidden();
        $this->actingAs($platformSuperAdmin)->get('/organization')->assertForbidden();

        $this->actingAs($platformSuperAdmin)->get('/platformadmin')->assertOk();
        $this->actingAs($platformSuperAdmin)->get('/platformadmin/integrations/api')->assertOk();
        $this->assertSame('/platformadmin', parse_url(route('platform-admin.index'), PHP_URL_PATH));
        $this->assertSame('/platformadmin/integrations/api', parse_url(route('platform-admin.integrations.api'), PHP_URL_PATH));
    }

    public function test_platform_admin_role_is_allowed_platform_landing_but_denied_superadmin_mutations_and_tenant_namespace(): void
    {
        $platformAdmin = User::where('email', 'platform.superadmin@tringers.test')->firstOrFail();
        $platformAdmin->forceFill([
            'is_platform_superadmin' => false,
            'platform_role' => 'platform_admin',
        ])->save();

        $this->post('/login', [
            'email' => 'platform.superadmin@tringers.test',
            'password' => 'AlphaDemo#2026!',
        ])->assertRedirect(route('platform-admin.index'));
        $this->assertAuthenticated();
        $this->get('/platformadmin')->assertOk();
        $this->get('/organization')->assertForbidden();

        // Ordinary platform_admin is denied Super Admin-only mutations with 403 Forbidden
        $acmeTenant = Tenant::where('slug', 'acme-demo')->firstOrFail();
        $this->post('/platformadmin/settings/branding', [
            'settings' => ['branding.platform_name' => 'Unauthorized Change'],
            'reason' => 'Testing ordinary platform_admin restriction.',
        ])->assertForbidden();

        $this->post("/platformadmin/tenants/{$acmeTenant->id}/lifecycle", [
            'status' => 'SUSPENDED',
            'retention_days' => 365,
            'reason' => 'Testing ordinary platform_admin restriction.',
        ])->assertForbidden();

        $this->post('/platformadmin/admins/promote', [
            'email' => 'recruiter@acme-demo.test',
            'name' => 'Recruiter',
            'reason' => 'Testing ordinary platform_admin restriction.',
        ])->assertForbidden();

        $this->post('/platformadmin/support-context', [
            'target_tenant_id' => $acmeTenant->id,
            'support_ticket_reference' => 'SUP-999',
            'justification' => 'Testing ordinary platform_admin restriction.',
        ])->assertForbidden();
    }

    public function test_tenant_slug_is_immutable_after_creation(): void
    {
        $tenant = Tenant::where('slug', 'acme-demo')->firstOrFail();

        try {
            $tenant->slug = 'acme-renamed';
            $tenant->save();
            $this->fail('Changing a tenant slug after creation must be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('TENANT_SLUG_IMMUTABLE', $exception->getMessage());
        }

        $this->assertSame('acme-demo', $tenant->fresh()->slug);
    }

    public function test_tenant_creation_requires_a_unique_non_reserved_slug(): void
    {
        $platformAdmin = User::where('email', 'platform.superadmin@tringers.test')->firstOrFail();

        // Missing or whitespace-only slug rejected
        $this->actingAs($platformAdmin)->post('/platformadmin/tenants', [
            'name' => 'Missing Slug Tenant',
            'slug' => '   ',
            'reason' => 'Testing missing tenant slug validation.',
        ])->assertSessionHasErrors('slug');

        // Invalid characters, spaces, and malformed hyphens rejected
        foreach (['invalid slug', 'invalid--slug', '-leading-hyphen', 'trailing-hyphen-', 'invalid_underscore'] as $invalidSlug) {
            $this->actingAs($platformAdmin)->post('/platformadmin/tenants', [
                'name' => 'Invalid Slug Tenant',
                'slug' => $invalidSlug,
                'reason' => 'Testing invalid tenant slug format validation.',
            ])->assertSessionHasErrors('slug');
        }

        // Duplicate slug rejected
        $this->actingAs($platformAdmin)->post('/platformadmin/tenants', [
            'name' => 'Duplicate Tenant',
            'slug' => 'acme-demo',
            'reason' => 'Testing unique tenant slug validation.',
        ])->assertSessionHasErrors('slug');

        // Reserved slugs rejected at both HTTP validation and model layers
        foreach (['login', 'platformadmin', 'organization', 'jobs', 'careers', 'api', 'mcp'] as $reservedSlug) {
            $this->actingAs($platformAdmin)->post('/platformadmin/tenants', [
                'name' => 'Reserved Tenant',
                'slug' => $reservedSlug,
                'reason' => 'Testing reserved tenant slug validation.',
            ])->assertSessionHasErrors('slug');

            try {
                Tenant::create([
                    'name' => 'Direct Reserved Tenant',
                    'slug' => $reservedSlug,
                    'status' => 'ACTIVE',
                ]);
                $this->fail("Direct model creation with reserved slug '{$reservedSlug}' must be rejected.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('TENANT_SLUG_RESERVED', $e->getMessage());
            }
        }

        // Uppercase slug is normalized to lowercase and ordinary platform_admin can provision a tenant
        $platformAdmin->forceFill([
            'is_platform_superadmin' => false,
            'platform_role' => 'platform_admin',
        ])->save();

        $this->actingAs($platformAdmin)->post('/platformadmin/tenants', [
            'name' => 'Codex Hiring',
            'slug' => '  Codex-Test  ',
            'reason' => 'Provisioning a new tenant with an immutable career portal slug.',
        ])->assertRedirect();

        $this->assertDatabaseHas('tenants', ['slug' => 'codex-test', 'name' => 'Codex Hiring']);
        $this->get('/codex-test')->assertOk()->assertSee('Explore Open Positions at Codex Hiring');
    }
}
