<?php

declare(strict_types=1);

use App\Jobs\ProcessResumeParsingJob;
use App\Models\Candidate;
use App\Models\HrHandover;
use App\Models\JobPosting;
use App\Models\ResumeParseJob;
use App\Services\AuditService;
use App\Services\CandidateAndApplicationService;
use App\Services\PlatformConfigService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('recruitment:process-scheduled-parse-retries', function (AuditService $audit): void {
    $exhaustedJobs = ResumeParseJob::withoutGlobalScopes()
        ->whereIn('status', [ResumeParseJob::STATUS_RETRY_SCHEDULED, ResumeParseJob::STATUS_QUEUED])
        ->whereColumn('attempt_count', '>=', 'max_attempts')
        ->limit(100)
        ->get();

    foreach ($exhaustedJobs as $exhaustedJob) {
        $exhaustedJob->update([
            'status' => ResumeParseJob::STATUS_FAILED,
            'error_code' => 'MAX_RETRIES_EXHAUSTED',
            'error_message' => 'Retry budget exhausted without successful completion.',
            'next_retry_at' => null,
            'completed_at' => now(),
        ]);

        $audit->record(
            module: 'candidates',
            action: 'resume_parse_retry_exhausted',
            outcome: 'FAILED',
            entityType: 'ResumeParseJob',
            entityId: (string) $exhaustedJob->id,
            metadata: [
                'attempt_count' => $exhaustedJob->attempt_count,
                'max_attempts' => $exhaustedJob->max_attempts,
            ],
            tenantId: (int) $exhaustedJob->tenant_id,
            actorType: 'SYSTEM_SCHEDULER',
            actorIdentifier: 'recruitment:process-scheduled-parse-retries',
        );
    }

    $blockedPrivacyJobs = ResumeParseJob::withoutGlobalScopes()
        ->whereIn('status', [ResumeParseJob::STATUS_RETRY_SCHEDULED, ResumeParseJob::STATUS_QUEUED])
        ->where(function ($query): void {
            $query->whereDoesntHave('candidate', function ($q): void {
                $q->withoutGlobalScopes();
            })->orWhereHas('candidate', function ($q): void {
                $q->withoutGlobalScopes()->where(function ($sub): void {
                    $sub->where('consent_given', false)
                        ->orWhereIn('dsar_status', [
                            'ANONYMIZED',
                            'DELETION_REQUESTED',
                            'OBJECTION_RECORDED',
                            'PROCESSING_RESTRICTED',
                            'LEGAL_HOLD',
                        ]);
                });
            });
        })
        ->limit(100)
        ->get();

    foreach ($blockedPrivacyJobs as $blockedJob) {
        $candidate = Candidate::withoutGlobalScopes()->find($blockedJob->candidate_id);
        $isAnonymized = $candidate === null || $candidate->dsar_status === 'ANONYMIZED';

        $updatePayload = [
            'status' => ResumeParseJob::STATUS_FAILED,
            'error_code' => $isAnonymized ? 'DSAR_ANONYMIZED' : 'CONSENT_OR_DSAR_BLOCKED',
            'error_message' => $isAnonymized
                ? 'Candidate profile anonymized under DSAR erasure.'
                : 'Scheduled retry cancelled because candidate privacy consent is inactive or DSAR restriction applies.',
            'next_retry_at' => null,
            'completed_at' => now(),
        ];

        if ($isAnonymized) {
            $updatePayload['candidate_document_id'] = null;
            $updatePayload['parsed_data'] = null;
            $updatePayload['corrected_data'] = null;
            $updatePayload['validation_errors'] = [];
            $updatePayload['missing_fields'] = [];
            $updatePayload['security_flags'] = [];
            $updatePayload['extracted_text_length'] = 0;
        }

        $blockedJob->update($updatePayload);

        $audit->record(
            module: 'candidates',
            action: 'resume_parse_retry_cancelled_privacy',
            outcome: 'BLOCKED',
            entityType: 'ResumeParseJob',
            entityId: (string) $blockedJob->id,
            metadata: [
                'error_code' => $updatePayload['error_code'],
            ],
            tenantId: (int) $blockedJob->tenant_id,
            actorType: 'SYSTEM_SCHEDULER',
            actorIdentifier: 'recruitment:process-scheduled-parse-retries',
        );
    }

    $dueJobs = ResumeParseJob::withoutGlobalScopes()
        ->where('status', ResumeParseJob::STATUS_RETRY_SCHEDULED)
        ->whereNotNull('next_retry_at')
        ->where('next_retry_at', '<=', now())
        ->whereColumn('attempt_count', '<', 'max_attempts')
        ->whereHas('candidate', function ($q): void {
            $q->withoutGlobalScopes()
                ->where('consent_given', true)
                ->whereNotIn('dsar_status', [
                    'ANONYMIZED',
                    'DELETION_REQUESTED',
                    'OBJECTION_RECORDED',
                    'PROCESSING_RESTRICTED',
                    'LEGAL_HOLD',
                ]);
        })
        ->orderBy('next_retry_at')
        ->limit(50)
        ->get();

    foreach ($dueJobs as $job) {
        ProcessResumeParsingJob::dispatch((int) $job->id);
    }

    $this->info(
        'Dispatched '.$dueJobs->count().' scheduled resume parse retry job(s); '
        .'marked '.$exhaustedJobs->count().' exhausted; '
        .'cancelled '.$blockedPrivacyJobs->count().' privacy-restricted job(s).'
    );
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

Artisan::command('recruitment:enforce-privacy-retention', function (
    PlatformConfigService $config,
    AuditService $audit,
    CandidateAndApplicationService $candidateService,
): void {
    $retentionDays = max(30, $config->getInt('security.min_retention_days'));
    $cutoff = now()->subDays($retentionDays);

    $expiredCandidates = Candidate::withoutGlobalScopes()
        ->where('dsar_status', 'NONE')
        ->where(function ($q): void {
            $q->whereNull('talent_pool_tag')
                ->orWhereNotIn('talent_pool_tag', ['legal_hold', 'active_dispute', 'statutory_hold']);
        })
        ->where(function ($q) use ($cutoff): void {
            $q->where(function ($sub): void {
                $sub->whereNotNull('retention_expires_at')
                    ->where('retention_expires_at', '<=', now());
            })->orWhere(function ($sub) use ($cutoff): void {
                $sub->whereNull('retention_expires_at')
                    ->where('updated_at', '<=', $cutoff);
            });
        })
        ->whereDoesntHave('applications', function ($q): void {
            $q->withoutGlobalScopes()->whereNotIn('current_stage', ['REJECTED', 'WITHDRAWN']);
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
                'retention_expires_at' => $candidate->retention_expires_at?->toIso8601String(),
            ],
            tenantId: (int) $candidate->tenant_id,
            actorType: 'SYSTEM_SCHEDULER',
            actorIdentifier: 'recruitment:enforce-privacy-retention',
        );
    }

    $eligibleHandovers = HrHandover::withoutGlobalScopes()
        ->whereIn('status', ['succeeded', 'reconciled', 'HANDOVER_COMPLETED', 'COMPLETED'])
        ->where('requires_reconciliation', false)
        ->whereNotNull('external_employee_id')
        ->where(function ($q) use ($cutoff): void {
            $q->where(function ($sub) use ($cutoff): void {
                $sub->whereNotNull('completed_at')
                    ->where('completed_at', '<=', $cutoff);
            })->orWhereHas('validationCase.application.candidate', function ($candidateQuery): void {
                $candidateQuery->withoutGlobalScopes()->where(function ($sub): void {
                    $sub->where('dsar_status', 'ANONYMIZED')
                        ->orWhere(function ($ret): void {
                            $ret->whereNotNull('retention_expires_at')
                                ->where('retention_expires_at', '<=', now());
                        });
                });
            });
        })
        ->whereDoesntHave('validationCase.application.candidate', function ($candidateQuery): void {
            $candidateQuery->withoutGlobalScopes()->where(function ($sub): void {
                $sub->whereIn('dsar_status', ['LEGAL_HOLD', 'DISPUTE_ACTIVE'])
                    ->orWhereIn('talent_pool_tag', ['legal_hold', 'active_dispute', 'statutory_hold']);
            });
        })
        ->limit(100)
        ->get();

    $minimizedHandoversCount = 0;
    foreach ($eligibleHandovers as $handover) {
        if ($candidateService->minimizeCompletedHandoverSnapshot($handover, 'SYSTEM_SCHEDULER', 'recruitment:enforce-privacy-retention')) {
            $minimizedHandoversCount++;
        }
    }

    $this->info(
        'Flagged '.$expiredCandidates->count().' candidate profile(s) exceeding the '.$retentionDays.'-day retention window; '
        .'minimized '.$minimizedHandoversCount.' completed HR handover snapshot(s).'
    );
})->purpose('Flag inactive candidate profiles exceeding privacy retention and minimize eligible completed HR handover snapshots');

Artisan::command('recruitment:audit-backup-retention {--prune-approved} {--dir=}', function (AuditService $audit): void {
    $backupDir = (string) ($this->option('dir') ?: '/home/career/backups/databases');
    $pruneApproved = (bool) $this->option('prune-approved');
    $retentionDays = max(7, (int) env('BACKUP_RETENTION_DAYS', 14));
    $cutoffTimestamp = now()->subDays($retentionDays)->getTimestamp();

    $legalHoldActive = Candidate::withoutGlobalScopes()
        ->where(function ($q): void {
            $q->whereIn('dsar_status', ['LEGAL_HOLD', 'DISPUTE_ACTIVE'])
                ->orWhereIn('talent_pool_tag', ['legal_hold', 'active_dispute', 'statutory_hold']);
        })
        ->exists();

    if (! is_dir($backupDir)) {
        $this->warn('Backup directory not found: '.$backupDir);

        return;
    }

    $files = glob(rtrim($backupDir, '/').'/*.sql') ?: [];
    usort($files, static fn (string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));

    $validBackups = [];
    $permissionWarnings = [];

    foreach ($files as $filePath) {
        $perms = substr(sprintf('%o', (int) fileperms($filePath)), -4);
        if ($perms !== '0600') {
            $permissionWarnings[] = basename($filePath).':'.$perms;
        }

        $size = (int) filesize($filePath);
        if ($size > 0) {
            $tail = (string) shell_exec('tail -n 5 '.escapeshellarg($filePath));
            if (str_contains($tail, 'Dump completed on')) {
                $validBackups[] = $filePath;
            }
        }
    }

    $newestValidBackup = $validBackups[0] ?? null;
    $expiredEligible = [];
    $prunedFiles = [];

    foreach ($files as $filePath) {
        if ($filePath === $newestValidBackup) {
            continue;
        }

        $mtime = (int) filemtime($filePath);
        if ($mtime <= $cutoffTimestamp) {
            $expiredEligible[] = basename($filePath);

            if ($pruneApproved && ! $legalHoldActive && $newestValidBackup !== null) {
                if (@unlink($filePath)) {
                    $prunedFiles[] = basename($filePath);
                }
            }
        }
    }

    $audit->record(
        module: 'governance',
        action: $pruneApproved ? 'backup_retention_pruned' : 'backup_retention_audit',
        outcome: 'SUCCESS',
        entityType: 'DatabaseBackupInventory',
        entityId: 'databases',
        metadata: [
            'mode' => $pruneApproved ? 'PRUNE_APPROVED' : 'DRY_RUN',
            'retention_days' => $retentionDays,
            'total_files' => count($files),
            'valid_backups' => count($validBackups),
            'newest_valid_backup' => $newestValidBackup ? basename($newestValidBackup) : null,
            'expired_eligible_count' => count($expiredEligible),
            'pruned_count' => count($prunedFiles),
            'legal_hold_active' => $legalHoldActive,
            'permission_warnings_count' => count($permissionWarnings),
        ],
        tenantId: null,
        actorType: 'SYSTEM_SCHEDULER',
        actorIdentifier: 'recruitment:audit-backup-retention',
    );

    $this->info(
        'Backup retention audit complete (mode='.($pruneApproved ? 'PRUNE_APPROVED' : 'DRY_RUN')
        .', total='.count($files)
        .', valid='.count($validBackups)
        .', expired_eligible='.count($expiredEligible)
        .', pruned='.count($prunedFiles)
        .', legal_hold='.($legalHoldActive ? 'ACTIVE' : 'NONE').').'
    );
})->purpose('Audit database backup inventory, permissions, 14-day retention eligibility, and legal-hold safeguards');

Schedule::command('recruitment:process-scheduled-parse-retries')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

Schedule::command('recruitment:expire-past-due-jobs')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

Schedule::command('recruitment:enforce-privacy-retention')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

Schedule::command('recruitment:audit-backup-retention')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));
