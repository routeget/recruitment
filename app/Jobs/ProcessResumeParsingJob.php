<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ResumeParseJob;
use App\Models\User;
use App\Services\ResumeParsingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessResumeParsingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly int $resumeParseJobId,
        public readonly ?int $actorUserId = null,
        public readonly ?string $providerOverride = null,
    ) {}

    public function uniqueId(): string
    {
        return 'resume-parse-job-'.$this->resumeParseJobId;
    }

    public function handle(ResumeParsingService $parsingService): void
    {
        /** @var ResumeParseJob|null $parseJob */
        $parseJob = ResumeParseJob::withoutGlobalScopes()->find($this->resumeParseJobId);
        if ($parseJob === null) {
            return;
        }

        if (in_array($parseJob->status, [ResumeParseJob::STATUS_COMPLETED, ResumeParseJob::STATUS_PROCESSING], true)) {
            return;
        }

        $actor = $this->actorUserId ? User::withoutGlobalScopes()->find($this->actorUserId) : null;
        $parsingService->executeParseJob($parseJob, $actor, $this->providerOverride);
    }
}
