<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Candidate;
use App\Models\ResumeParseJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\ResumeContentAnalyzer;
use App\Services\AuditService;
use App\Services\ResumeParsingService;
use App\Services\TenantContextService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessResumeParsingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @var array<int, int>
     */
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly int $resumeParseJobId,
        public readonly ?int $actorUserId = null,
        public readonly ?string $providerOverride = null,
    ) {}

    public function uniqueId(): string
    {
        return 'resume-parse-job-'.$this->resumeParseJobId;
    }

    public function handle(ResumeParsingService $parsingService, TenantContextService $tenantContext): void
    {
        /** @var ResumeParseJob|null $parseJob */
        $parseJob = ResumeParseJob::withoutGlobalScopes()->find($this->resumeParseJobId);
        if ($parseJob === null) {
            return;
        }

        if (in_array($parseJob->status, [ResumeParseJob::STATUS_COMPLETED, ResumeParseJob::STATUS_PROCESSING], true)) {
            return;
        }

        if (
            $parseJob->status === ResumeParseJob::STATUS_FAILED
            && in_array((string) $parseJob->error_code, ['DSAR_ANONYMIZED', 'CONSENT_OR_DSAR_BLOCKED', 'MAX_RETRIES_EXHAUSTED'], true)
        ) {
            return;
        }

        $tenantContext->setActiveTenantId((int) $parseJob->tenant_id);

        $tenant = Tenant::withoutGlobalScopes()->find($parseJob->tenant_id);
        if ($tenant === null || $tenant->status !== 'ACTIVE') {
            $parseJob->update([
                'status' => ResumeParseJob::STATUS_FAILED,
                'error_code' => 'TENANT_SUSPENDED',
                'error_message' => 'Queued resume parsing cancelled because tenant is not active.',
                'next_retry_at' => null,
                'completed_at' => now(),
            ]);

            return;
        }

        $candidate = Candidate::withoutGlobalScopes()->find($parseJob->candidate_id);
        if (
            $candidate === null
            || ! $candidate->consent_given
            || in_array((string) $candidate->dsar_status, ['RESTRICTION_REQUESTED', 'DELETION_REQUESTED', 'ANONYMIZED'], true)
        ) {
            $isAnonymized = $candidate !== null && (string) $candidate->dsar_status === 'ANONYMIZED';
            $errorCode = $isAnonymized ? 'DSAR_ANONYMIZED' : 'CONSENT_OR_DSAR_BLOCKED';
            $errorMessage = $isAnonymized
                ? 'Candidate data anonymized per DSAR request.'
                : 'Execution blocked: candidate privacy consent withdrawn or DSAR restriction/deletion active.';

            $parseJob->update([
                'candidate_document_id' => $isAnonymized ? null : $parseJob->candidate_document_id,
                'status' => ResumeParseJob::STATUS_FAILED,
                'parsed_data' => null,
                'corrected_data' => $isAnonymized ? null : $parseJob->corrected_data,
                'validation_errors' => [],
                'missing_fields' => [],
                'security_flags' => [],
                'extracted_text_length' => $isAnonymized ? 0 : $parseJob->extracted_text_length,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'next_retry_at' => null,
                'completed_at' => now(),
            ]);

            if ($candidate !== null) {
                $candidate->update(['resume_parse_status' => ResumeParseJob::STATUS_FAILED]);
                app(AuditService::class)->record(
                    module: 'resume_parsing',
                    action: 'resume.parse_blocked_by_privacy_state',
                    outcome: 'DENIED',
                    errorCode: $errorCode,
                    entityType: 'ResumeParseJob',
                    entityId: (string) $parseJob->id,
                    metadata: [
                        'candidate_id' => $candidate->id,
                        'consent_given' => (bool) $candidate->consent_given,
                        'dsar_status' => (string) $candidate->dsar_status,
                        'cancelled_from_queue' => true,
                    ],
                    tenantId: (int) $parseJob->tenant_id,
                    actorType: 'SYSTEM_QUEUE',
                    actorIdentifier: 'ProcessResumeParsingJob',
                );
            }

            return;
        }

        $actor = $this->actorUserId ? User::withoutGlobalScopes()->find($this->actorUserId) : null;
        $parsingService->executeParseJob($parseJob, $actor, $this->providerOverride);
    }

    public function failed(Throwable $exception): void
    {
        /** @var ResumeParseJob|null $parseJob */
        $parseJob = ResumeParseJob::withoutGlobalScopes()->find($this->resumeParseJobId);
        if ($parseJob === null) {
            return;
        }

        if (in_array((string) $parseJob->error_code, ['DSAR_ANONYMIZED', 'CONSENT_OR_DSAR_BLOCKED'], true)) {
            return;
        }

        $sanitizedError = app(ResumeContentAnalyzer::class)->redactDirectContactIdentifiers($exception->getMessage());

        $parseJob->update([
            'status' => ResumeParseJob::STATUS_FAILED,
            'error_code' => 'QUEUE_WORKER_EXHAUSTED',
            'error_message' => substr($sanitizedError, 0, 450),
            'next_retry_at' => null,
            'completed_at' => now(),
        ]);

        Candidate::withoutGlobalScopes()
            ->whereKey($parseJob->candidate_id)
            ->update(['resume_parse_status' => ResumeParseJob::STATUS_FAILED]);
    }
}
