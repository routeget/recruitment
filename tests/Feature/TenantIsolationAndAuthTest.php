<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
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
        $root = $this->get('/');
        $root->assertOk()
            ->assertSee('Explore Approved Open Positions')
            ->assertSee('Senior AI Platform Architect (Demo)');

        $tenantPortal = $this->get('/acme-demo');
        $tenantPortal->assertOk()
            ->assertSee('Explore Open Positions at Acme Global Tech (Demo)')
            ->assertSee('Senior AI Platform Architect (Demo)');

        $this->get('/does-not-exist')->assertNotFound();
    }

    public function test_platform_admin_and_tenant_namespaces_are_isolated(): void
    {
        $tenantUser = User::where('email', 'recruiter@acme-demo.test')->firstOrFail();
        $platformSuperAdmin = User::where('email', 'platform.superadmin@tringers.test')->firstOrFail();

        $this->actingAs($tenantUser)->get('/platformadmin')->assertForbidden();
        $this->actingAs($platformSuperAdmin)->get('/organization')->assertForbidden();

        $this->actingAs($platformSuperAdmin)->get('/platformadmin')->assertOk();
        $this->assertSame('/platformadmin', parse_url(route('platform-admin.index'), PHP_URL_PATH));
        $this->assertSame('/platformadmin/integrations/api', parse_url(route('platform-admin.integrations.api'), PHP_URL_PATH));
    }

    public function test_platform_admin_role_is_allowed_platform_landing_but_not_tenant_namespace(): void
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

        $this->actingAs($platformAdmin)->post('/platformadmin/tenants', [
            'name' => 'Duplicate Tenant',
            'slug' => 'acme-demo',
            'reason' => 'Testing unique tenant slug validation.',
        ])->assertSessionHasErrors('slug');

        $this->actingAs($platformAdmin)->post('/platformadmin/tenants', [
            'name' => 'Reserved Tenant',
            'slug' => 'platformadmin',
            'reason' => 'Testing reserved tenant slug validation.',
        ])->assertSessionHasErrors('slug');

        $platformAdmin->forceFill([
            'is_platform_superadmin' => false,
            'platform_role' => 'platform_admin',
        ])->save();

        $this->actingAs($platformAdmin)->post('/platformadmin/tenants', [
            'name' => 'Codex Hiring',
            'slug' => 'codex-test',
            'reason' => 'Provisioning a new tenant with an immutable career portal slug.',
        ])->assertRedirect();

        $this->assertDatabaseHas('tenants', ['slug' => 'codex-test', 'name' => 'Codex Hiring']);
        $this->get('/codex-test')->assertOk()->assertSee('Explore Open Positions at Codex Hiring');
    }
}
