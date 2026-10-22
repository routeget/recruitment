<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessResumeParsingJob;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\ResumeParseJob;
use App\Models\User;
use App\Services\Ai\ResumeContentAnalyzer;
use App\Services\Ai\ResumeTextExtractorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ResumeParsingService
{
    public function __construct(
        private readonly ResumeTextExtractorService $textExtractor,
        private readonly ResumeContentAnalyzer $contentAnalyzer,
        private readonly AiGatewayService $aiGateway,
        private readonly AuditService $auditService,
        private readonly TenantContextService $tenantContext,
    ) {}

    /**
     * Initiate or execute AI-assisted resume parsing for a stored CandidateDocument.
     *
     * @param  array<string, mixed>  $options
     */
    public function parseDocument(
        Candidate $candidate,
        CandidateDocument $document,
        ?int $applicationId = null,
        ?User $actor = null,
        bool $forceReprocess = false,
        bool $dispatchAsync = false,
        ?string $providerOverride = null,
        array $options = []
    ): ResumeParseJob {
        if ((int) $candidate->tenant_id !== (int) $document->tenant_id || (int) $document->candidate_id !== (int) $candidate->id) {
            throw ValidationException::withMessages([
                'document' => ['TENANT_OR_CANDIDATE_MISMATCH: Document does not belong to the specified candidate and tenant.'],
            ]);
        }

        if (! $candidate->consent_given) {
            throw ValidationException::withMessages([
                'consent_given' => ['CONSENT_REQUIRED: Active candidate privacy consent is required before AI resume processing.'],
            ]);
        }

        $baseIdempotencyKey = "resume-parse-doc-{$document->id}-{$document->sha256_checksum}";

        if (! $forceReprocess) {
            /** @var ResumeParseJob|null $existing */
            $existing = ResumeParseJob::withoutGlobalScopes()
                ->where('tenant_id', $candidate->tenant_id)
                ->where('idempotency_key', $baseIdempotencyKey)
                ->first();

            if ($existing !== null && in_array($existing->status, [
                ResumeParseJob::STATUS_COMPLETED,
                ResumeParseJob::STATUS_REVIEW_REQUIRED,
                ResumeParseJob::STATUS_PROCESSING,
                ResumeParseJob::STATUS_QUEUED,
            ], true)) {
                return $existing;
            }

            if ($existing !== null) {
                return $this->executeParseJob($existing, $actor, $providerOverride, $options);
            }
        }

        $idempotencyKey = $forceReprocess
            ? $baseIdempotencyKey.'-reprocess-'.Str::lower(Str::random(8))
            : $baseIdempotencyKey;

        $parseJob = ResumeParseJob::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $candidate->tenant_id,
            'candidate_id' => $candidate->id,
            'candidate_document_id' => $document->id,
            'application_id' => $applicationId ?? $document->application_id,
            'idempotency_key' => $idempotencyKey,
            'status' => $dispatchAsync ? ResumeParseJob::STATUS_QUEUED : ResumeParseJob::STATUS_UPLOADED,
            'schema_version' => ResumeParseJob::SCHEMA_VERSION,
            'attempt_count' => 0,
            'max_attempts' => 3,
        ]);

        $candidate->update(['resume_parse_status' => $parseJob->status]);

        if ($dispatchAsync) {
            ProcessResumeParsingJob::dispatch($parseJob->id, $actor?->id, $providerOverride);

            return $parseJob;
        }

        return $this->executeParseJob($parseJob, $actor, $providerOverride, $options);
    }

    /**
     * Execute extraction, prompt-injection defense, AI task execution, schema validation, and profile persistence.
     *
     * @param  array<string, mixed>  $options
     */
    public function executeParseJob(
        ResumeParseJob $parseJob,
        ?User $actor = null,
        ?string $providerOverride = null,
        array $options = []
    ): ResumeParseJob {
        $candidate = Candidate::withoutGlobalScopes()->findOrFail($parseJob->candidate_id);
        $document = CandidateDocument::withoutGlobalScopes()->findOrFail($parseJob->candidate_document_id);

        if ((int) $parseJob->tenant_id !== (int) $candidate->tenant_id || (int) $parseJob->tenant_id !== (int) $document->tenant_id) {
            throw new RuntimeException('TENANT_SCOPE_VIOLATION: Cross-tenant resume parse execution blocked.', 403);
        }

        $parseJob->update([
            'status' => ResumeParseJob::STATUS_PROCESSING,
            'attempt_count' => $parseJob->attempt_count + 1,
            'started_at' => now(),
            'error_code' => null,
            'error_message' => null,
        ]);

        try {
            $extracted = $this->textExtractor->extractFromDocument($document);
        } catch (RuntimeException $e) {
            $parts = explode(':', $e->getMessage(), 2);
            $errorCode = trim($parts[0]) ?: 'EXTRACTION_FAILED';
            $errorMessage = trim($parts[1] ?? $e->getMessage());

            $parseJob->update([
                'status' => ResumeParseJob::STATUS_FAILED,
                'error_code' => substr($errorCode, 0, 60),
                'error_message' => $errorMessage,
                'completed_at' => now(),
            ]);
            $candidate->update(['resume_parse_status' => ResumeParseJob::STATUS_FAILED]);

            $this->auditService->record(
                module: 'resume_parsing',
                action: 'resume.extraction_failed',
                outcome: 'FAILED',
                errorCode: substr($errorCode, 0, 60),
                entityType: 'ResumeParseJob',
                entityId: (string) $parseJob->id,
                metadata: [
                    'candidate_id' => $candidate->id,
                    'document_id' => $document->id,
                    'error_code' => substr($errorCode, 0, 60),
                ],
                tenantId: (int) $candidate->tenant_id,
                actorUserId: $actor?->id,
            );

            return $parseJob->fresh();
        }

        // Scanned PDF without OCR text layer -> honest REVIEW_REQUIRED state without fabricating data
        if ($extracted['requires_manual_review']) {
            $parseJob->update([
                'status' => ResumeParseJob::STATUS_REVIEW_REQUIRED,
                'is_scanned_document' => $extracted['is_scanned_document'],
                'ocr_used' => $extracted['ocr_used'],
                'extracted_text_length' => 0,
                'error_code' => $extracted['warning_code'] ?? 'SCANNED_DOCUMENT_OCR_UNAVAILABLE',
                'error_message' => $extracted['warning_message'] ?? 'Scanned document requires manual profile review.',
                'completed_at' => now(),
            ]);
            $candidate->update(['resume_parse_status' => ResumeParseJob::STATUS_REVIEW_REQUIRED]);

            $this->auditService->record(
                module: 'resume_parsing',
                action: 'resume.manual_review_required',
                outcome: 'SUCCESS',
                errorCode: $parseJob->error_code,
                entityType: 'ResumeParseJob',
                entityId: (string) $parseJob->id,
                metadata: [
                    'candidate_id' => $candidate->id,
                    'document_id' => $document->id,
                    'is_scanned_document' => true,
                ],
                tenantId: (int) $candidate->tenant_id,
                actorUserId: $actor?->id,
            );

            return $parseJob->fresh();
        }

        $rawText = $extracted['text'];
        $injection = $this->contentAnalyzer->detectAndNeutralizePromptInjection($rawText);

        if ($injection['detected']) {
            $this->auditService->record(
                module: 'resume_parsing',
                action: 'resume.prompt_injection_neutralized',
                outcome: 'DENIED',
                errorCode: 'PROMPT_INJECTION_DETECTED',
                entityType: 'ResumeParseJob',
                entityId: (string) $parseJob->id,
                metadata: [
                    'candidate_id' => $candidate->id,
                    'document_id' => $document->id,
                    'flags_count' => count($injection['flags']),
                ],
                tenantId: (int) $candidate->tenant_id,
                actorUserId: $actor?->id,
            );
        }

        // Pre-extract non-secret contact details from clean text before PII redaction masks email in external prompts
        $localBaseline = $this->contentAnalyzer->extractStructuredResume($injection['clean_text']);
        $isolatedPromptInput = "<untrusted_resume_document>\n".$injection['clean_text']."\n</untrusted_resume_document>";

        try {
            $taskRun = $this->aiGateway->executeTask(
                taskType: 'resume.extract',
                providerKey: $providerOverride ?: 'auto',
                rawInputText: $isolatedPromptInput,
                actor: $actor,
                tenantId: (int) $candidate->tenant_id,
                options: array_merge($options, [
                    'entity_type' => 'ResumeParseJob',
                    'entity_id' => $parseJob->id,
                ])
            );
        } catch (Throwable $e) {
            $parts = explode(':', $e->getMessage(), 2);
            $errorCode = trim($parts[0]) ?: 'AI_PROVIDER_ERROR';
            $errorMessage = trim($parts[1] ?? $e->getMessage());

            $isRetryable = in_array($errorCode, ['PROVIDER_TIMEOUT', 'PROVIDER_UNAVAILABLE', 'PROVIDER_RATE_LIMITED'], true)
                && $parseJob->attempt_count < $parseJob->max_attempts;

            $newStatus = $isRetryable ? ResumeParseJob::STATUS_RETRY_SCHEDULED : ResumeParseJob::STATUS_FAILED;

            $parseJob->update([
                'status' => $newStatus,
                'prompt_injection_detected' => $injection['detected'],
                'security_flags' => $injection['flags'],
                'extracted_text_length' => strlen($injection['clean_text']),
                'error_code' => substr($errorCode, 0, 60),
                'error_message' => substr($errorMessage, 0, 500),
                'next_retry_at' => $isRetryable ? now()->addMinutes(5) : null,
                'completed_at' => now(),
            ]);

            $candidate->update(['resume_parse_status' => $newStatus]);

            return $parseJob->fresh();
        }

        $aiOutput = is_array($taskRun->structured_output) ? $taskRun->structured_output : [];
        $normalizedData = $this->normalizeAndValidateResumeSchema($aiOutput, $localBaseline, $injection);

        if (! $normalizedData['valid']) {
            $parseJob->update([
                'ai_task_run_id' => $taskRun->id,
                'provider_key' => $taskRun->provider_key,
                'model_name' => $taskRun->model_name,
                'execution_mode' => $taskRun->execution_mode,
                'status' => ResumeParseJob::STATUS_FAILED,
                'prompt_injection_detected' => $injection['detected'],
                'security_flags' => $injection['flags'],
                'extracted_text_length' => strlen($injection['clean_text']),
                'validation_errors' => $normalizedData['errors'],
                'error_code' => 'RESUME_SCHEMA_VALIDATION_FAILED',
                'error_message' => implode('; ', $normalizedData['errors']),
                'completed_at' => now(),
            ]);
            $candidate->update(['resume_parse_status' => ResumeParseJob::STATUS_FAILED]);

            return $parseJob->fresh();
        }

        $structured = $normalizedData['data'];
        $needsReview = $injection['detected']
            || ! empty($structured['ambiguous_fields'])
            || count((array) ($structured['missing_fields'] ?? [])) >= 6;

        $finalStatus = $needsReview ? ResumeParseJob::STATUS_REVIEW_REQUIRED : ResumeParseJob::STATUS_COMPLETED;

        return DB::transaction(function () use (
            $parseJob,
            $candidate,
            $taskRun,
            $extracted,
            $injection,
            $structured,
            $finalStatus,
            $actor
        ): ResumeParseJob {
            $parseJob->update([
                'ai_task_run_id' => $taskRun->id,
                'provider_key' => $taskRun->provider_key,
                'model_name' => $taskRun->model_name,
                'execution_mode' => $taskRun->execution_mode,
                'status' => $finalStatus,
                'schema_version' => ResumeParseJob::SCHEMA_VERSION,
                'ocr_used' => $extracted['ocr_used'],
                'is_scanned_document' => $extracted['is_scanned_document'],
                'prompt_injection_detected' => $injection['detected'],
                'security_flags' => $injection['flags'],
                'extracted_text_length' => strlen($injection['clean_text']),
                'parsed_data' => $structured,
                'missing_fields' => $structured['missing_fields'] ?? [],
                'validation_errors' => [],
                'error_code' => null,
                'error_message' => null,
                'completed_at' => now(),
            ]);

            $this->syncParsedDataToCandidateProfile($candidate, $structured, $finalStatus);

            $this->auditService->record(
                module: 'resume_parsing',
                action: 'resume.parsed_successfully',
                outcome: 'SUCCESS',
                entityType: 'ResumeParseJob',
                entityId: (string) $parseJob->id,
                metadata: [
                    'candidate_id' => $candidate->id,
                    'status' => $finalStatus,
                    'provider_key' => $taskRun->provider_key,
                    'model_name' => $taskRun->model_name,
                    'schema_version' => ResumeParseJob::SCHEMA_VERSION,
                    'prompt_injection_detected' => $injection['detected'],
                ],
                tenantId: (int) $candidate->tenant_id,
                actorUserId: $actor?->id,
            );

            return $parseJob->fresh();
        });
    }

    /**
     * Normalize and validate structured resume output against RESUME_SCHEMA_v1.0 without inventing missing fields.
     *
     * @param  array<string, mixed>  $aiOutput
     * @param  array<string, mixed>  $localBaseline
     * @param  array{clean_text: string, detected: bool, flags: array<int, string>}  $injection
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>}
     */
    public function normalizeAndValidateResumeSchema(array $aiOutput, array $localBaseline, array $injection): array
    {
        if (! array_key_exists('contact', $aiOutput) && (array_key_exists('emails', $aiOutput) || array_key_exists('phones', $aiOutput))) {
            $aiOutput['contact'] = [
                'email' => $aiOutput['emails'][0] ?? null,
                'phone' => $aiOutput['phones'][0] ?? null,
                'location_city' => $aiOutput['location'] ?? null,
                'country_code' => $aiOutput['country_code'] ?? null,
                'links' => $aiOutput['professional_links'] ?? [],
            ];
        }

        $errors = [];
        $requiredTopKeys = ['full_name', 'contact', 'professional_summary', 'skills', 'work_experience', 'education'];
        foreach ($requiredTopKeys as $rk) {
            if (! array_key_exists($rk, $aiOutput)) {
                $errors[] = "Missing required schema key '{$rk}'.";
            }
        }

        if (! empty($errors)) {
            return ['valid' => false, 'data' => [], 'errors' => $errors];
        }

        if (! is_array($aiOutput['skills']) || ! is_array($aiOutput['work_experience']) || ! is_array($aiOutput['education'])) {
            return [
                'valid' => false,
                'data' => [],
                'errors' => ['Schema fields skills, work_experience, and education must be arrays.'],
            ];
        }

        // Restore unredacted email from local baseline if AI output had [REDACTED_EMAIL]
        $contact = is_array($aiOutput['contact'] ?? null) ? $aiOutput['contact'] : [];
        if (($contact['email'] ?? null) === '[REDACTED_EMAIL]' || empty($contact['email'])) {
            $contact['email'] = $localBaseline['contact']['email'] ?? null;
        }
        $contact['phone'] = $contact['phone'] ?? ($localBaseline['contact']['phone'] ?? null);
        $contact['location_city'] = $contact['location_city'] ?? ($localBaseline['contact']['location_city'] ?? null);
        $contact['country_code'] = $contact['country_code'] ?? ($localBaseline['contact']['country_code'] ?? null);
        $contact['links'] = is_array($contact['links'] ?? null) ? $contact['links'] : ($localBaseline['contact']['links'] ?? []);

        $fullName = isset($aiOutput['full_name']) && is_string($aiOutput['full_name']) && trim($aiOutput['full_name']) !== ''
            ? trim($aiOutput['full_name'])
            : null;
        $firstName = $aiOutput['first_name'] ?? null;
        $lastName = $aiOutput['last_name'] ?? null;
        if ($fullName !== null && ($firstName === null || $lastName === null)) {
            $parts = preg_split('/\s+/', $fullName, 2) ?: [];
            $firstName = $parts[0] ?? null;
            $lastName = $parts[1] ?? null;
        }

        $yearsExp = isset($aiOutput['years_experience']) && is_numeric($aiOutput['years_experience'])
            ? (int) $aiOutput['years_experience']
            : (isset($aiOutput['total_years_experience']) && is_numeric($aiOutput['total_years_experience'])
                ? (int) $aiOutput['total_years_experience']
                : ($localBaseline['years_experience'] ?? null));

        $missingFields = is_array($aiOutput['missing_fields'] ?? null)
            ? array_values($aiOutput['missing_fields'])
            : ($localBaseline['missing_fields'] ?? []);

        if (empty($contact['phone']) && ! in_array('phones', $missingFields, true)) {
            $missingFields[] = 'phones';
        }
        if (empty($aiOutput['education']) && ! in_array('education', $missingFields, true)) {
            $missingFields[] = 'education';
        }

        $data = [
            'schema_version' => ResumeParseJob::SCHEMA_VERSION,
            'full_name' => $fullName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'contact' => $contact,
            'emails' => ! empty($contact['email']) ? [$contact['email']] : [],
            'phones' => ! empty($contact['phone']) ? [$contact['phone']] : [],
            'location' => $contact['location_city'] ?? null,
            'country_code' => $contact['country_code'] ?? null,
            'professional_summary' => isset($aiOutput['professional_summary']) && is_string($aiOutput['professional_summary']) && trim($aiOutput['professional_summary']) !== ''
                ? trim($aiOutput['professional_summary'])
                : null,
            'current_title' => isset($aiOutput['current_title']) && is_string($aiOutput['current_title']) && trim($aiOutput['current_title']) !== ''
                ? trim($aiOutput['current_title'])
                : ($localBaseline['current_title'] ?? null),
            'years_experience' => $yearsExp,
            'total_years_experience' => $yearsExp,
            'skills' => array_values(array_filter(array_map('strval', $aiOutput['skills']))),
            'work_experience' => array_values($aiOutput['work_experience']),
            'education' => array_values($aiOutput['education']),
            'certifications' => is_array($aiOutput['certifications'] ?? null) ? array_values($aiOutput['certifications']) : ($localBaseline['certifications'] ?? []),
            'projects' => is_array($aiOutput['projects'] ?? null) ? array_values($aiOutput['projects']) : ($localBaseline['projects'] ?? []),
            'languages' => is_array($aiOutput['languages'] ?? null) ? array_values($aiOutput['languages']) : ($localBaseline['languages'] ?? []),
            'professional_links' => is_array($aiOutput['professional_links'] ?? null) ? array_values($aiOutput['professional_links']) : ($contact['links'] ?? []),
            'missing_fields' => array_values(array_unique($missingFields)),
            'ambiguous_fields' => is_array($aiOutput['ambiguous_fields'] ?? null) ? array_values($aiOutput['ambiguous_fields']) : ($localBaseline['ambiguous_fields'] ?? []),
            'evidence_quotes' => is_array($aiOutput['evidence_quotes'] ?? null) ? array_values($aiOutput['evidence_quotes']) : ($localBaseline['evidence_quotes'] ?? []),
            'prompt_injection_detected' => $injection['detected'],
            'security_flags' => $injection['flags'],
        ];

        return ['valid' => true, 'data' => $data, 'errors' => []];
    }

    /**
     * Safely populate Candidate profile with extracted fields without overwriting existing valid fields with null.
     *
     * @param  array<string, mixed>  $structured
     */
    private function syncParsedDataToCandidateProfile(Candidate $candidate, array $structured, string $status): void
    {
        $updates = [
            'parsed_resume_data' => $structured,
            'resume_parse_status' => $status,
            'resume_parsed_at' => now(),
            'resume_schema_version' => ResumeParseJob::SCHEMA_VERSION,
        ];

        if (! empty($structured['professional_summary'])) {
            $updates['professional_summary'] = $structured['professional_summary'];
        }
        if (! empty($structured['current_title'])) {
            $updates['current_title'] = $structured['current_title'];
        }
        if (isset($structured['years_experience']) && is_int($structured['years_experience'])) {
            $updates['years_experience'] = $structured['years_experience'];
        }
        if (! empty($structured['skills']) && is_array($structured['skills'])) {
            $mergedSkills = array_values(array_unique(array_filter(array_merge(
                is_array($candidate->skills) ? $candidate->skills : [],
                $structured['skills']
            ))));
            $updates['skills'] = $mergedSkills;
        }
        if (! empty($structured['work_experience'])) {
            $updates['work_experience'] = $structured['work_experience'];
        }
        if (! empty($structured['education'])) {
            $updates['education'] = $structured['education'];
        }
        if (! empty($structured['certifications'])) {
            $updates['certifications'] = $structured['certifications'];
        }
        if (! empty($structured['projects'])) {
            $updates['projects'] = $structured['projects'];
        }
        if (! empty($structured['languages'])) {
            $updates['languages'] = $structured['languages'];
        }
        if (! empty($structured['professional_links'])) {
            $updates['professional_links'] = $structured['professional_links'];
        }
        if (! empty($structured['contact']['phone']) && empty($candidate->phone)) {
            $updates['phone'] = $structured['contact']['phone'];
        }
        if (! empty($structured['contact']['location_city']) && empty($candidate->location_city)) {
            $updates['location_city'] = $structured['contact']['location_city'];
        }

        $candidate->update($updates);
    }

    /**
     * Convenience wrapper for applying human review and corrections from web/API controllers.
     *
     * @param  array<string, mixed>  $correctedPayload
     */
    public function applyCorrections(
        ResumeParseJob $parseJob,
        Candidate $candidate,
        array $correctedPayload,
        ?User $reviewer = null,
        bool $isCandidateReview = false
    ): ResumeParseJob {
        if ((int) $parseJob->candidate_id !== (int) $candidate->id) {
            throw new RuntimeException('PARSE_JOB_CANDIDATE_MISMATCH: Parse job does not belong to candidate.', 403);
        }

        if (! empty($correctedPayload['full_name']) && (empty($correctedPayload['first_name']) || empty($correctedPayload['last_name']))) {
            $parts = preg_split('/\s+/', trim((string) $correctedPayload['full_name']), 2) ?: [];
            $correctedPayload['first_name'] = $parts[0] ?? $candidate->first_name;
            $correctedPayload['last_name'] = $parts[1] ?? $candidate->last_name;
        }
        if (isset($correctedPayload['location']) && ! isset($correctedPayload['location_city'])) {
            $correctedPayload['location_city'] = $correctedPayload['location'];
        }
        if (isset($correctedPayload['total_years_experience']) && ! isset($correctedPayload['years_experience'])) {
            $correctedPayload['years_experience'] = $correctedPayload['total_years_experience'];
        }

        return $this->applyHumanReviewAndCorrections($parseJob, $correctedPayload, $reviewer, $isCandidateReview);
    }

    /**
     * Apply human review and corrections (by either the candidate or an authorized tenant recruiter).
     *
     * @param  array<string, mixed>  $corrections
     */
    public function applyHumanReviewAndCorrections(
        ResumeParseJob $parseJob,
        array $corrections,
        ?User $reviewer = null,
        bool $isCandidateReview = false
    ): ResumeParseJob {
        $candidate = Candidate::withoutGlobalScopes()->findOrFail($parseJob->candidate_id);

        if ((int) $parseJob->tenant_id !== (int) $candidate->tenant_id) {
            throw new RuntimeException('TENANT_SCOPE_VIOLATION: Cross-tenant review blocked.', 403);
        }

        if (! $candidate->consent_given) {
            throw ValidationException::withMessages([
                'consent_given' => ['CONSENT_REQUIRED: Candidate privacy consent must be active before saving reviewed profile data.'],
            ]);
        }

        $skills = is_array($corrections['skills'] ?? null)
            ? array_values(array_filter(array_map('trim', $corrections['skills'])))
            : array_values(array_filter(array_map('trim', explode(',', (string) ($corrections['skills'] ?? '')))));

        $certifications = is_array($corrections['certifications'] ?? null)
            ? array_values(array_filter(array_map('trim', $corrections['certifications'])))
            : array_values(array_filter(array_map('trim', explode(',', (string) ($corrections['certifications'] ?? '')))));

        $languages = is_array($corrections['languages'] ?? null)
            ? array_values(array_filter(array_map('trim', $corrections['languages'])))
            : array_values(array_filter(array_map('trim', explode(',', (string) ($corrections['languages'] ?? '')))));

        $baseline = is_array($parseJob->parsed_data) ? $parseJob->parsed_data : [];
        $yearsExp = isset($corrections['years_experience']) && $corrections['years_experience'] !== ''
            ? (int) $corrections['years_experience']
            : ($baseline['years_experience'] ?? $candidate->years_experience);

        $correctedPayload = array_merge($baseline, [
            'first_name' => trim((string) ($corrections['first_name'] ?? $candidate->first_name)),
            'last_name' => trim((string) ($corrections['last_name'] ?? $candidate->last_name)),
            'full_name' => trim(trim((string) ($corrections['first_name'] ?? $candidate->first_name)).' '.trim((string) ($corrections['last_name'] ?? $candidate->last_name))),
            'current_title' => isset($corrections['current_title']) && trim((string) $corrections['current_title']) !== ''
                ? trim((string) $corrections['current_title'])
                : ($baseline['current_title'] ?? $candidate->current_title),
            'years_experience' => $yearsExp,
            'total_years_experience' => $yearsExp,
            'professional_summary' => isset($corrections['professional_summary']) && trim((string) $corrections['professional_summary']) !== ''
                ? trim((string) $corrections['professional_summary'])
                : ($baseline['professional_summary'] ?? $candidate->professional_summary),
            'skills' => ! empty($skills) ? $skills : ($baseline['skills'] ?? $candidate->skills ?? []),
            'certifications' => ! empty($certifications) ? $certifications : ($baseline['certifications'] ?? $candidate->certifications ?? []),
            'languages' => ! empty($languages) ? $languages : ($baseline['languages'] ?? $candidate->languages ?? []),
            'human_reviewed' => true,
            'reviewed_at' => now()->toIso8601String(),
        ]);

        return DB::transaction(function () use ($parseJob, $candidate, $corrections, $correctedPayload, $reviewer, $isCandidateReview): ResumeParseJob {
            $parseJob->update([
                'status' => ResumeParseJob::STATUS_COMPLETED,
                'corrected_data' => $correctedPayload,
                'candidate_reviewed' => $isCandidateReview ? true : $parseJob->candidate_reviewed,
                'reviewed_by_user_id' => $reviewer?->id ?? $parseJob->reviewed_by_user_id,
                'reviewed_at' => now(),
            ]);

            if ($parseJob->ai_task_run_id && $reviewer !== null) {
                $taskRun = $parseJob->aiTaskRun;
                if ($taskRun !== null && ! $taskRun->human_reviewed) {
                    $this->aiGateway->reviewTaskRun($taskRun, $reviewer);
                }
            }

            $candidate->update([
                'first_name' => $correctedPayload['first_name'] ?: $candidate->first_name,
                'last_name' => $correctedPayload['last_name'] ?: $candidate->last_name,
                'phone' => isset($corrections['phone']) && trim((string) $corrections['phone']) !== '' ? trim((string) $corrections['phone']) : $candidate->phone,
                'location_city' => isset($corrections['location_city']) && trim((string) $corrections['location_city']) !== '' ? trim((string) $corrections['location_city']) : $candidate->location_city,
                'current_title' => $correctedPayload['current_title'],
                'years_experience' => (int) ($correctedPayload['years_experience'] ?? 0),
                'professional_summary' => $correctedPayload['professional_summary'],
                'skills' => $correctedPayload['skills'],
                'certifications' => $correctedPayload['certifications'],
                'languages' => $correctedPayload['languages'],
                'parsed_resume_data' => $correctedPayload,
                'resume_parse_status' => ResumeParseJob::STATUS_COMPLETED,
            ]);

            $this->auditService->record(
                module: 'resume_parsing',
                action: 'resume.parse_reviewed_and_applied',
                outcome: 'SUCCESS',
                entityType: 'ResumeParseJob',
                entityId: (string) $parseJob->id,
                metadata: [
                    'candidate_id' => $candidate->id,
                    'reviewer_type' => $isCandidateReview ? 'CANDIDATE' : 'TENANT_USER',
                ],
                tenantId: (int) $candidate->tenant_id,
                actorUserId: $reviewer?->id,
            );

            return $parseJob->fresh();
        });
    }

    /**
     * Run explainable AI-assisted candidate-to-job matching without automatically advancing or rejecting the application.
     */
    public function evaluateApplicationMatch(
        Application $application,
        User|Candidate|null $candidate = null,
        User|string|null $actor = 'auto',
        string $providerKey = 'auto'
    ): Application {
        $resolvedActor = $candidate instanceof User
            ? $candidate
            : ($actor instanceof User ? $actor : null);
        $resolvedProviderKey = is_string($actor) && $actor !== '' ? $actor : $providerKey;
        $actor = $resolvedActor;
        $providerKey = $resolvedProviderKey;

        $application->loadMissing(['candidate.latestResumeParseJob', 'jobPosting']);
        $candidate = $application->candidate;
        $job = $application->jobPosting;

        if ($candidate === null || $job === null || (int) $application->tenant_id !== (int) $candidate->tenant_id || (int) $application->tenant_id !== (int) $job->tenant_id) {
            throw new RuntimeException('TENANT_SCOPE_VIOLATION: Application, candidate, and job posting must belong to the same tenant.', 403);
        }

        $resumeData = $candidate->latestResumeParseJob?->getEffectiveResumeData()
            ?: (is_array($candidate->parsed_resume_data) ? $candidate->parsed_resume_data : [
                'skills' => $candidate->skills ?? [],
                'years_experience' => $candidate->years_experience,
                'current_title' => $candidate->current_title,
                'education' => $candidate->education ?? [],
                'work_experience' => $candidate->work_experience ?? [],
            ]);

        $jobRequirements = [
            'title' => $job->title,
            'experience_level' => $job->experience_level,
            'skills_required' => $job->skills_required ?? [],
            'qualifications' => $job->qualifications,
        ];

        $promptSummary = "Job Title: {$job->title}\nRequired Skills: ".implode(', ', (array) ($job->skills_required ?? []))."\n"
            .'Candidate Title: '.($candidate->current_title ?? 'Not stated')."\n"
            .'Candidate Years Experience: '.($candidate->years_experience ?? 'Not stated')."\n"
            .'Candidate Skills: '.implode(', ', (array) ($resumeData['skills'] ?? $candidate->skills ?? []));

        $taskRun = $this->aiGateway->executeTask(
            taskType: 'candidate.match',
            providerKey: $providerKey,
            rawInputText: $promptSummary,
            actor: $actor,
            tenantId: (int) $application->tenant_id,
            options: [
                'resume_data' => $resumeData,
                'job_requirements' => $jobRequirements,
                'entity_type' => 'Application',
                'entity_id' => $application->id,
            ]
        );

        $matchOutput = is_array($taskRun->structured_output) ? $taskRun->structured_output : [];
        $score = isset($matchOutput['overall_score']) && is_numeric($matchOutput['overall_score'])
            ? (float) $matchOutput['overall_score']
            : null;

        $application->update([
            'ai_match_score' => $score,
            'ai_match_data' => array_merge($matchOutput, [
                'ai_task_run_id' => $taskRun->id,
                'provider_key' => $taskRun->provider_key,
                'model_name' => $taskRun->model_name,
                'evaluated_at' => now()->toIso8601String(),
            ]),
            'ai_match_reviewed' => false,
            'ai_match_reviewed_by_user_id' => null,
            'ai_match_reviewed_at' => null,
        ]);

        $this->auditService->record(
            module: 'resume_matching',
            action: 'application.ai_match_evaluated',
            outcome: 'SUCCESS',
            entityType: 'Application',
            entityId: (string) $application->id,
            metadata: [
                'application_number' => $application->application_number,
                'ai_task_run_id' => $taskRun->id,
                'provider_key' => $taskRun->provider_key,
                'overall_score' => $score,
                'stage_unchanged' => $application->current_stage,
            ],
            tenantId: (int) $application->tenant_id,
            actorUserId: $actor?->id,
        );

        return $application->fresh();
    }

    public function reviewApplicationMatch(Application $application, User $actor): Application
    {
        $application->update([
            'ai_match_reviewed' => true,
            'ai_match_reviewed_by_user_id' => $actor->id,
            'ai_match_reviewed_at' => now(),
        ]);

        $this->auditService->record(
            module: 'resume_matching',
            action: 'application.ai_match_reviewed',
            outcome: 'SUCCESS',
            entityType: 'Application',
            entityId: (string) $application->id,
            metadata: [
                'application_number' => $application->application_number,
                'ai_match_score' => $application->ai_match_score,
            ],
            tenantId: (int) $application->tenant_id,
            actorUserId: (int) $actor->id,
        );

        return $application->fresh(['aiMatchReviewer']);
    }
}
