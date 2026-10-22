<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\JobPosting;
use App\Models\Requisition;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AlphaSafeBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SystemWideProductionUxRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;

    protected User $adminA;

    protected User $recruiterA;

    protected User $managerA;

    protected User $hrValidatorA;

    protected User $hrApproverA;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(AlphaSafeBootstrapSeeder::class);

        $this->tenantA = Tenant::where('slug', 'acme-demo')->firstOrFail();
        $this->adminA = User::where('email', 'admin@acme-demo.test')->firstOrFail();
        $this->recruiterA = User::where('email', 'recruiter@acme-demo.test')->firstOrFail();
        $this->managerA = User::where('email', 'manager@acme-demo.test')->firstOrFail();
        $this->hrValidatorA = User::where('email', 'hr.validator@acme-demo.test')->firstOrFail();
        $this->hrApproverA = User::where('email', 'hr.approver@acme-demo.test')->firstOrFail();
    }

    public function test_default_laravel_welcome_scaffold_is_removed_and_branded_error_views_exist(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/welcome.blade.php'));

        foreach (['layout', '403', '404', '409', '419', '429', '500', '503'] as $errorView) {
            $this->assertFileExists(resource_path("views/errors/{$errorView}.blade.php"));
        }

        // Verify 403 renders the branded enterprise error layout with safe navigation
        $forbiddenResp = $this->actingAs($this->recruiterA)
            ->withSession(['active_tenant_id' => $this->tenantA->id])
            ->get('/platformadmin');

        $forbiddenResp->assertStatus(403)
            ->assertSee('Access Restricted')
            ->assertSee('Return to Workspace')
            ->assertSee('enterprise-theme.css');

        // Verify 404 renders the branded enterprise error layout
        $notFoundResp = $this->get('/non-existent-enterprise-route-404-check');
        $notFoundResp->assertStatus(404)
            ->assertSee('Requested Resource Not Found')
            ->assertSee('Explore Open Roles');
    }

    public function test_public_career_portal_and_legal_pages_contain_no_internal_storage_or_developer_identifiers(): void
    {
        $job = JobPosting::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->firstOrFail();

        $portalResp = $this->get('/');
        $portalResp->assertOk()
            ->assertDontSee('DETERMINISTIC_FAKE')
            ->assertDontSee('urap-deterministic-v1')
            ->assertDontSee('Laravel v');

        $jobResp = $this->get("/{$this->tenantA->slug}/jobs/{$job->slug}");
        $jobResp->assertOk()
            ->assertSee($job->title)
            ->assertSee('AI-Assisted Resume Pre-Fill (Optional)')
            ->assertDontSee('DETERMINISTIC_FAKE')
            ->assertDontSee('urap-deterministic-v1');

        foreach (['privacy-policy', 'terms-of-use', 'cookie-policy'] as $slug) {
            $legalResp = $this->get("/legal/{$slug}");
            $legalResp->assertOk()
                ->assertDontSee('local_private')
                ->assertDontSee('tringers_cookie_consent');
        }
    }

    public function test_candidate_and_recruiter_views_present_friendly_engine_names_and_no_raw_json_or_spec_codes(): void
    {
        $candidate = Candidate::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->firstOrFail();
        $application = Application::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->firstOrFail();
        $requisition = Requisition::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->firstOrFail();

        // Upload and parse a resume for the candidate so the provenance panel renders
        $resumeFile = UploadedFile::fake()->createWithContent(
            'alex_rivera_resume.txt',
            "Alex Rivera\nEmail: alex.rivera@example.com\nRole: Principal Distributed Systems Architect\nExperience: 9 years\nSkills: PHP 8.5, Laravel 13, Redis"
        );

        $this->actingAs($this->recruiterA)
            ->withSession(['active_tenant_id' => $this->tenantA->id])
            ->post("/candidates/{$candidate->id}/documents", [
                'document_type' => 'RESUME',
                'document' => $resumeFile,
            ])
            ->assertRedirect();

        // Inspect Candidate CRM show page: must show friendly engine name and Schema v1.0, never DETERMINISTIC_FAKE or urap-deterministic-v1
        $candShow = $this->actingAs($this->recruiterA)
            ->withSession(['active_tenant_id' => $this->tenantA->id])
            ->get("/candidates/{$candidate->id}");

        $candShow->assertOk()
            ->assertSee('Built-In Structured Rule Analyzer')
            ->assertSee('Governed Rule Engine v1.0')
            ->assertSee('Schema v1.0')
            ->assertDontSee('Provider: DETERMINISTIC_FAKE')
            ->assertDontSee('(urap-deterministic-v1)');

        // Inspect Evaluations, Offers, HR Handover, AI Gateway, Dynamics, and IAM views for absence of raw JSON dumps and spec rule codes
        $routesToAudit = [
            '/dashboard',
            '/organization',
            '/requisitions',
            "/requisitions/{$requisition->id}",
            '/jobs',
            '/candidates',
            '/applications',
            "/applications/{$application->id}",
            '/evaluations',
            '/offers',
            '/ai',
            '/integrations/api',
            '/integrations/dynamics',
            '/admin/tenants-iam',
            '/admin/audit-events',
            '/analytics',
        ];

        foreach ($routesToAudit as $uri) {
            $resp = $this->actingAs($this->adminA)
                ->withSession(['active_tenant_id' => $this->tenantA->id])
                ->get($uri);

            $resp->assertOk()
                ->assertDontSee('Per Specification §')
                ->assertDontSee('HRH-01 Enforced:')
                ->assertDontSee('Revise Material Terms (HRH-04)')
                ->assertDontSee('{"scope_mode":"tenant_scoped"}');
        }

        // Also check HR Handover screen as HR Approver
        $hrHandoverResp = $this->actingAs($this->hrApproverA)
            ->withSession(['active_tenant_id' => $this->tenantA->id])
            ->get('/hr-handover');

        $hrHandoverResp->assertOk()
            ->assertSee('Mandatory HR Validation & Employee Handover Safety Gate')
            ->assertDontSee('Test Ambiguous Timeout Recovery (HRH-06)');
    }
}
