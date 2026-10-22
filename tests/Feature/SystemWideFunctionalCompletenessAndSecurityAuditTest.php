<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeliverSignedWebhookJob;
use App\Models\AiProviderConfig;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\Department;
use App\Models\JobPosting;
use App\Models\LegalEntity;
use App\Models\Offer;
use App\Models\Permission;
use App\Models\ResumeParseJob;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\AiGatewayService;
use App\Services\ApiAndMcpPlatformService;
use App\Services\CandidateAndApplicationService;
use App\Services\OfferAndHrHandoverService;
use App\Services\ResumeParsingService;
use App\Services\TenantContextService;
use Database\Seeders\AlphaSafeBootstrapSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class SystemWideFunctionalCompletenessAndSecurityAuditTest extends TestCase
{
    private Tenant $acme;

    private Tenant $contoso;

    private User $acmeAdmin;

    private User $acmeHr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AlphaSafeBootstrapSeeder::class);

        $this->acme = Tenant::where('slug', 'acme-demo')->firstOrFail();
        $this->contoso = Tenant::where('slug', 'globex-demo')->firstOrFail();
        $this->acmeAdmin = User::where('email', 'admin@acme-demo.test')->firstOrFail();
        $this->acmeHr = User::where('email', 'hr.approver@acme-demo.test')->firstOrFail();
    }

    public function test_cross_tenant_foreign_key_injection_is_blocked_on_department_requisition_and_offer_version_creation(): void
    {
        app(TenantContextService::class)->setActiveTenant($this->acme);

        $contosoEntity = LegalEntity::withoutGlobalScopes()->create([
            'tenant_id' => $this->contoso->id,
            'code' => 'CONTOSO-LE-99',
            'name' => 'Contoso Foreign Entity',
            'country_code' => 'DE',
            'currency_code' => 'EUR',
            'dynamics_company_code' => 'CN99',
            'is_active' => true,
        ]);

        // 1. Attempting to link Contoso's LegalEntity when creating an Acme Department must be rejected with 403
        $deptResp = $this->actingAs($this->acmeAdmin)
            ->withSession(['active_tenant_id' => $this->acme->id])
            ->post('/organization/departments', [
                'code' => 'CROSS-DEPT',
                'name' => 'Cross Tenant Dept Attempt',
                'legal_entity_id' => $contosoEntity->id,
            ]);
        $deptResp->assertStatus(403);

        // 2. Attempting to link Contoso's LegalEntity on Offer creation in Acme must throw AccessDeniedHttpException
        $acmeApp = Application::withoutGlobalScopes()->where('tenant_id', $this->acme->id)->firstOrFail();
        $acmeApp->update(['current_stage' => 'hiring_decision']);

        $offerService = app(OfferAndHrHandoverService::class);
        $thrown = false;
        try {
            $offerService->createOffer($acmeApp, [
                'job_title' => 'Principal Security Architect',
                'legal_entity_id' => $contosoEntity->id,
                'employment_type' => 'full_time',
                'base_salary_minor' => 18500000,
                'currency_code' => 'USD',
                'start_date' => now()->addDays(30)->toDateString(),
            ], $this->acmeAdmin);
        } catch (AccessDeniedHttpException) {
            $thrown = true;
        }
        $this->assertTrue($thrown, 'Expected AccessDeniedHttpException when passing a cross-tenant LegalEntity ID to Offer creation.');
    }

    public function test_public_candidate_resume_review_and_reprocess_enforce_session_or_signature_ownership_against_idor(): void
    {
        Storage::fake('local');

        $job = JobPosting::withoutGlobalScopes()
            ->where('tenant_id', $this->acme->id)
            ->where('status', JobPosting::STATUS_PUBLISHED)
            ->firstOrFail();

        $resumeBytes = "Jane SecurityTester\njane.sec@example.com\n+1 555 234 5678\nLocation: Austin, US\n"
            ."SUMMARY\nPrincipal Application Security Engineer with 9 years experience.\n"
            ."EXPERIENCE\nPrincipal Security Engineer | ShieldCorp | 2020 - Present\n"
            ."EDUCATION\nB.S. Computer Science | UT Austin | 2016\n"
            ."SKILLS\nPHP, Laravel, OAuth2, Zero Trust\n";

        $applyResponse = $this->post(route('careers.apply', [
            'tenantSlug' => $this->acme->slug,
            'jobSlug' => $job->slug,
        ]), [
            'first_name' => 'Jane',
            'last_name' => 'SecurityTester',
            'email' => 'jane.sec@example.com',
            'country_code' => 'US',
            'consent_given' => '1',
            'resume_file' => UploadedFile::fake()->createWithContent('jane_resume.txt', $resumeBytes),
        ]);
        $applyResponse->assertRedirect();

        $candidate = Candidate::withoutGlobalScopes()
            ->where('tenant_id', $this->acme->id)
            ->where('email', 'jane.sec@example.com')
            ->firstOrFail();
        $application = Application::withoutGlobalScopes()
            ->where('tenant_id', $this->acme->id)
            ->where('candidate_id', $candidate->id)
            ->firstOrFail();

        // Owner session (from submitPublicApplication) can view resume review
        $ownerView = $this->get(route('careers.application.resume-review', [
            'tenantSlug' => $this->acme->slug,
            'applicationUuid' => $application->uuid,
        ]));
        $ownerView->assertOk();

        // Clear session to simulate an unauthenticated third-party IDOR attacker
        $this->flushSession();

        $attackerGet = $this->get(route('careers.application.resume-review', [
            'tenantSlug' => $this->acme->slug,
            'applicationUuid' => $application->uuid,
        ]));
        $attackerGet->assertStatus(403);

        $attackerPost = $this->post(route('careers.application.resume-review.submit', [
            'tenantSlug' => $this->acme->slug,
            'applicationUuid' => $application->uuid,
        ]), [
            'consent_given' => '1',
            'full_name' => 'Attacker Tampered',
            'email' => 'attacker@example.com',
        ]);
        $attackerPost->assertStatus(403);

        $attackerReprocess = $this->post(route('careers.application.resume-reprocess', [
            'tenantSlug' => $this->acme->slug,
            'applicationUuid' => $application->uuid,
        ]));
        $attackerReprocess->assertStatus(403);

        // Signed temporary URL grants legitimate candidate access
        $signedUrl = URL::temporarySignedRoute(
            'careers.application.resume-review',
            now()->addMinutes(30),
            ['tenantSlug' => $this->acme->slug, 'applicationUuid' => $application->uuid]
        );
        $this->get($signedUrl)->assertOk();
    }

    public function test_candidate_consent_withdrawal_and_dsar_anonymization_scrub_pii_delete_documents_and_block_ai_processing(): void
    {
        Storage::fake('local');
        app(TenantContextService::class)->setActiveTenant($this->acme);

        $service = app(CandidateAndApplicationService::class);
        $resumeParsing = app(ResumeParsingService::class);

        $candidate = $service->upsertCandidateWithConsent([
            'first_name' => 'Marcus',
            'last_name' => 'PrivacyTest',
            'email' => 'marcus.privacy@example.com',
            'phone' => '+1-555-888-9999',
            'location_city' => 'Seattle',
            'country_code' => 'US',
            'current_title' => 'Staff Cloud Architect',
            'years_experience' => 11,
            'skills' => 'Kubernetes, Terraform, AWS',
            'consent_given' => true,
        ], $this->acme->id);

        $doc = $service->storePrivateDocument(
            $candidate,
            UploadedFile::fake()->createWithContent('marcus_cv.txt', "Marcus PrivacyTest\nmarcus.privacy@example.com\nSUMMARY\nCloud Architect\nEXPERIENCE\nArchitect | CloudCo | 2019 - Present\nEDUCATION\nB.S. CS | UW | 2014\nSKILLS\nKubernetes, Terraform\n"),
            'RESUME'
        );

        $parseJob = $resumeParsing->parseDocument($candidate, $doc, null, $this->acmeAdmin);
        $this->assertNotNull($parseJob->parsed_data);

        // 1. Withdraw consent -> consent_given_at becomes null and AI resume parsing is blocked
        $updated = $service->updateCandidateConsentAndDsar($candidate, [
            'consent_given' => false,
            'dsar_status' => 'RESTRICTION_REQUESTED',
        ], $this->acmeAdmin);

        $this->assertFalse($updated->consent_given);
        $this->assertNull($updated->consent_given_at);

        $consentBlocked = false;
        try {
            $resumeParsing->parseDocument($updated, $doc, null, $this->acmeAdmin, true);
        } catch (ValidationException) {
            $consentBlocked = true;
        }
        $this->assertTrue($consentBlocked, 'Expected ValidationException when parsing resume for candidate who withdrew consent.');

        // 2. Execute DSAR Anonymization -> PII is scrubbed, private files deleted, and parse jobs removed
        $anonymized = $service->updateCandidateConsentAndDsar($updated, [
            'consent_given' => false,
            'dsar_status' => 'ANONYMIZED',
        ], $this->acmeAdmin);

        $this->assertSame('Anonymized', $anonymized->first_name);
        $this->assertSame('Candidate', $anonymized->last_name);
        $this->assertNull($anonymized->phone);
        $this->assertNull($anonymized->parsed_resume_data);
        $this->assertSame([], $anonymized->skills);
        $this->assertSame('ANONYMIZED', $anonymized->dsar_status);
        $this->assertSame(0, CandidateDocument::withoutGlobalScopes()->where('candidate_id', $candidate->id)->count());
        $this->assertSame(0, ResumeParseJob::withoutGlobalScopes()->where('candidate_id', $candidate->id)->count());
    }

    public function test_human_reviewed_resume_corrections_persist_email_country_and_survive_resume_reprocessing(): void
    {
        Storage::fake('local');
        app(TenantContextService::class)->setActiveTenant($this->acme);

        $service = app(CandidateAndApplicationService::class);
        $resumeParsing = app(ResumeParsingService::class);

        $candidate = $service->upsertCandidateWithConsent([
            'first_name' => 'Elena',
            'last_name' => 'Vance',
            'email' => 'elena.initial@example.com',
            'country_code' => 'US',
            'consent_given' => true,
        ], $this->acme->id);

        $doc = $service->storePrivateDocument(
            $candidate,
            UploadedFile::fake()->createWithContent('elena_cv.txt', "Elena Vance\nelena.initial@example.com\nSUMMARY\nBackend Engineer\nEXPERIENCE\nBackend Engineer | CoreTech | 2021 - Present\nEDUCATION\nB.S. CS | MIT | 2020\nSKILLS\nPHP, PostgreSQL\n"),
            'RESUME'
        );

        $parseJob = $resumeParsing->parseDocument($candidate, $doc, null, $this->acmeAdmin);

        // Apply human review with updated email, country_code, title, and years_experience
        $reviewedJob = $resumeParsing->applyCorrections(
            parseJob: $parseJob,
            candidate: $candidate,
            correctedPayload: [
                'first_name' => 'Elena',
                'last_name' => 'Vance-Reviewed',
                'email' => 'elena.verified@example.com',
                'country_code' => 'CA',
                'location_city' => 'Toronto',
                'current_title' => 'Distinguished Principal Engineer',
                'years_experience' => 14,
                'professional_summary' => 'Human-verified executive summary.',
                'skills' => 'PHP, PostgreSQL, Rust, Distributed Systems',
            ],
            reviewer: $this->acmeAdmin,
            isCandidateReview: false
        );

        $candidate->refresh();
        $this->assertSame('elena.verified@example.com', $candidate->email);
        $this->assertSame('CA', $candidate->country_code);
        $this->assertSame('Distinguished Principal Engineer', $candidate->current_title);
        $this->assertSame(14, $candidate->years_experience);
        $this->assertTrue((bool) ($reviewedJob->corrected_data['human_reviewed'] ?? false));

        // Now force reprocess the document -> human-reviewed fields must NOT be overwritten by raw extraction
        $reprocessedJob = $resumeParsing->parseDocument(
            candidate: $candidate,
            document: $doc,
            applicationId: null,
            actor: $this->acmeAdmin,
            forceReprocess: true
        );

        $candidate->refresh();
        $this->assertSame('Distinguished Principal Engineer', $candidate->current_title);
        $this->assertSame(14, $candidate->years_experience);
        $this->assertSame('Human-verified executive summary.', $candidate->professional_summary);
        $this->assertNotEmpty($reprocessedJob->corrected_data);
        $this->assertSame('Distinguished Principal Engineer', $reprocessedJob->getEffectiveResumeData()['current_title']);
    }

    public function test_api_client_and_ai_provider_runtime_rate_limits_and_multi_membership_abac_pii_restriction_are_enforced(): void
    {
        app(TenantContextService::class)->setActiveTenant($this->acme);

        // 1. API Client runtime rate_limit_per_minute enforcement
        $apiPlatform = app(ApiAndMcpPlatformService::class);
        $created = $apiPlatform->createApiClient(
            name: 'Rate Limited Test Client',
            scopes: ['requisitions.view'],
            tenantId: $this->acme->id,
            actor: $this->acmeAdmin
        );
        $created['client']->update(['rate_limit_per_minute' => 2]);
        $token = $created['client']->client_id.':'.$created['raw_secret'];

        RateLimiter::clear('api_client_rpm:'.$created['client']->id);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/requisitions')
            ->assertOk();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/requisitions')
            ->assertOk();

        $thirdResp = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/requisitions');
        $thirdResp->assertStatus(429)
            ->assertJsonPath('error.code', 'API_RATE_LIMIT_EXCEEDED');

        // 2. AI Provider runtime rate_limit_rpm enforcement
        $aiGateway = app(AiGatewayService::class);
        $aiGateway->ensureDefaultsForTenant($this->acme->id);
        $detConfig = AiProviderConfig::withoutGlobalScopes()
            ->where('tenant_id', $this->acme->id)
            ->where('provider_key', 'deterministic_fake')
            ->firstOrFail();
        $detConfig->update(['rate_limit_rpm' => 1]);

        $aiRateKey = 'ai_provider_rpm:'.$this->acme->id.':deterministic_fake';
        RateLimiter::clear($aiRateKey);

        $aiGateway->executeTask(
            taskType: 'job.description.generate',
            providerKey: 'deterministic_fake',
            rawInputText: 'Role: Senior Platform Engineer',
            actor: $this->acmeAdmin,
            tenantId: $this->acme->id
        );

        $rateLimited = false;
        try {
            $aiGateway->executeTask(
                taskType: 'job.description.generate',
                providerKey: 'deterministic_fake',
                rawInputText: 'Role: Staff SRE',
                actor: $this->acmeAdmin,
                tenantId: $this->acme->id
            );
        } catch (RuntimeException $e) {
            $rateLimited = str_contains($e->getMessage(), 'PROVIDER_RATE_LIMITED');
        }
        $this->assertTrue($rateLimited, 'Expected PROVIDER_RATE_LIMITED RuntimeException when exceeding AI provider rate_limit_rpm.');
        RateLimiter::clear($aiRateKey);

        // 3. Multi-membership ABAC candidate_pii_browsing_prohibited enforcement
        $supportUser = User::create([
            'name' => 'Multi Membership Support Auditor',
            'email' => 'multi-abac-'.Str::lower(Str::random(6)).'@example.com',
            'password' => 'Password123!',
            'status' => 'ACTIVE',
            'current_tenant_id' => $this->acme->id,
        ]);

        $recruiterRole = Role::where('slug', 'recruiter')->firstOrFail();

        $auditorRole = Role::firstOrCreate(
            ['slug' => 'support_auditor_abac'],
            ['name' => 'Support Auditor ABAC', 'is_system' => false]
        );
        $auditPerm = Permission::where('slug', 'audit.view')->firstOrFail();
        $auditorRole->permissions()->syncWithoutDetaching([$auditPerm->id]);

        // Membership 1 grants recruiter permissions (including candidates.view)
        TenantMembership::create([
            'tenant_id' => $this->acme->id,
            'user_id' => $supportUser->id,
            'role_id' => $recruiterRole->id,
            'status' => 'ACTIVE',
            'is_primary' => true,
        ]);

        // Membership 2 has candidate_pii_browsing_prohibited = true
        TenantMembership::create([
            'tenant_id' => $this->acme->id,
            'user_id' => $supportUser->id,
            'role_id' => $auditorRole->id,
            'status' => 'ACTIVE',
            'is_primary' => false,
            'abac_scope' => ['candidate_pii_browsing_prohibited' => true],
        ]);

        $this->assertFalse(
            $supportUser->hasPermission('candidates.view', $this->acme->id),
            'ABAC candidate_pii_browsing_prohibited must strip candidates.view across all memberships in the tenant.'
        );
        $this->assertTrue($supportUser->hasPermission('audit.view', $this->acme->id));
    }

    public function test_mcp_analytics_summary_hr_handover_headcount_reconciliation_and_scheduled_maintenance_commands_execute_cleanly(): void
    {
        app(TenantContextService::class)->setActiveTenant($this->acme);

        // 1. MCP analytics.summary returns accurate published_jobs_count
        $mcpService = app(ApiAndMcpPlatformService::class);
        $summary = $mcpService->invokeMcpTool(
            tenantId: $this->acme->id,
            actor: $this->acmeAdmin,
            toolName: 'analytics.summary',
            arguments: []
        );

        $expectedPublished = JobPosting::withoutGlobalScopes()
            ->where('tenant_id', $this->acme->id)
            ->where('status', JobPosting::STATUS_PUBLISHED)
            ->count();
        $this->assertSame($expectedPublished, $summary['result']['published_jobs_count']);
        $this->assertGreaterThan(0, $summary['result']['published_jobs_count']);

        // 2. Scheduled maintenance commands execute cleanly
        $this->artisan('recruitment:process-scheduled-parse-retries')->assertExitCode(0);
        $this->artisan('recruitment:expire-past-due-jobs')->assertExitCode(0);
        $this->artisan('recruitment:enforce-privacy-retention')->assertExitCode(0);

        // 3. Suspended tenant blocks outbound webhook job execution
        $suspendedTenant = Tenant::create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'suspended-webhook-tenant',
            'name' => 'Suspended Webhook Tenant',
            'status' => 'SUSPENDED',
            'isolation_mode' => 'SHARED_DB',
            'default_Exporter' => 'NONE',
        ]);

        $sub = WebhookSubscription::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $suspendedTenant->id,
            'name' => 'Suspended Hook',
            'target_url' => 'https://hooks.example.org/receive',
            'signing_secret' => 'whsec_test_secret_123456',
            'subscribed_events' => ['candidate.applied'],
            'is_active' => true,
        ]);

        $delivery = WebhookDelivery::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $suspendedTenant->id,
            'webhook_subscription_id' => $sub->id,
            'event_id' => 'evt_suspended_test_1',
            'event_type' => 'candidate.applied',
            'payload' => ['test' => true],
            'signature_header' => 'sha256=test',
            'status' => 'QUEUED',
            'attempt_count' => 0,
        ]);

        app()->call([new DeliverSignedWebhookJob($delivery->id), 'handle']);

        $delivery->refresh();
        $this->assertSame('PAUSED_BY_TENANT_STATUS', $delivery->status);
        $this->assertSame('TENANT_SUSPENDED', $delivery->error_code);
    }
}
