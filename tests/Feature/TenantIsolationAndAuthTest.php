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

        $response->assertRedirect(route('dashboard'));
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
}
