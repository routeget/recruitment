<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeliverSignedWebhookJob;
use App\Jobs\ProcessResumeParsingJob;
use App\Models\AiProviderConfig;
use App\Models\AiTaskRoute;
use App\Models\Candidate;
use App\Models\HrHandover;
use App\Models\HrHandoverApproval;
use App\Models\HrValidationCase;
use App\Models\JobPosting;
use App\Models\LegalEntity;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\ResumeParseJob;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\AiGatewayService;
use App\Services\CandidateAndApplicationService;
use App\Services\ResumeParsingService;
use App\Services\TenantContextService;
use Database\Seeders\AlphaSafeBootstrapSeeder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class PostReleaseAssurancePa02Test extends TestCase
{
    private Tenant $acme;

    private User $acmeAdmin;

    private JobPosting $acmeJob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AlphaSafeBootstrapSeeder::class);

        $this->acme = Tenant::query()->where('slug', 'acme-demo')->firstOrFail();
        $this->acmeAdmin = User::query()->where('email', 'admin@acme-demo.test')->firstOrFail();
        $this->acmeJob = JobPosting::withoutGlobalScopes()
            ->where('tenant_id', $this->acme->id)
            ->where('status', JobPosting::STATUS_PUBLISHED)
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPrivacyAttestation(string $providerKey, string $modelName, array $overrides = []): array
    {
        $commonPrivacySettings = [
            'allow_model_training' => false,
            'training_opt_out_verified' => true,
            'zdr_enabled' => true,
            'retention_days' => 0,
        ];

        $providerSpecificSettings = match ($providerKey) {
            'openai' => ['store_completions' => false],
            'claude' => ['commercial_terms_verified' => true, 'zdr_org_enabled' => true],
            'gemini' => ['billing_tier' => 'PAID_ENTERPRISE', 'project_logging_disabled' => true],
            default => [],
        };

        return array_replace_recursive([
            'status' => 'VERIFIED',
            'attestation_source' => 'ENTERPRISE_DPA_AND_ZDR_VERIFIED',
            'dpa_reference' => 'DPA-2026-TRINGERS-01',
            'zdr_or_retention_reference' => 'ZDR-2026-TRINGERS-01',
            'verifier_identifier' => 'privacy-governance@career.tringers.com',
            'verified_at' => now()->subDay()->toIso8601String(),
            'expires_at' => now()->addMonths(6)->toIso8601String(),
            'approved_tasks' => AiProviderConfig::CANDIDATE_PII_TASKS,
            'approved_models' => [$modelName],
            'redaction_enabled' => true,
            'provider_privacy_settings' => array_merge($commonPrivacySettings, $providerSpecificSettings),
        ], $overrides);
    }

    private function providerConfig(string $providerKey): AiProviderConfig
    {
        return AiProviderConfig::withoutGlobalScopes()
            ->where('tenant_id', $this->acme->id)
            ->where('provider_key', $providerKey)
            ->firstOrFail();
    }

    public function test_01_unattested_expired_incomplete_task_model_and_contradictory_external_ai_providers_fail_closed(): void
    {
        Http::fake();
        $aiService = app(AiGatewayService::class);
        $aiService->ensureDefaultsForTenant((int) $this->acme->id);

        // 1. Unattested external providers (claude, gemini, openai) fail closed on all candidate PII tasks
        foreach (['claude' => 'claude-3-7-sonnet', 'gemini' => 'gemini-2.5-pro', 'openai' => 'gpt-4.1-mini'] as $providerKey => $modelName) {
            $aiService->updateProviderConfig(
                $this->providerConfig($providerKey),
                [
                    'is_enabled' => true,
                    'model_name' => $modelName,
                    'api_key' => 'sk-test-'.$providerKey.'-key-1234567890',
                    'privacy_attestation' => [],
                ],
                $this->acmeAdmin,
            );

            foreach (AiProviderConfig::CANDIDATE_PII_TASKS as $taskType) {
                try {
                    $aiService->executeTask(
                        taskType: $taskType,
                        providerKey: $providerKey,
                        rawInputText: 'Candidate Jane Doe jane@example.com +1-415-555-0199 Senior Engineer',
                        actor: $this->acmeAdmin,
                        tenantId: (int) $this->acme->id,
                    );
                    $this->fail("Expected {$providerKey} without privacy attestation to be blocked for {$taskType}.");
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('EXTERNAL_AI_PRIVACY_ATTESTATION_REQUIRED', $e->getMessage());
                }
            }
        }

        // 2. Expired attestation is blocked
        $aiService->updateProviderConfig(
            $this->providerConfig('openai'),
            [
                'is_enabled' => true,
                'model_name' => 'gpt-4.1-mini',
                'api_key' => 'sk-test-openai-key-1234567890',
                'privacy_attestation' => $this->validPrivacyAttestation('openai', 'gpt-4.1-mini', [
                    'expires_at' => now()->subMinute()->toIso8601String(),
                ]),
            ],
            $this->acmeAdmin,
        );

        try {
            $aiService->executeTask(
                taskType: 'resume.extract',
                providerKey: 'openai',
                rawInputText: 'Resume text',
                actor: $this->acmeAdmin,
                tenantId: (int) $this->acme->id,
            );
            $this->fail('Expected expired privacy attestation to fail closed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('EXTERNAL_AI_PRIVACY_ATTESTATION_EXPIRED', $e->getMessage());
        }

        // 3. Incomplete attestation missing required metadata is blocked
        $aiService->updateProviderConfig(
            $this->providerConfig('claude'),
            [
                'is_enabled' => true,
                'model_name' => 'claude-3-7-sonnet',
                'api_key' => 'sk-test-claude-key-1234567890',
                'privacy_attestation' => $this->validPrivacyAttestation('claude', 'claude-3-7-sonnet', [
                    'dpa_reference' => '',
                ]),
            ],
            $this->acmeAdmin,
        );

        try {
            $aiService->executeTask(
                taskType: 'resume.extract',
                providerKey: 'claude',
                rawInputText: 'Resume text',
                actor: $this->acmeAdmin,
                tenantId: (int) $this->acme->id,
            );
            $this->fail('Expected incomplete privacy attestation to fail closed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('EXTERNAL_AI_PRIVACY_ATTESTATION_INCOMPLETE', $e->getMessage());
        }

        // 4. Attested provider for resume.extract is blocked for unapproved task candidate_screening
        $aiService->updateProviderConfig(
            $this->providerConfig('gemini'),
            [
                'is_enabled' => true,
                'model_name' => 'gemini-2.5-pro',
                'api_key' => 'sk-test-gemini-key-1234567890',
                'privacy_attestation' => array_replace($this->validPrivacyAttestation('gemini', 'gemini-2.5-pro'), [
                    'approved_tasks' => ['resume.extract'],
                ]),
            ],
            $this->acmeAdmin,
        );

        try {
            $aiService->executeTask(
                taskType: 'candidate_screening',
                providerKey: 'gemini',
                rawInputText: 'Resume text',
                actor: $this->acmeAdmin,
                tenantId: (int) $this->acme->id,
            );
            $this->fail('Expected unapproved task to fail closed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('EXTERNAL_AI_PURPOSE_NOT_APPROVED', $e->getMessage());
        }

        // 5. Attested provider for model A is blocked when configured with model B
        $aiService->updateProviderConfig(
            $this->providerConfig('openai'),
            [
                'is_enabled' => true,
                'model_name' => 'gpt-4o',
                'api_key' => 'sk-test-openai-key-1234567890',
                'privacy_attestation' => $this->validPrivacyAttestation('openai', 'gpt-4.1-mini'),
            ],
            $this->acmeAdmin,
        );

        try {
            $aiService->executeTask(
                taskType: 'resume.extract',
                providerKey: 'openai',
                rawInputText: 'Resume text',
                actor: $this->acmeAdmin,
                tenantId: (int) $this->acme->id,
            );
            $this->fail('Expected unapproved model to fail closed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('EXTERNAL_AI_MODEL_NOT_APPROVED', $e->getMessage());
        }

        // 6. Contradictory provider privacy settings (store_completions = true) are blocked
        $aiService->updateProviderConfig(
            $this->providerConfig('openai'),
            [
                'is_enabled' => true,
                'model_name' => 'gpt-4.1-mini',
                'api_key' => 'sk-test-openai-key-1234567890',
                'privacy_attestation' => $this->validPrivacyAttestation('openai', 'gpt-4.1-mini', [
                    'provider_privacy_settings' => [
                        'store_completions' => true,
                    ],
                ]),
            ],
            $this->acmeAdmin,
        );

        try {
            $aiService->executeTask(
                taskType: 'resume.extract',
                providerKey: 'openai',
                rawInputText: 'Resume text',
                actor: $this->acmeAdmin,
                tenantId: (int) $this->acme->id,
            );
            $this->fail('Expected store_completions=true to fail closed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('EXTERNAL_AI_PRIVACY_SETTINGS_CONTRADICTORY', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_02_attested_openai_sends_store_false_minimizes_contact_pii_blocks_silent_fallback_and_keeps_local_deterministic_offline(): void
    {
        Storage::fake('local');

        $outboundBodies = [];
        Http::fake([
            'api.openai.com/*' => function (Request $request) use (&$outboundBodies) {
                $outboundBodies[] = $request->data();

                return Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'content' => json_encode([
                                    'schema_version' => 'RESUME_SCHEMA_v1.0',
                                    'full_name' => '[REDACTED]',
                                    'contact' => [
                                        'email' => '[REDACTED_EMAIL]',
                                        'phone' => '[REDACTED_PHONE]',
                                        'location' => 'Austin, TX',
                                        'links' => [],
                                    ],
                                    'email' => '[REDACTED_EMAIL]',
                                    'phone' => '[REDACTED_PHONE]',
                                    'location' => 'Austin, TX',
                                    'professional_summary' => 'Staff Backend Engineer specializing in distributed systems.',
                                    'current_title' => 'Staff Backend Engineer',
                                    'years_experience' => 9,
                                    'skills' => ['Laravel', 'PostgreSQL', 'Redis', 'Kubernetes'],
                                    'work_experience' => [
                                        [
                                            'job_title' => 'Staff Backend Engineer',
                                            'employer' => 'Nebula Systems',
                                            'start_date' => '2021',
                                            'end_date' => 'Present',
                                        ],
                                    ],
                                    'education' => [
                                        [
                                            'qualification' => 'B.S.',
                                            'field_of_study' => 'Computer Science',
                                            'institution' => 'UT Austin',
                                            'graduation_year' => '2016',
                                        ],
                                    ],
                                    'certifications' => ['CKA'],
                                    'languages' => ['English'],
                                    'professional_links' => [],
                                    'missing_fields' => [],
                                    'evidence_quotes' => ['Staff Backend Engineer with 9 years of experience.'],
                                ]),
                            ],
                        ],
                    ],
                    'usage' => ['total_tokens' => 310],
                ], 200);
            },
        ]);

        $aiService = app(AiGatewayService::class);
        $aiService->ensureDefaultsForTenant((int) $this->acme->id);

        // Configure attested OpenAI provider
        $aiService->updateProviderConfig(
            $this->providerConfig('openai'),
            [
                'is_enabled' => true,
                'model_name' => 'gpt-4.1-mini',
                'api_key' => 'sk-test-openai-valid-key-999999',
                'privacy_attestation' => $this->validPrivacyAttestation('openai', 'gpt-4.1-mini'),
            ],
            $this->acmeAdmin,
        );

        AiTaskRoute::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $this->acme->id, 'task_type' => 'resume.extract'],
            [
                'uuid' => (string) Str::uuid(),
                'primary_provider_key' => 'openai',
                'primary_model_name' => 'gpt-4.1-mini',
                'fallback_provider_key' => 'claude',
                'fallback_model_name' => 'claude-3-7-sonnet',
                'is_enabled' => true,
                'require_human_review' => true,
            ]
        );

        app(TenantContextService::class)->setActiveTenant($this->acme);
        $candidateService = app(CandidateAndApplicationService::class);
        $resumeParsingService = app(ResumeParsingService::class);

        $candidate = $candidateService->upsertCandidateWithConsent([
            'first_name' => 'Marcus',
            'last_name' => 'Vance',
            'email' => 'marcus.vance.private@example.com',
            'phone' => '+1 (512) 555-0147',
            'consent_given' => true,
        ], (int) $this->acme->id, $this->acmeAdmin);

        $rawResume = "Marcus Vance\nmarcus.vance.private@example.com | +1 (512) 555-0147 | https://linkedin.com/in/marcus-vance-secret | SSN 123-45-6789\n"
            ."SUMMARY\nStaff Backend Engineer with 9 years of experience.\n"
            ."EXPERIENCE\nStaff Backend Engineer | Nebula Systems | 2021 - Present\n"
            ."EDUCATION\nB.S. Computer Science | UT Austin | 2016\n"
            ."SKILLS\nLaravel, PostgreSQL, Redis, Kubernetes\n";

        $document = $candidateService->storePrivateDocument(
            $candidate,
            UploadedFile::fake()->createWithContent('marcus_vance_cv.txt', $rawResume),
            'RESUME',
            null,
            $this->acmeAdmin
        );

        $completedJob = $resumeParsingService->parseDocument(
            candidate: $candidate,
            document: $document,
            applicationId: null,
            actor: $this->acmeAdmin,
            forceReprocess: true,
            dispatchAsync: false,
            providerOverride: 'openai',
        );

        $this->assertTrue(in_array($completedJob->status, [
            ResumeParseJob::STATUS_COMPLETED,
            ResumeParseJob::STATUS_REVIEW_REQUIRED,
        ], true), 'Expected COMPLETED or REVIEW_REQUIRED, got '.$completedJob->status.' ('.$completedJob->error_code.': '.$completedJob->error_message.')');
        $this->assertNotEmpty($outboundBodies);

        $sentPayload = $outboundBodies[0];
        $this->assertFalse($sentPayload['store'] ?? true, 'OpenAI outbound request must set store=false.');

        $serializedOutbound = json_encode($sentPayload) ?: '';
        $this->assertStringContainsString('<minimized_candidate_qualifications>', $serializedOutbound);
        $this->assertStringNotContainsString('marcus.vance.private@example.com', $serializedOutbound);
        $this->assertStringNotContainsString('512) 555-0147', $serializedOutbound);
        $this->assertStringNotContainsString('linkedin.com/in/marcus-vance-secret', $serializedOutbound);
        $this->assertStringNotContainsString('123-45-6789', $serializedOutbound);

        // Verify locally extracted contact fields are restored in the merged structured payload
        $this->assertSame('marcus.vance.private@example.com', $completedJob->parsed_data['email'] ?? null);
        $this->assertSame('Marcus Vance', $completedJob->parsed_data['full_name'] ?? null);

        // Verify silent fallback to another external provider is blocked when primary external provider fails privacy gate
        $aiService->updateProviderConfig(
            $this->providerConfig('openai'),
            [
                'is_enabled' => true,
                'model_name' => 'gpt-4.1-mini',
                'api_key' => 'sk-test-openai-valid-key-999999',
                'privacy_attestation' => [],
            ],
            $this->acmeAdmin,
        );
        $aiService->updateProviderConfig(
            $this->providerConfig('claude'),
            [
                'is_enabled' => true,
                'model_name' => 'claude-3-7-sonnet',
                'api_key' => 'sk-test-claude-key-999999',
                'privacy_attestation' => $this->validPrivacyAttestation('claude', 'claude-3-7-sonnet'),
            ],
            $this->acmeAdmin,
        );

        try {
            $aiService->executeTask(
                taskType: 'resume.extract',
                providerKey: 'openai',
                rawInputText: $rawResume,
                actor: $this->acmeAdmin,
                tenantId: (int) $this->acme->id,
            );
            $this->fail('Expected primary provider privacy gate failure to block silent fallback.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('EXTERNAL_AI_PRIVACY_ATTESTATION_REQUIRED', $e->getMessage());
        }

        // Verify local deterministic_fake runs completely offline without any external HTTP requests
        $countBeforeLocal = count($outboundBodies);
        $localRun = $aiService->executeTask(
            taskType: 'resume.extract',
            providerKey: 'deterministic_fake',
            rawInputText: $rawResume,
            actor: $this->acmeAdmin,
            tenantId: (int) $this->acme->id,
        );
        $this->assertSame('SUCCEEDED', $localRun->status);
        $this->assertCount($countBeforeLocal, $outboundBodies);
    }

    public function test_03_scheduled_parse_retries_handle_eligible_exhausted_and_consent_or_dsar_blocked_jobs_and_worker_guards(): void
    {
        Queue::fake();
        Storage::fake('local');

        app(TenantContextService::class)->setActiveTenant($this->acme);
        $candidateService = app(CandidateAndApplicationService::class);

        // 1. Eligible candidate with attempt_count < max_attempts
        $eligibleCandidate = $candidateService->upsertCandidateWithConsent([
            'first_name' => 'Elena',
            'last_name' => 'Rostova',
            'email' => 'elena.rostova@example.com',
            'consent_given' => true,
        ], (int) $this->acme->id, $this->acmeAdmin);

        $doc1 = $candidateService->storePrivateDocument(
            $eligibleCandidate,
            UploadedFile::fake()->createWithContent('elena.txt', 'Elena Rostova CV'),
            'RESUME',
            null,
            $this->acmeAdmin
        );

        $eligibleJob = ResumeParseJob::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->acme->id,
            'candidate_id' => $eligibleCandidate->id,
            'candidate_document_id' => $doc1->id,
            'idempotency_key' => 'pa02-eligible-'.Str::random(8),
            'status' => ResumeParseJob::STATUS_RETRY_SCHEDULED,
            'attempt_count' => 1,
            'max_attempts' => 3,
            'next_retry_at' => now()->subMinute(),
        ]);

        // 2. Exhausted retry job (attempt_count >= max_attempts)
        $exhaustedJob = ResumeParseJob::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->acme->id,
            'candidate_id' => $eligibleCandidate->id,
            'candidate_document_id' => $doc1->id,
            'idempotency_key' => 'pa02-exhausted-'.Str::random(8),
            'status' => ResumeParseJob::STATUS_RETRY_SCHEDULED,
            'attempt_count' => 3,
            'max_attempts' => 3,
            'next_retry_at' => now()->subMinute(),
        ]);

        // 3. Consent-withdrawn candidate retry job
        $withdrawnCandidate = $candidateService->upsertCandidateWithConsent([
            'first_name' => 'NoConsent',
            'last_name' => 'User',
            'email' => 'noconsent@example.com',
            'consent_given' => true,
        ], (int) $this->acme->id, $this->acmeAdmin);
        $withdrawnCandidate->update(['consent_given' => false]);

        $withdrawnJob = ResumeParseJob::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->acme->id,
            'candidate_id' => $withdrawnCandidate->id,
            'candidate_document_id' => $doc1->id,
            'idempotency_key' => 'pa02-withdrawn-'.Str::random(8),
            'status' => ResumeParseJob::STATUS_RETRY_SCHEDULED,
            'attempt_count' => 1,
            'max_attempts' => 3,
            'next_retry_at' => now()->subMinute(),
        ]);

        // 4. Anonymized candidate retry job
        $anonCandidate = $candidateService->upsertCandidateWithConsent([
            'first_name' => 'Anon',
            'last_name' => 'User',
            'email' => 'anon.user@example.com',
            'consent_given' => true,
        ], (int) $this->acme->id, $this->acmeAdmin);
        $anonCandidate->update(['consent_given' => false, 'dsar_status' => 'ANONYMIZED']);

        $anonJob = ResumeParseJob::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->acme->id,
            'candidate_id' => $anonCandidate->id,
            'candidate_document_id' => null,
            'idempotency_key' => 'pa02-anon-'.Str::random(8),
            'status' => ResumeParseJob::STATUS_RETRY_SCHEDULED,
            'attempt_count' => 1,
            'max_attempts' => 3,
            'next_retry_at' => now()->subMinute(),
            'parsed_data' => ['full_name' => 'Residual PII'],
        ]);

        Artisan::call('recruitment:process-scheduled-parse-retries');

        $eligibleJob->refresh();
        $exhaustedJob->refresh();
        $withdrawnJob->refresh();
        $anonJob->refresh();

        Queue::assertPushed(ProcessResumeParsingJob::class, fn (ProcessResumeParsingJob $job): bool => $job->resumeParseJobId === (int) $eligibleJob->id);
        Queue::assertNotPushed(ProcessResumeParsingJob::class, fn (ProcessResumeParsingJob $job): bool => in_array($job->resumeParseJobId, [(int) $exhaustedJob->id, (int) $withdrawnJob->id, (int) $anonJob->id], true));

        $this->assertSame(ResumeParseJob::STATUS_FAILED, $exhaustedJob->status);
        $this->assertSame('MAX_RETRIES_EXHAUSTED', $exhaustedJob->error_code);
        $this->assertNull($exhaustedJob->next_retry_at);

        $this->assertSame(ResumeParseJob::STATUS_FAILED, $withdrawnJob->status);
        $this->assertSame('CONSENT_OR_DSAR_BLOCKED', $withdrawnJob->error_code);

        $this->assertSame(ResumeParseJob::STATUS_FAILED, $anonJob->status);
        $this->assertSame('DSAR_ANONYMIZED', $anonJob->error_code);
        $this->assertNull($anonJob->parsed_data);

        // Verify ProcessResumeParsingJob execution-time guard when candidate withdraws consent before worker runs
        $eligibleCandidate->update(['consent_given' => false]);
        $workerJob = new ProcessResumeParsingJob((int) $eligibleJob->id);
        $workerJob->handle(app(ResumeParsingService::class), app(TenantContextService::class));

        $eligibleJob->refresh();
        $this->assertSame(ResumeParseJob::STATUS_FAILED, $eligibleJob->status);
        $this->assertSame('CONSENT_OR_DSAR_BLOCKED', $eligibleJob->error_code);
    }

    public function test_04_webhook_delivery_job_uniqueness_and_terminal_status_idempotency(): void
    {
        Http::fake();

        $subscription = WebhookSubscription::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->acme->id,
            'name' => 'Test Webhook',
            'target_url' => 'https://hooks.example.com/recruitment',
            'signing_secret' => 'whsec_test_secret_1234567890',
            'subscribed_events' => ['candidate.applied'],
            'is_active' => true,
        ]);

        $delivery = WebhookDelivery::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->acme->id,
            'webhook_subscription_id' => $subscription->id,
            'event_type' => 'candidate.applied',
            'event_id' => (string) Str::uuid(),
            'payload' => ['event' => 'candidate.applied'],
            'signature_header' => 'sha256=testsignature',
            'status' => 'DELIVERED',
            'attempt_count' => 1,
            'delivered_at' => now(),
        ]);

        $job = new DeliverSignedWebhookJob((int) $delivery->id);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('webhook-delivery-'.$delivery->id, $job->uniqueId());

        app()->call([$job, 'handle']);
        Http::assertNothingSent();
    }

    public function test_05_backup_retention_command_dry_run_and_legal_hold_safeguards(): void
    {
        $tempDir = storage_path('framework/testing/pa02_backups_'.Str::random(8));
        File::ensureDirectoryExists($tempDir, 0700);

        try {
            $newestFile = $tempDir.'/recruitment_newest.sql';
            $expiredFile = $tempDir.'/recruitment_expired.sql';

            File::put($newestFile, "CREATE TABLE `tenants` (`id` int);\n-- Dump completed on 2026-10-23 10:00:00\n");
            chmod($newestFile, 0600);
            touch($newestFile, now()->subHours(2)->getTimestamp());

            File::put($expiredFile, "CREATE TABLE `tenants` (`id` int);\n-- Dump completed on 2026-10-01 10:00:00\n");
            chmod($expiredFile, 0600);
            touch($expiredFile, now()->subDays(20)->getTimestamp());

            // 1. Default dry-run never deletes expired files
            Artisan::call('recruitment:audit-backup-retention', ['--dir' => $tempDir]);
            $this->assertFileExists($newestFile);
            $this->assertFileExists($expiredFile);

            // 2. Active LEGAL_HOLD blocks pruning even when --prune-approved is passed
            app(TenantContextService::class)->setActiveTenant($this->acme);
            $candidateService = app(CandidateAndApplicationService::class);
            $holdCandidate = $candidateService->upsertCandidateWithConsent([
                'first_name' => 'Hold',
                'last_name' => 'Candidate',
                'email' => 'hold.candidate@example.com',
                'consent_given' => true,
            ], (int) $this->acme->id, $this->acmeAdmin);
            $holdCandidate->update(['dsar_status' => 'LEGAL_HOLD']);

            Artisan::call('recruitment:audit-backup-retention', [
                '--dir' => $tempDir,
                '--prune-approved' => true,
            ]);
            $this->assertFileExists($expiredFile);

            // 3. Releasing legal hold allows approved pruning of expired backup while preserving newest valid backup
            $holdCandidate->update(['dsar_status' => 'NONE']);
            Artisan::call('recruitment:audit-backup-retention', [
                '--dir' => $tempDir,
                '--prune-approved' => true,
            ]);
            $this->assertFileExists($newestFile);
            $this->assertFileDoesNotExist($expiredFile);
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    public function test_06_hr_handover_snapshot_minimization_retention_expires_at_legal_hold_and_public_privacy_notice(): void
    {
        app(TenantContextService::class)->setActiveTenant($this->acme);
        $candidateService = app(CandidateAndApplicationService::class);

        $legalEntity = LegalEntity::withoutGlobalScopes()->where('tenant_id', $this->acme->id)->firstOrFail();

        // 1. Create candidate with completed HR handover and test DSAR anonymization + handover snapshot minimization
        $candidate = $candidateService->upsertCandidateWithConsent([
            'first_name' => 'Clara',
            'last_name' => 'Oswald',
            'email' => 'clara.oswald@example.com',
            'phone' => '+44 20 7946 0958',
            'consent_given' => true,
        ], (int) $this->acme->id, $this->acmeAdmin);

        $application = $candidateService->submitApplication($this->acmeJob, $candidate, [
            'cover_note' => 'Cover note for Clara Oswald',
        ], $this->acmeAdmin);

        $offer = Offer::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->acme->id,
            'application_id' => $application->id,
            'offer_code' => 'OFR-PA02-001',
            'status' => 'ACCEPTED',
            'current_version_number' => 1,
            'created_by_user_id' => $this->acmeAdmin->id,
        ]);

        $offerVersion = OfferVersion::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->acme->id,
            'offer_id' => $offer->id,
            'version_number' => 1,
            'job_title' => 'Principal Cloud Architect',
            'legal_entity_id' => $legalEntity->id,
            'employment_type' => 'FULL_TIME',
            'currency_code' => 'USD',
            'base_salary_minor' => 15500000,
            'bonus_terms' => '15%',
            'start_date' => now()->addDays(30)->toDateString(),
            'snapshot_hash' => hash('sha256', 'offer-v1'),
            'created_by_user_id' => $this->acmeAdmin->id,
            'created_at' => now(),
        ]);

        $validationCase = HrValidationCase::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->acme->id,
            'application_id' => $application->id,
            'offer_version_id' => $offerVersion->id,
            'legal_entity_id' => $legalEntity->id,
            'status' => 'handed_over',
            'identity_verified' => true,
            'right_to_work_verified' => true,
            'background_check_verified' => true,
            'mandatory_documents_verified' => true,
            'validated_by_user_id' => $this->acmeAdmin->id,
            'validated_at' => now(),
        ]);

        $approval = HrHandoverApproval::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->acme->id,
            'hr_validation_case_id' => $validationCase->id,
            'approver_user_id' => $this->acmeAdmin->id,
            'decision' => 'APPROVED',
            'comment' => 'Approved for PA-02 verification',
            'approved_payload_snapshot' => [
                'idempotency_key' => 'HND-PA02-COMPLETED-001',
                'candidate_email' => 'clara.oswald@example.com',
                'candidate_first_name' => 'Clara',
                'candidate_last_name' => 'Oswald',
            ],
            'approved_payload_hash' => hash('sha256', 'approved-payload'),
            'decided_at' => now(),
        ]);

        $completedHandover = HrHandover::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->acme->id,
            'hr_validation_case_id' => $validationCase->id,
            'approval_id' => $approval->id,
            'idempotency_key' => 'HND-PA02-COMPLETED-001',
            'status' => 'COMPLETED',
            'requires_reconciliation' => false,
            'target_system' => 'DYNAMICS_365_HR',
            'external_request_id' => 'D365-REQ-001',
            'external_employee_id' => 'D365-EMP-9001',
            'completed_at' => now()->subDays(45),
            'payload_snapshot' => [
                'idempotency_key' => 'HND-PA02-COMPLETED-001',
                'tenant_id' => $this->acme->id,
                'application_id' => $application->id,
                'candidate_id' => $candidate->id,
                'candidate_code' => $candidate->candidate_code,
                'candidate_email' => 'clara.oswald@example.com',
                'candidate_first_name' => 'Clara',
                'candidate_last_name' => 'Oswald',
                'candidate_phone' => '+44 20 7946 0958',
                'legal_entity_id' => $legalEntity->id,
                'legal_entity_code' => $legalEntity->code,
                'offer_version_id' => $offerVersion->id,
                'offer_snapshot_hash' => $offerVersion->snapshot_hash,
            ],
            'payload_hash' => hash('sha256', 'initial-snapshot'),
        ]);

        // Also create a reconciliation-pending handover to prove non-completed handovers retain operational payload until reconciled
        $reconcilingHandover = HrHandover::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->acme->id,
            'hr_validation_case_id' => $validationCase->id,
            'approval_id' => $approval->id,
            'idempotency_key' => 'HND-PA02-PENDING-002',
            'status' => 'reconciliation_pending',
            'requires_reconciliation' => true,
            'target_system' => 'DYNAMICS_365_HR',
            'external_request_id' => 'D365-REQ-002',
            'external_employee_id' => null,
            'payload_snapshot' => [
                'idempotency_key' => 'HND-PA02-PENDING-002',
                'candidate_email' => 'clara.oswald@example.com',
                'candidate_first_name' => 'Clara',
                'candidate_last_name' => 'Oswald',
            ],
            'payload_hash' => hash('sha256', 'pending-snapshot'),
        ]);

        // Legal hold blocks DSAR anonymization
        $candidate->update(['dsar_status' => 'LEGAL_HOLD']);
        try {
            $candidateService->updateCandidateConsentAndDsar(
                $candidate,
                ['consent_given' => false, 'dsar_status' => 'ANONYMIZED'],
                $this->acmeAdmin
            );
            $this->fail('Expected LEGAL_HOLD candidate to block DSAR anonymization.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('LEGAL_HOLD_ACTIVE', json_encode($e->errors()) ?: '');
        }

        // Release legal hold and execute DSAR anonymization
        $candidate->update(['dsar_status' => 'NONE']);
        $candidateService->updateCandidateConsentAndDsar(
            $candidate->fresh(),
            ['consent_given' => false, 'dsar_status' => 'ANONYMIZED'],
            $this->acmeAdmin
        );

        $completedHandover->refresh();
        $reconcilingHandover->refresh();
        $approval->refresh();

        $this->assertArrayNotHasKey('candidate_email', $completedHandover->payload_snapshot);
        $this->assertArrayNotHasKey('candidate_first_name', $completedHandover->payload_snapshot);
        $this->assertArrayNotHasKey('candidate_last_name', $completedHandover->payload_snapshot);
        $this->assertArrayNotHasKey('candidate_phone', $completedHandover->payload_snapshot);
        $this->assertTrue($completedHandover->payload_snapshot['snapshot_minimized'] ?? false);
        $this->assertSame('HND-PA02-COMPLETED-001', $completedHandover->payload_snapshot['idempotency_key'] ?? null);
        $this->assertSame($legalEntity->code, $completedHandover->payload_snapshot['legal_entity_code'] ?? null);
        $this->assertSame($offerVersion->snapshot_hash, $completedHandover->payload_snapshot['offer_snapshot_hash'] ?? null);
        $this->assertSame('D365-EMP-9001', $completedHandover->external_employee_id);
        $this->assertArrayNotHasKey('candidate_email', $approval->approved_payload_snapshot);

        // Reconciling handover retains operational payload for reconciliation
        $this->assertSame('clara.oswald@example.com', $reconcilingHandover->payload_snapshot['candidate_email'] ?? null);

        // Idempotency: second minimization call returns false and preserves minimized snapshot
        $this->assertFalse($candidateService->minimizeCompletedHandoverSnapshot($completedHandover));

        // Verify retention_expires_at enforcement and legal-hold exclusion in recruitment:enforce-privacy-retention
        $expiredCandidate = $candidateService->upsertCandidateWithConsent([
            'first_name' => 'Expired',
            'last_name' => 'Retention',
            'email' => 'expired.retention@example.com',
            'consent_given' => true,
        ], (int) $this->acme->id, $this->acmeAdmin);
        $expiredCandidate->update([
            'retention_expires_at' => now()->subDay(),
            'updated_at' => now(),
        ]);

        $legalHoldExpiredCandidate = $candidateService->upsertCandidateWithConsent([
            'first_name' => 'LegalHold',
            'last_name' => 'Retention',
            'email' => 'legalhold.retention@example.com',
            'consent_given' => true,
        ], (int) $this->acme->id, $this->acmeAdmin);
        $legalHoldExpiredCandidate->update([
            'retention_expires_at' => now()->subDay(),
            'talent_pool_tag' => 'legal_hold',
        ]);

        Artisan::call('recruitment:enforce-privacy-retention');

        $this->assertSame('RETENTION_REVIEW_DUE', $expiredCandidate->fresh()?->dsar_status);
        $this->assertSame('NONE', $legalHoldExpiredCandidate->fresh()?->dsar_status);

        // Verify public privacy policy page discloses deleted/anonymized vs minimized handover retention and retention_expires_at
        $privacyResponse = $this->get('/legal/privacy-policy');
        $privacyResponse->assertOk();
        $privacyResponse->assertSee('retention_expires_at', false);
        $privacyResponse->assertSee('Minimized Statutory Onboarding', false);
        $privacyResponse->assertSee('external_employee_id', false);
        $privacyResponse->assertSee('LEGAL_HOLD', false);
    }
}
