<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Requisition;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PlatformConfigService;
use Database\Seeders\AlphaSafeBootstrapSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class QuickBookErpThemeSidebarLoginTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AlphaSafeBootstrapSeeder::class);
    }

    public function test_centralized_enterprise_theme_tokens_and_css_injection_sanitization(): void
    {
        $cssPath = public_path('css/platform-theme.css');
        $this->assertFileExists($cssPath);

        $css = (string) file_get_contents($cssPath);
        $this->assertStringContainsString('--color-primary-actions: #0078D4;', $css);
        $this->assertStringContainsString('--color-header-emphasis: #004578;', $css);
        $this->assertStringContainsString('--color-header-dark: #002050;', $css);
        $this->assertStringContainsString('--color-surface-main: #FFFFFF;', $css);
        $this->assertStringContainsString('--color-bg-canvas: #F5F5F5;', $css);
        $this->assertStringContainsString('--color-text-main: #323130;', $css);
        $this->assertStringContainsString('--color-text-secondary: #605E5C;', $css);
        $this->assertStringContainsString('--color-border-input: #D2D0CE;', $css);
        $this->assertStringContainsString('--color-border-divider: #EDEBE9;', $css);
        $this->assertStringContainsString('--color-hover-bg: #F3F9FD;', $css);
        $this->assertStringContainsString('--color-selected-row-bg: #EFF6FC;', $css);
        $this->assertStringContainsString('--color-selected-control: #DEECF9;', $css);
        $this->assertStringContainsString('--sidebar-width: 260px;', $css);
        $this->assertStringContainsString('--header-height: 48px;', $css);
        $this->assertStringContainsString('border-left: 3px solid transparent;', $css);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);

        // Verify strict hex color validation and CSS injection prevention
        $this->assertSame('#107C10', PlatformConfigService::sanitizeHexColor('#107c10', '#0078D4'));
        $this->assertSame('#0078D4', PlatformConfigService::sanitizeHexColor('#0078D4; background:url(javascript:alert(1))', '#0078D4'));
        $this->assertSame('#0078D4', PlatformConfigService::sanitizeHexColor('expression(alert(1))', '#0078D4'));
        $this->assertSame('#0078D4', PlatformConfigService::sanitizeHexColor('#123', '#0078D4'));
        $this->assertSame('#0078D4', PlatformConfigService::sanitizeHexColor(null, '#0078D4'));

        config(['recruitment.theme.primary_color' => '#0078D4; body{display:none}']);
        $loginHtml = (string) $this->get('/login')->assertOk()->getContent();
        $this->assertStringContainsString('--color-primary-actions: #0078D4;', $loginHtml);
        $this->assertStringNotContainsString('body{display:none}', $loginHtml);
    }

    public function test_asymmetric_7_5_login_layout_wave_svg_and_production_demo_persona_gating(): void
    {
        $response = $this->get('/login');
        $response->assertOk();

        $html = (string) $response->getContent();

        // 1. Verify QuickBook ERP-inspired 7/5 split (col-lg-7 + col-lg-5) and absence of 75/25 split
        $this->assertStringContainsString('col-lg-7 d-none d-lg-flex urap-auth-brand-pane', $html);
        $this->assertStringContainsString('col-lg-5 urap-auth-form-pane', $html);
        $this->assertStringNotContainsString('col-lg-9', $html);
        $this->assertStringNotContainsString('col-lg-3', $html);

        // 2. Verify decorative SVG wave, mobile header, constrained form container, and SSO fail-closed controls
        $this->assertStringContainsString('class="urap-auth-wave"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('urap-auth-form-container', $html);
        $this->assertStringContainsString('urap-auth-mobile-banner d-lg-none', $html);
        $this->assertStringContainsString('Sign in with Microsoft work or school account', $html);

        // 3. Verify no hardcoded demo password literal is exposed in HTML source
        $this->assertStringNotContainsString('AlphaDemo#2026!', $html);

        // 4. Verify synthetic demo persona tables and test account rosters are never rendered on the user-facing login screen in any environment
        $this->assertStringNotContainsString('Development Demo Personas', $html);
        $this->assertStringNotContainsString('DEMO ONLY', $html);
        $this->assertStringNotContainsString('admin@acme-demo.test', $html);
        $this->assertStringNotContainsString('platform.superadmin@tringers.test', $html);
        $this->assertStringNotContainsString('MICROSOFT_ENTRA_CLIENT_ID', $html);
    }

    public function test_sidebar_shell_collapsible_groups_active_states_and_authorization_visibility(): void
    {
        $tenant = Tenant::where('slug', 'acme-demo')->firstOrFail();
        $tenantAdmin = User::where('email', 'admin@acme-demo.test')->firstOrFail();
        $recruiter = User::where('email', 'recruiter@acme-demo.test')->firstOrFail();
        $superAdmin = User::where('email', 'platform.superadmin@tringers.test')->firstOrFail();

        // 1. Tenant Admin sees full tenant navigation, collapsible groups, aligned icons, and no dead links
        $adminDashboard = $this->actingAs($tenantAdmin)->get('/organization')->assertOk();
        $adminHtml = (string) $adminDashboard->getContent();
        $this->assertStringContainsString('urap-topbar', $adminHtml);
        $this->assertStringContainsString('urap-sidebar offcanvas-lg offcanvas-start', $adminHtml);
        $this->assertStringContainsString('urap-nav-group-toggle', $adminHtml);
        $this->assertStringContainsString('aria-expanded="true"', $adminHtml);
        $this->assertStringContainsString('Organization Setup', $adminHtml);
        $this->assertStringContainsString('Workforce Requisitions', $adminHtml);
        $this->assertStringContainsString('Candidate CRM &amp; Documents', $adminHtml);
        $this->assertStringContainsString('Tenants &amp; IAM Access (Tenant Scope)', $adminHtml);
        $this->assertStringNotContainsString('Platform Configuration Centre', $adminHtml);
        $this->assertStringNotContainsString('href="#"', $adminHtml);

        // 2. Active route matching on index and detail routes
        $requisition = Requisition::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $reqDetailHtml = (string) $this->actingAs($tenantAdmin)->get("/requisitions/{$requisition->id}")->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/<a class="nav-link active"[^>]*href="[^"]*\/requisitions"/',
            $reqDetailHtml
        );

        $candidate = Candidate::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $candDetailHtml = (string) $this->actingAs($tenantAdmin)->get("/candidates/{$candidate->id}")->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/<a class="nav-link active"[^>]*href="[^"]*\/candidates"/',
            $candDetailHtml
        );

        // 3. Recruiter sees recruitment workflows but NOT privileged org/IAM/API/Platform Admin links
        $recruiterHtml = (string) $this->actingAs($recruiter)->get('/requisitions')->assertOk()->getContent();
        $this->assertStringContainsString('Workforce Requisitions', $recruiterHtml);
        $this->assertStringContainsString('Candidate CRM &amp; Documents', $recruiterHtml);
        $this->assertStringNotContainsString('Organization Setup', $recruiterHtml);
        $this->assertStringNotContainsString('Tenants &amp; IAM Access (Tenant Scope)', $recruiterHtml);
        $this->assertStringNotContainsString('API Platform, Webhooks &amp; MCP', $recruiterHtml);
        $this->assertStringNotContainsString('Platform Configuration Centre', $recruiterHtml);

        // 4. Platform Super Admin sees Platform Administration group & indented sub-navigation on /platformadmin
        $platformHtml = (string) $this->actingAs($superAdmin)->get('/platformadmin/section/branding')->assertOk()->getContent();
        $this->assertStringContainsString('Platform Configuration Centre', $platformHtml);
        $this->assertStringContainsString('Branding &amp; Theme Tokens', $platformHtml);
        $this->assertStringContainsString('urap-nav-sublink active', $platformHtml);
    }
}
