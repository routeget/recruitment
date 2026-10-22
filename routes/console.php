<?php

declare(strict_types=1);

use App\Jobs\ProcessResumeParsingJob;
use App\Models\Candidate;
use App\Models\JobPosting;
use App\Models\ResumeParseJob;
use App\Services\AuditService;
use App\Services\PlatformConfigService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('recruitment:process-scheduled-parse-retries', function (): void {
    $dueJobs = ResumeParseJob::withoutGlobalScopes()
        ->where('status', ResumeParseJob::STATUS_RETRY_SCHEDULED)
        ->whereNotNull('next_retry_at')
        ->where('next_retry_at', '<=', now())
        ->whereColumn('attempt_count', '<', 'max_attempts')
        ->orderBy('next_retry_at')
        ->limit(50)
        ->get();

    foreach ($dueJobs as $job) {
        ProcessResumeParsingJob::dispatch((int) $job->id);
    }

    $this->info('Dispatched '.$dueJobs->count().' scheduled resume parse retry job(s).');
})->purpose('Dispatch scheduled AI resume parse retries that have reached next_retry_at');

Artisan::command('recruitment:expire-past-due-jobs', function (AuditService $audit): void {
    $expiredJobs = JobPosting::withoutGlobalScopes()
        ->where('status', JobPosting::STATUS_PUBLISHED)
        ->whereNotNull('expires_at')
        ->where('expires_at', '<=', now())
        ->limit(200)
        ->get();

    foreach ($expiredJobs as $job) {
        $job->update(['status' => JobPosting::STATUS_EXPIRED]);
        $audit->record(
            module: 'jobs',
            action: 'job_posting_auto_expired',
            outcome: 'SUCCESS',
            entityType: 'JobPosting',
            entityId: (string) $job->id,
            metadata: ['code' => $job->code, 'slug' => $job->slug],
            tenantId: (int) $job->tenant_id,
            actorType: 'SYSTEM_SCHEDULER',
            actorIdentifier: 'recruitment:expire-past-due-jobs',
        );
    }

    $this->info('Transitioned '.$expiredJobs->count().' past-due published job posting(s) to EXPIRED.');
})->purpose('Transition past-due published job postings to EXPIRED status');

Artisan::command('recruitment:enforce-privacy-retention', function (PlatformConfigService $config, AuditService $audit): void {
    $retentionDays = max(30, $config->getInt('security.min_retention_days'));
    $cutoff = now()->subDays($retentionDays);

    $expiredCandidates = Candidate::withoutGlobalScopes()
        ->where('dsar_status', 'NONE')
        ->where('updated_at', '<=', $cutoff)
        ->whereDoesntHave('applications', function ($q) {
            $q->whereNotIn('current_stage', ['REJECTED', 'WITHDRAWN']);
        })
        ->limit(100)
        ->get();

    foreach ($expiredCandidates as $candidate) {
        $candidate->update(['dsar_status' => 'RETENTION_REVIEW_DUE']);
        $audit->record(
            module: 'candidates',
            action: 'candidate_retention_review_flagged',
            outcome: 'SUCCESS',
            entityType: 'Candidate',
            entityId: (string) $candidate->id,
            metadata: [
                'candidate_code' => $candidate->candidate_code,
                'retention_days' => $retentionDays,
            ],
            tenantId: (int) $candidate->tenant_id,
            actorType: 'SYSTEM_SCHEDULER',
            actorIdentifier: 'recruitment:enforce-privacy-retention',
        );
    }

    $this->info('Flagged '.$expiredCandidates->count().' candidate profile(s) exceeding the '.$retentionDays.'-day retention window.');
})->purpose('Flag inactive candidate profiles exceeding the governed privacy retention window');

Schedule::command('recruitment:process-scheduled-parse-retries')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('recruitment:expire-past-due-jobs')->hourly()->withoutOverlapping();
Schedule::command('recruitment:enforce-privacy-retention')->dailyAt('02:30')->withoutOverlapping();
