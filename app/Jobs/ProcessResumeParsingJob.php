<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Candidate;
use App\Models\ResumeParseJob;
use App\Models\User;
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

        $tenantContext->setActiveTenantId((int) $parseJob->tenant_id);

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

        $parseJob->update([
            'status' => ResumeParseJob::STATUS_FAILED,
            'error_code' => 'QUEUE_WORKER_EXHAUSTED',
            'error_message' => substr($exception->getMessage(), 0, 450),
            'completed_at' => now(),
        ]);

        Candidate::withoutGlobalScopes()
            ->whereKey($parseJob->candidate_id)
            ->update(['resume_parse_status' => ResumeParseJob::STATUS_FAILED]);
    }
}
