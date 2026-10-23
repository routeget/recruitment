<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessResumeParsingJob;
use App\Models\AiTaskRun;
use App\Models\Application;
use App\Models\ApplicationStageEvent;
use App\Models\AuditEvent;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\HrHandover;
use App\Models\HrHandoverApproval;
use App\Models\HrValidationCase;
use App\Models\JobPosting;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\ResumeParseJob;
use App\Models\Tenant;
use App\Models\TransactionalOutboxEvent;
use App\Models\User;
use App\Services\AiGatewayService;
use App\Services\CandidateAndApplicationService;
use App\Services\EvaluationAndDecisionService;
use App\Services\ResumeParsingService;
use App\Services\TenantContextService;
use Database\Seeders\AlphaSafeBootstrapSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class PostReleaseAssurancePa01Test extends TestCase
{
    private Tenant $acme;

    private Tenant $globex;

    private User $acmeAdmin;

    private User $acmeRecruiter;

    private User $globexAdmin;

    private JobPosting $acmeJob;

    private JobPosting $globexJob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AlphaSafeBootstrapSeeder::class);

        $this->acme = Tenant::query()->where('slug', 'acme-demo')->firstOrFail();
        $this->globex = Tenant::query()->where('slug', 'globex-demo')->firstOrFail();

        $this->acmeAdmin = User::query()->where('email', 'admin@acme-demo.test')->firstOrFail();
        $this->acmeRecruiter = User::query()->where('email', 'recruiter@acme-demo.test')->firstOrFail();
        $this->globexAdmin = User::query()->where('email', 'admin@globex-demo.test')->firstOrFail();

        $this->acmeJob = JobPosting::withoutGlobalScopes()
            ->where('tenant_id', $this->acme->id)
            ->where('status', JobPosting::STATUS_PUBLISHED)
            ->firstOrFail();

        $this->globexJob = JobPosting::withoutGlobalScopes()
            ->where('tenant_id', $this->globex->id)
            ->where('status', JobPosting::STATUS_PUBLISHED)
            ->firstOrFail();
    }

    /**
     * @return array{candidate: Candidate, document: CandidateDocument, application: Application, parseJob: ResumeParseJob}
     */
    private function createCandidateWithParsedResumeAndMatch(
        Tenant $tenant,
        JobPosting $job,
        User $actor,
        string $firstName = 'Sophia',
        string $lastName = 'Lindqvist',
        string $email = 'sophia.lindqvist@example.com'
    ): array {
        app(TenantContextService::class)->setActiveTenant($tenant);
        $service = app(CandidateAndApplicationService::class);
        $resumeParsing = app(ResumeParsingService::class);

        $candidate = $service->upsertCandidateWithConsent([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => '+1-415-555-0192',
            'location_city' => 'San Francisco',
            'country_code' => 'US',
            'current_title' => 'Principal Security Architect',
            'years_experience' => 12,
            'skills' => 'Zero Trust, Kubernetes, Cryptography, Go',
            'consent_given' => true,
        ], $tenant->id, $actor);

        $application = $service->submitApplication($job, $candidate, [
            'cover_note' => "Cover note from {$firstName} {$lastName} ({$email}) with private career context.",
            'answers' => ['work_authorization' => "Authorized for {$firstName} {$lastName}"],
        ], $actor);

        $resumeContent = "{$firstName} {$lastName}\n{$email} | +1-415-555-0192 | San Francisco, CA\n"
            ."SUMMARY\nPrincipal Security Architect with 12 years building Zero Trust platforms.\n"
            ."EXPERIENCE\nPrincipal Security Architect | CipherCloud Corp | 2020 - Present\n"
            ."EDUCATION\nM.S. Computer Science | Stanford University | 2014\n"
            ."SKILLS\nZero Trust, Kubernetes, Cryptography, Go\n";

        $document = $service->storePrivateDocument(
            $candidate,
            UploadedFile::fake()->createWithContent('sophia_resume.txt', $resumeContent),
            'RESUME',
            $application->id,
            $actor
        );

        $parseJob = $resumeParsing->parseDocument($candidate, $document, $application->id, $actor);
        $parseJob = $resumeParsing->applyCorrections(
            parseJob: $parseJob,
            candidate: $candidate,
            correctedPayload: [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => '+1-415-555-0192',
                'location_city' => 'San Francisco',
                'country_code' => 'US',
                'current_title' => 'Principal Security Architect',
                'years_experience' => 12,
                'professional_summary' => "Reviewed summary for {$firstName} {$lastName}.",
                'skills' => 'Zero Trust, Kubernetes, Cryptography, Go',
            ],
            reviewer: $actor,
            isCandidateReview: false
        );

        $application = $resumeParsing->evaluateApplicationMatch($application->fresh(['jobPosting']), $candidate->fresh(), $actor);

        return [
            'candidate' => $candidate->fresh(),
            'document' => $document->fresh(),
            'application' => $application->fresh(),
            'parseJob' => $parseJob->fresh(),
        ];
    }

    public function test_01_dsar_anonymization_scrubs_associated_resume_parse_job_data(): void
    {
        Storage::fake('local');
        $fixture = $this->createCandidateWithParsedResumeAndMatch($this->acme, $this->acmeJob, $this->acmeAdmin);

        $parseJob = $fixture['parseJob'];
        $this->assertNotNull($parseJob->parsed_data);
        $this->assertNotNull($parseJob->corrected_data);
        $this->assertNotNull($parseJob->candidate_document_id);

        app(CandidateAndApplicationService::class)->updateCandidateConsentAndDsar($fixture['candidate'], [
            'consent_given' => false,
            'dsar_status' => 'ANONYMIZED',
        ], $this->acmeAdmin);

        $scrubbedJob = ResumeParseJob::withoutGlobalScopes()->findOrFail($parseJob->id);
        $this->assertNull($scrubbedJob->candidate_document_id);
        $this->assertNull($scrubbedJob->parsed_data);
        $this->assertNull($scrubbedJob->corrected_data);
        $this->assertSame([], $scrubbedJob->validation_errors);
        $this->assertSame([], $scrubbedJob->missing_fields);
        $this->assertSame([], $scrubbedJob->security_flags);
        $this->assertSame(0, $scrubbedJob->extracted_text_length);
        $this->assertSame(ResumeParseJob::STATUS_FAILED, $scrubbedJob->status);
        $this->assertSame('DSAR_ANONYMIZED', $scrubbedJob->error_code);
        $this->assertSame('Candidate data anonymized per DSAR request.', $scrubbedJob->error_message);
        $this->assertNull($scrubbedJob->next_retry_at);
        $this->assertSame(0, CandidateDocument::withoutGlobalScopes()->where('candidate_id', $fixture['candidate']->id)->count());
    }

    public function test_02_associated_ai_task_run_structured_output_no_longer_contains_candidate_pii(): void
    {
        Storage::fake('local');
        $fixture = $this->createCandidateWithParsedResumeAndMatch($this->acme, $this->acmeJob, $this->acmeAdmin);

        $extractRun = AiTaskRun::withoutGlobalScopes()->findOrFail($fixture['parseJob']->ai_task_run_id);
        $matchRunId = (int) ($fixture['application']->ai_match_data['ai_task_run_id'] ?? 0);
        $matchRun = AiTaskRun::withoutGlobalScopes()->findOrFail($matchRunId);

        $this->assertStringContainsString('Sophia', (string) json_encode($extractRun->structured_output));
        $this->assertNotEmpty($matchRun->structured_output);

        app(CandidateAndApplicationService::class)->updateCandidateConsentAndDsar($fixture['candidate'], [
            'consent_given' => false,
            'dsar_status' => 'ANONYMIZED',
        ], $this->acmeAdmin);

        foreach ([$extractRun->fresh(), $matchRun->fresh()] as $scrubbedRun) {
            $this->assertNotNull($scrubbedRun);
            $this->assertTrue((bool) ($scrubbedRun->structured_output['redacted'] ?? false));
            $this->assertSame('DSAR_ANONYMIZED', $scrubbedRun->structured_output['reason'] ?? null);

            $encoded = (string) json_encode($scrubbedRun->structured_output);
            $this->assertStringNotContainsString('Sophia', $encoded);
            $this->assertStringNotContainsString('Lindqvist', $encoded);
            $this->assertStringNotContainsString('sophia.lindqvist@example.com', $encoded);
            $this->assertStringNotContainsString('415-555-0192', $encoded);
            $this->assertStringNotContainsString('CipherCloud', $encoded);
            $this->assertStringNotContainsString('Stanford', $encoded);
        }
    }

    public function test_03_associated_ai_task_run_redacted_input_summary_is_scrubbed_appropriately(): void
    {
        Storage::fake('local');
        $fixture = $this->createCandidateWithParsedResumeAndMatch($this->acme, $this->acmeJob, $this->acmeAdmin);

        $extractRun = AiTaskRun::withoutGlobalScopes()->findOrFail($fixture['parseJob']->ai_task_run_id);
        $matchRunId = (int) ($fixture['application']->ai_match_data['ai_task_run_id'] ?? 0);
        $matchRun = AiTaskRun::withoutGlobalScopes()->findOrFail($matchRunId);

        $originalExtractHash = $extractRun->input_hash;
        $this->assertStringContainsString('Sophia', (string) $extractRun->redacted_input_summary);

        app(CandidateAndApplicationService::class)->updateCandidateConsentAndDsar($fixture['candidate'], [
            'consent_given' => false,
            'dsar_status' => 'ANONYMIZED',
        ], $this->acmeAdmin);

        $extractRun->refresh();
        $matchRun->refresh();

        $this->assertSame('[REDACTED_DSAR_ANONYMIZED]', $extractRun->redacted_input_summary);
        $this->assertSame('[REDACTED_DSAR_ANONYMIZED]', $matchRun->redacted_input_summary);
        $this->assertNotSame($originalExtractHash, $extractRun->input_hash);
        $this->assertStringNotContainsString('Sophia', (string) $extractRun->redacted_input_summary);
        $this->assertStringNotContainsString('Principal Security Architect', (string) $matchRun->redacted_input_summary);
    }

    public function test_04_relevant_linked_records_are_checked_and_handled_according_to_policy(): void
    {
        Storage::fake('local');
        $fixture = $this->createCandidateWithParsedResumeAndMatch($this->acme, $this->acmeJob, $this->acmeAdmin);
        $application = $fixture['application'];
        $evalService = app(EvaluationAndDecisionService::class);

        $screening = $evalService->recordScreeningEvaluation($application, [
            'overall_score' => 91,
            'recommendation' => 'STRONG_YES',
            'notes' => 'Sophia Lindqvist demonstrated strong Zero Trust architecture experience at CipherCloud.',
        ], $this->acmeRecruiter);

        $assessment = $evalService->recordAssessment($application, [
            'title' => 'Security Architecture Case Study',
            'assessment_type' => 'TECHNICAL',
            'score' => 94,
            'max_score' => 100,
            'accommodations_notes' => 'Candidate Sophia requested extra 15 minutes for medical ergonomic setup.',
        ], $this->acmeRecruiter);

        $interview = $evalService->scheduleInterview($application, [
            'stage_name' => 'Panel Architecture Review',
            'interview_mode' => 'VIDEO',
            'scheduled_start_at' => now()->addDay()->toDateTimeString(),
            'scheduled_end_at' => now()->addDay()->addHour()->toDateTimeString(),
            'location_or_link' => 'https://meet.tringers.test/sophia-lindqvist-private-room',
            'interviewer_user_id' => $this->acmeAdmin->id,
            'interview_pack_notes' => 'Focus on Sophia Lindqvist Stanford thesis and CipherCloud tenure.',
        ], $this->acmeRecruiter);

        $scorecard = $evalService->submitScorecard($interview, [
            'overall_rating' => 5,
            'recommendation' => 'STRONG_HIRE',
            'strengths_evidence' => 'Sophia Lindqvist articulated deep cryptographic key rotation design.',
            'concerns_evidence' => 'Sophia mentioned relocation timing from San Francisco.',
        ], $this->acmeAdmin);

        $decision = $evalService->recordHiringDecision($application->fresh(), [
            'decision' => 'APPROVE_FOR_OFFER',
            'rationale' => 'Approved Sophia Lindqvist based on Stanford M.S. and panel consensus.',
        ], $this->acmeAdmin);

        // Also create a pending (non-handed-over) HR validation case + approval + pending handover
        $offer = Offer::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->acme->id,
            'application_id' => $application->id,
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
        $offerVersion = OfferVersion::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->acme->id,
            'offer_id' => $offer->id,
            'version_number' => 1,
            'job_title' => 'Principal Security Architect',
            'employment_type' => 'full_time',
            'base_salary_minor' => 21000000,
            'currency_code' => 'USD',
            'start_date' => now()->addMonth()->format('Y-m-d'),
            'probation_months' => 3,
            'snapshot_hash' => hash('sha256', 'v1'),
            'created_by_user_id' => $this->acmeAdmin->id,
            'created_at' => now(),
        ]);
        $validationCase = HrValidationCase::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->acme->id,
            'application_id' => $application->id,
            'offer_version_id' => $offerVersion->id,
            'status' => 'approved_for_handover',
            'checklist_version' => 1,
        ]);
        $pendingApproval = HrHandoverApproval::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->acme->id,
            'hr_validation_case_id' => $validationCase->id,
            'approver_user_id' => $this->acmeAdmin->id,
            'decision' => 'approved',
            'comment' => 'Approved for Sophia Lindqvist',
            'approved_payload_snapshot' => [
                'candidate_email' => 'sophia.lindqvist@example.com',
                'candidate_first_name' => 'Sophia',
                'candidate_last_name' => 'Lindqvist',
            ],
            'approved_payload_hash' => hash('sha256', 'pending-approval'),
            'decided_at' => now(),
        ]);
        $pendingHandover = HrHandover::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->acme->id,
            'hr_validation_case_id' => $validationCase->id,
            'approval_id' => $pendingApproval->id,
            'idempotency_key' => 'pending-handover-sophia-1',
            'status' => 'queued',
            'target_system' => 'dynamics_365_finance_hr',
            'payload_snapshot' => [
                'candidate_email' => 'sophia.lindqvist@example.com',
                'candidate_first_name' => 'Sophia',
                'candidate_last_name' => 'Lindqvist',
            ],
            'payload_hash' => hash('sha256', 'pending-handover'),
        ]);
        $pendingOutbox = TransactionalOutboxEvent::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->acme->id,
            'aggregate_type' => 'hr_handover',
            'aggregate_id' => $pendingHandover->id,
            'event_type' => 'hr.handover.approved_for_dispatch',
            'idempotency_key' => 'pending-handover-sophia-1',
            'payload' => [
                'candidate_email' => 'sophia.lindqvist@example.com',
                'candidate_first_name' => 'Sophia',
                'candidate_last_name' => 'Lindqvist',
            ],
            'status' => 'pending',
        ]);

        // Execute DSAR anonymization
        app(CandidateAndApplicationService::class)->updateCandidateConsentAndDsar($fixture['candidate'], [
            'consent_given' => false,
            'dsar_status' => 'ANONYMIZED',
        ], $this->acmeAdmin);

        $application->refresh();
        $this->assertNull($application->cover_note);
        $this->assertNull($application->answers);
        $this->assertNull($application->screening_notes);
        $this->assertNull($application->ai_match_data);
        $this->assertNull($application->rejection_reason);
        $this->assertNotNull($application->ai_match_score); // Non-personal numeric metric preserved

        $stageEvents = ApplicationStageEvent::withoutGlobalScopes()->where('application_id', $application->id)->get();
        foreach ($stageEvents as $event) {
            $this->assertSame('[REDACTED_DSAR_ANONYMIZED]', $event->notes);
            $this->assertNull($event->metadata);
        }

        $screening->refresh();
        $this->assertNull($screening->notes);
        $this->assertTrue((bool) ($screening->criteria_breakdown['redacted'] ?? false));
        $this->assertSame([], $screening->missing_evidence_flags);
        $this->assertSame(91.0, (float) $screening->overall_score);

        $assessment->refresh();
        $this->assertNull($assessment->accommodations_notes);
        $this->assertSame(94.0, (float) $assessment->score);

        $interview->refresh();
        $this->assertNull($interview->location_or_link);
        $this->assertNull($interview->interview_pack_notes);

        $scorecard->refresh();
        $this->assertSame('[REDACTED_DSAR_ANONYMIZED]', $scorecard->strengths_evidence);
        $this->assertNull($scorecard->concerns_evidence);
        $this->assertSame(5, $scorecard->overall_rating);

        $decision->refresh();
        $this->assertSame('[REDACTED_DSAR_ANONYMIZED]', $decision->rationale);
        $this->assertSame('APPROVE_FOR_OFFER', $decision->decision);

        $pendingApproval->refresh();
        $this->assertSame('invalidated', $pendingApproval->decision);
        $this->assertSame('Anonymized', $pendingApproval->approved_payload_snapshot['candidate_first_name']);

        $pendingHandover->refresh();
        $this->assertSame('cancelled', $pendingHandover->status);
        $this->assertSame('DSAR_ANONYMIZED', $pendingHandover->error_code);
        $this->assertSame('Anonymized', $pendingHandover->payload_snapshot['candidate_first_name']);

        $pendingOutbox->refresh();
        $this->assertSame('cancelled', $pendingOutbox->status);
        $this->assertSame('Anonymized', $pendingOutbox->payload['candidate_first_name']);
    }

    public function test_05_unrelated_candidates_and_tenants_records_remain_unchanged(): void
    {
        Storage::fake('local');

        $targetFixture = $this->createCandidateWithParsedResumeAndMatch(
            $this->acme,
            $this->acmeJob,
            $this->acmeAdmin,
            'Target',
            'EraseMe',
            'target.eraseme@example.com'
        );

        $sameTenantOtherFixture = $this->createCandidateWithParsedResumeAndMatch(
            $this->acme,
            $this->acmeJob,
            $this->acmeAdmin,
            'KeepMe',
            'SameTenant',
            'keepme.sametenant@example.com'
        );

        $otherTenantFixture = $this->createCandidateWithParsedResumeAndMatch(
            $this->globex,
            $this->globexJob,
            $this->globexAdmin,
            'KeepMe',
            'OtherTenant',
            'keepme.othertenant@example.com'
        );

        // Cross-tenant anonymization attempt is rejected
        app(TenantContextService::class)->setActiveTenant($this->globex);
        $crossTenantBlocked = false;
        try {
            app(CandidateAndApplicationService::class)->updateCandidateConsentAndDsar($targetFixture['candidate'], [
                'consent_given' => false,
                'dsar_status' => 'ANONYMIZED',
            ], $this->globexAdmin);
        } catch (AccessDeniedHttpException) {
            $crossTenantBlocked = true;
        }
        $this->assertTrue($crossTenantBlocked, 'Expected cross-tenant DSAR anonymization to be blocked.');

        // Now anonymize target candidate inside its own tenant (acme)
        app(TenantContextService::class)->setActiveTenant($this->acme);
        app(CandidateAndApplicationService::class)->updateCandidateConsentAndDsar($targetFixture['candidate'], [
            'consent_given' => false,
            'dsar_status' => 'ANONYMIZED',
        ], $this->acmeAdmin);

        // Unrelated candidate in same tenant is completely untouched
        $sameTenantCandidate = $sameTenantOtherFixture['candidate']->fresh();
        $sameTenantParseJob = $sameTenantOtherFixture['parseJob']->fresh();
        $sameTenantRun = AiTaskRun::withoutGlobalScopes()->findOrFail($sameTenantParseJob->ai_task_run_id);

        $this->assertSame('KeepMe', $sameTenantCandidate->first_name);
        $this->assertSame('keepme.sametenant@example.com', $sameTenantCandidate->email);
        $this->assertNotNull($sameTenantParseJob->parsed_data);
        $this->assertNotNull($sameTenantParseJob->corrected_data);
        $this->assertStringContainsString('KeepMe', (string) json_encode($sameTenantRun->structured_output));
        $this->assertNotSame('[REDACTED_DSAR_ANONYMIZED]', $sameTenantRun->redacted_input_summary);
        $this->assertSame(1, CandidateDocument::withoutGlobalScopes()->where('candidate_id', $sameTenantCandidate->id)->count());

        // Candidate in other tenant (globex) is completely untouched
        $otherTenantCandidate = $otherTenantFixture['candidate']->fresh();
        $otherTenantParseJob = $otherTenantFixture['parseJob']->fresh();
        $otherTenantRun = AiTaskRun::withoutGlobalScopes()->findOrFail($otherTenantParseJob->ai_task_run_id);

        $this->assertSame('KeepMe', $otherTenantCandidate->first_name);
        $this->assertSame('keepme.othertenant@example.com', $otherTenantCandidate->email);
        $this->assertNotNull($otherTenantParseJob->parsed_data);
        $this->assertNotNull($otherTenantParseJob->corrected_data);
        $this->assertStringContainsString('KeepMe', (string) json_encode($otherTenantRun->structured_output));
        $this->assertNotSame('[REDACTED_DSAR_ANONYMIZED]', $otherTenantRun->redacted_input_summary);
        $this->assertSame(1, CandidateDocument::withoutGlobalScopes()->where('candidate_id', $otherTenantCandidate->id)->count());
    }

    public function test_06_queued_or_retried_parsing_job_cannot_restore_anonymized_data(): void
    {
        Storage::fake('local');
        Queue::fake();
        app(TenantContextService::class)->setActiveTenant($this->acme);

        $service = app(CandidateAndApplicationService::class);
        $resumeParsing = app(ResumeParsingService::class);

        $candidate = $service->upsertCandidateWithConsent([
            'first_name' => 'Queued',
            'last_name' => 'CandidatePii',
            'email' => 'queued.pii@example.com',
            'country_code' => 'US',
            'consent_given' => true,
        ], $this->acme->id, $this->acmeAdmin);

        $document = $service->storePrivateDocument(
            $candidate,
            UploadedFile::fake()->createWithContent('queued_cv.txt', "Queued CandidatePii\nqueued.pii@example.com\nSUMMARY\nArchitect\nEXPERIENCE\nArchitect | CloudCo | 2020 - Present\nEDUCATION\nB.S. CS | MIT | 2018\nSKILLS\nPHP, Go\n"),
            'RESUME',
            null,
            $this->acmeAdmin
        );

        // Dispatch async -> creates ResumeParseJob in STATUS_QUEUED
        $queuedJob = $resumeParsing->parseDocument(
            candidate: $candidate,
            document: $document,
            applicationId: null,
            actor: $this->acmeAdmin,
            forceReprocess: false,
            dispatchAsync: true
        );
        $this->assertSame(ResumeParseJob::STATUS_QUEUED, $queuedJob->status);

        // Also create a retry-scheduled parse job due now
        $retryJob = ResumeParseJob::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->acme->id,
            'candidate_id' => $candidate->id,
            'candidate_document_id' => $document->id,
            'idempotency_key' => 'retry-scheduled-test-'.$candidate->id,
            'status' => ResumeParseJob::STATUS_RETRY_SCHEDULED,
            'schema_version' => ResumeParseJob::SCHEMA_VERSION,
            'attempt_count' => 1,
            'max_attempts' => 3,
            'next_retry_at' => now()->subMinute(),
        ]);

        // Anonymize the candidate BEFORE the queued worker or retry scheduler executes
        $service->updateCandidateConsentAndDsar($candidate, [
            'consent_given' => false,
            'dsar_status' => 'ANONYMIZED',
        ], $this->acmeAdmin);

        $runsBefore = AiTaskRun::withoutGlobalScopes()->where('tenant_id', $this->acme->id)->count();

        // 1. Execute queued job worker handler
        $workerJob = new ProcessResumeParsingJob($queuedJob->id, $this->acmeAdmin->id);
        $workerJob->handle($resumeParsing, app(TenantContextService::class));

        // 2. Execute direct executeParseJob on the retry job
        $retriedResult = $resumeParsing->executeParseJob($retryJob->fresh(), $this->acmeAdmin);

        // 3. Run scheduled retry command
        $this->artisan('recruitment:process-scheduled-parse-retries')->assertExitCode(0);

        $runsAfter = AiTaskRun::withoutGlobalScopes()->where('tenant_id', $this->acme->id)->count();
        $this->assertSame($runsBefore, $runsAfter, 'No new AiTaskRun should be created when executing a stale job for an anonymized candidate.');

        $candidate->refresh();
        $queuedJob->refresh();
        $retriedResult->refresh();

        $this->assertSame('Anonymized', $candidate->first_name);
        $this->assertSame('Candidate', $candidate->last_name);
        $this->assertNull($candidate->parsed_resume_data);
        $this->assertSame([], $candidate->skills);

        $this->assertSame(ResumeParseJob::STATUS_FAILED, $queuedJob->status);
        $this->assertSame('DSAR_ANONYMIZED', $queuedJob->error_code);
        $this->assertNull($queuedJob->parsed_data);
        $this->assertNull($queuedJob->corrected_data);

        $this->assertSame(ResumeParseJob::STATUS_FAILED, $retriedResult->status);
        $this->assertSame('DSAR_ANONYMIZED', $retriedResult->error_code);
        $this->assertNull($retriedResult->parsed_data);
        $this->assertNull($retriedResult->next_retry_at);
    }

    public function test_07_consent_withdrawal_continues_to_block_prohibited_ai_processing(): void
    {
        Storage::fake('local');
        Queue::fake();
        app(TenantContextService::class)->setActiveTenant($this->acme);

        $service = app(CandidateAndApplicationService::class);
        $resumeParsing = app(ResumeParsingService::class);
        $aiGateway = app(AiGatewayService::class);

        $candidate = $service->upsertCandidateWithConsent([
            'first_name' => 'Consent',
            'last_name' => 'WithdrawnUser',
            'email' => 'consent.withdrawn@example.com',
            'country_code' => 'US',
            'consent_given' => true,
        ], $this->acme->id, $this->acmeAdmin);

        $application = $service->submitApplication($this->acmeJob, $candidate, [], $this->acmeAdmin);

        $document = $service->storePrivateDocument(
            $candidate,
            UploadedFile::fake()->createWithContent('consent_cv.txt', "Consent WithdrawnUser\nconsent.withdrawn@example.com\nSUMMARY\nEngineer\nEXPERIENCE\nEngineer | DevCo | 2021 - Present\nEDUCATION\nB.S. CS | UCLA | 2020\nSKILLS\nPython\n"),
            'RESUME',
            $application->id,
            $this->acmeAdmin
        );

        // Queue a parse job while consent is still active
        $queuedJob = $resumeParsing->parseDocument(
            candidate: $candidate,
            document: $document,
            applicationId: $application->id,
            actor: $this->acmeAdmin,
            forceReprocess: false,
            dispatchAsync: true
        );

        // Candidate withdraws consent prior to worker execution
        $updated = $service->updateCandidateConsentAndDsar($candidate, [
            'consent_given' => false,
            'dsar_status' => 'RESTRICTION_REQUESTED',
        ], $this->acmeAdmin);

        $this->assertFalse($updated->consent_given);

        // 1. Queued job execution is blocked at execution time
        $workerJob = new ProcessResumeParsingJob($queuedJob->id, $this->acmeAdmin->id);
        $workerJob->handle($resumeParsing, app(TenantContextService::class));

        $queuedJob->refresh();
        $this->assertSame(ResumeParseJob::STATUS_FAILED, $queuedJob->status);
        $this->assertSame('CONSENT_OR_DSAR_BLOCKED', $queuedJob->error_code);
        $this->assertNull($queuedJob->parsed_data);

        // 2. Direct parseDocument call is blocked even if stale in-memory $candidate (with consent_given = true) is passed
        $parseBlocked = false;
        try {
            $resumeParsing->parseDocument($candidate, $document, $application->id, $this->acmeAdmin, true);
        } catch (ValidationException) {
            $parseBlocked = true;
        }
        $this->assertTrue($parseBlocked, 'Expected ValidationException when calling parseDocument after consent withdrawal.');

        // 3. Direct evaluateApplicationMatch call is blocked
        $matchBlocked = false;
        try {
            $resumeParsing->evaluateApplicationMatch($application, $this->acmeAdmin);
        } catch (ValidationException) {
            $matchBlocked = true;
        }
        $this->assertTrue($matchBlocked, 'Expected ValidationException when calling evaluateApplicationMatch after consent withdrawal.');

        // 4. Direct AiGatewayService::executeTask for the candidate/application is blocked
        $gatewayBlocked = false;
        try {
            $aiGateway->executeTask(
                taskType: 'candidate.match',
                providerKey: 'auto',
                rawInputText: 'Attempted direct gateway call for candidate who withdrew consent.',
                actor: $this->acmeAdmin,
                tenantId: $this->acme->id,
                options: [
                    'entity_type' => 'Application',
                    'entity_id' => $application->id,
                ]
            );
        } catch (RuntimeException $e) {
            $gatewayBlocked = str_contains($e->getMessage(), 'CONSENT_OR_DSAR_BLOCKED');
        }
        $this->assertTrue($gatewayBlocked, 'Expected AiGatewayService::executeTask to block execution when candidate consent is withdrawn.');
    }

    public function test_08_reprocessing_preserves_valid_human_reviewed_corrections_for_eligible_candidates(): void
    {
        Storage::fake('local');
        $fixture = $this->createCandidateWithParsedResumeAndMatch(
            $this->acme,
            $this->acmeJob,
            $this->acmeAdmin,
            'Clara',
            'Oswald',
            'clara.oswald@example.com'
        );

        $resumeParsing = app(ResumeParsingService::class);
        $candidate = $fixture['candidate'];
        $parseJob = $fixture['parseJob'];

        $reviewedJob = $resumeParsing->applyCorrections(
            parseJob: $parseJob,
            candidate: $candidate,
            correctedPayload: [
                'first_name' => 'Clara',
                'last_name' => 'Oswald-Verified',
                'email' => 'clara.verified@example.com',
                'country_code' => 'GB',
                'location_city' => 'London',
                'current_title' => 'VP of Platform Engineering',
                'years_experience' => 15,
                'professional_summary' => 'Human-reviewed and verified executive summary.',
                'skills' => 'Distributed Systems, Rust, Go, Kubernetes',
            ],
            reviewer: $this->acmeAdmin,
            isCandidateReview: false
        );

        $reprocessedJob = $resumeParsing->parseDocument(
            candidate: $candidate->fresh(),
            document: $fixture['document'],
            applicationId: $fixture['application']->id,
            actor: $this->acmeAdmin,
            forceReprocess: true
        );

        $candidate->refresh();
        $this->assertSame('Clara', $candidate->first_name);
        $this->assertSame('Oswald-Verified', $candidate->last_name);
        $this->assertSame('clara.verified@example.com', $candidate->email);
        $this->assertSame('GB', $candidate->country_code);
        $this->assertSame('VP of Platform Engineering', $candidate->current_title);
        $this->assertSame(15, $candidate->years_experience);
        $this->assertSame('Human-reviewed and verified executive summary.', $candidate->professional_summary);
        $this->assertNotEmpty($reprocessedJob->corrected_data);
        $this->assertSame('VP of Platform Engineering', $reprocessedJob->getEffectiveResumeData()['current_title']);
        $this->assertSame($reviewedJob->corrected_data['professional_summary'], $reprocessedJob->corrected_data['professional_summary']);
    }

    public function test_09_partial_storage_deletion_failures_are_handled_honestly_and_recoverably(): void
    {
        Storage::fake('local');
        $fixture = $this->createCandidateWithParsedResumeAndMatch(
            $this->acme,
            $this->acmeJob,
            $this->acmeAdmin,
            'Recoverable',
            'StorageCandidate',
            'recoverable.storage@example.com'
        );

        $candidate = $fixture['candidate'];
        $document = $fixture['document'];
        $parseJob = $fixture['parseJob'];
        $extractRun = AiTaskRun::withoutGlobalScopes()->findOrFail($parseJob->ai_task_run_id);

        // Simulate a storage disk failure where exists() is true but delete() returns false
        $failingDisk = Mockery::mock(Filesystem::class);
        $failingDisk->shouldReceive('exists')->with($document->storage_path)->andReturn(true);
        $failingDisk->shouldReceive('delete')->with($document->storage_path)->andReturn(false);
        Storage::set('local', $failingDisk);

        $storageFailureCaught = false;
        try {
            app(CandidateAndApplicationService::class)->updateCandidateConsentAndDsar($candidate, [
                'consent_given' => false,
                'dsar_status' => 'ANONYMIZED',
            ], $this->acmeAdmin);
        } catch (RuntimeException $e) {
            $storageFailureCaught = str_contains($e->getMessage(), 'DSAR_STORAGE_DELETION_FAILED');
        }

        $this->assertTrue($storageFailureCaught, 'Expected DSAR_STORAGE_DELETION_FAILED RuntimeException when file deletion fails.');

        // Verify transaction rolled back honestly: candidate is NOT falsely marked ANONYMIZED, and records remain intact for retry
        $candidate->refresh();
        $parseJob->refresh();
        $extractRun->refresh();

        $this->assertNotSame('ANONYMIZED', $candidate->dsar_status);
        $this->assertSame('Recoverable', $candidate->first_name);
        $this->assertSame(1, CandidateDocument::withoutGlobalScopes()->where('candidate_id', $candidate->id)->count());
        $this->assertNotNull($parseJob->parsed_data);
        $this->assertNotSame('[REDACTED_DSAR_ANONYMIZED]', $extractRun->redacted_input_summary);

        // Verify failure audit event was recorded
        $failureAudit = AuditEvent::withoutGlobalScopes()
            ->where('tenant_id', $this->acme->id)
            ->where('action', 'candidate_dsar_anonymization_storage_failed')
            ->where('entity_id', (string) $candidate->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($failureAudit);
        $this->assertSame('FAILED', $failureAudit->outcome);
        $this->assertSame('DSAR_STORAGE_DELETION_FAILED', $failureAudit->error_code);

        // Now restore working fake storage disk and retry anonymization -> succeeds cleanly
        Storage::fake('local');
        Storage::disk('local')->put($document->storage_path, 'resume bytes to delete on retry');

        $anonymized = app(CandidateAndApplicationService::class)->updateCandidateConsentAndDsar($candidate, [
            'consent_given' => false,
            'dsar_status' => 'ANONYMIZED',
        ], $this->acmeAdmin);

        $this->assertSame('ANONYMIZED', $anonymized->dsar_status);
        $this->assertSame('Anonymized', $anonymized->first_name);
        $this->assertFalse(Storage::disk('local')->exists($document->storage_path));
        $this->assertSame(0, CandidateDocument::withoutGlobalScopes()->where('candidate_id', $candidate->id)->count());
        $this->assertNull($parseJob->fresh()->parsed_data);
        $this->assertSame('[REDACTED_DSAR_ANONYMIZED]', $extractRun->fresh()->redacted_input_summary);
    }

    public function test_10_operational_audit_metadata_remains_useful_without_retaining_personal_data(): void
    {
        Storage::fake('local');
        $fixture = $this->createCandidateWithParsedResumeAndMatch(
            $this->acme,
            $this->acmeJob,
            $this->acmeAdmin,
            'Telemetry',
            'PreservedCandidate',
            'telemetry.preserved@example.com'
        );

        $candidate = $fixture['candidate'];
        $parseJob = $fixture['parseJob'];
        $extractRun = AiTaskRun::withoutGlobalScopes()->findOrFail($parseJob->ai_task_run_id);
        $originalTokens = $extractRun->tokens_used;
        $originalCost = $extractRun->cost_minor;
        $originalLatency = $extractRun->latency_ms;
        $originalProvider = $extractRun->provider_key;
        $originalModel = $extractRun->model_name;

        app(CandidateAndApplicationService::class)->updateCandidateConsentAndDsar($candidate, [
            'consent_given' => false,
            'dsar_status' => 'ANONYMIZED',
        ], $this->acmeAdmin);

        $extractRun->refresh();
        $parseJob->refresh();

        // Non-personal operational AI telemetry is preserved
        $this->assertSame($originalTokens, $extractRun->tokens_used);
        $this->assertSame($originalCost, $extractRun->cost_minor);
        $this->assertSame($originalLatency, $extractRun->latency_ms);
        $this->assertSame($originalProvider, $extractRun->provider_key);
        $this->assertSame($originalModel, $extractRun->model_name);
        $this->assertSame('SUCCEEDED', $extractRun->status);
        $this->assertSame('resume.extract', $extractRun->task_type);

        // Non-personal operational ResumeParseJob metadata is preserved
        $this->assertSame($originalProvider, $parseJob->provider_key);
        $this->assertSame($originalModel, $parseJob->model_name);
        $this->assertSame(ResumeParseJob::SCHEMA_VERSION, $parseJob->schema_version);
        $this->assertSame('DSAR_ANONYMIZED', $parseJob->error_code);

        // AuditEvent records operational counts and candidate_code without personal data
        $auditEvent = AuditEvent::withoutGlobalScopes()
            ->where('tenant_id', $this->acme->id)
            ->where('action', 'candidate_consent_dsar_updated')
            ->where('entity_id', (string) $candidate->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('SUCCESS', $auditEvent->outcome);
        $this->assertSame($candidate->candidate_code, $auditEvent->metadata['candidate_code'] ?? null);
        $this->assertTrue((bool) ($auditEvent->metadata['anonymized'] ?? false));
        $this->assertGreaterThanOrEqual(1, (int) ($auditEvent->metadata['scrubbed_parse_jobs_count'] ?? 0));
        $this->assertGreaterThanOrEqual(2, (int) ($auditEvent->metadata['scrubbed_ai_task_runs_count'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($auditEvent->metadata['deleted_documents_count'] ?? 0));

        $serializedAudit = (string) json_encode($auditEvent->metadata);
        $this->assertStringNotContainsString('Telemetry', $serializedAudit);
        $this->assertStringNotContainsString('PreservedCandidate', $serializedAudit);
        $this->assertStringNotContainsString('telemetry.preserved@example.com', $serializedAudit);
    }
}
