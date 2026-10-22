<?php

namespace App\Services;

use App\Models\JobPosting;
use App\Models\JobPostingVersion;
use App\Models\Requisition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class JobPublishingService
{
    public function __construct(
        private readonly TenantContextService $context,
        private readonly AuditService $audit
    ) {}

    public function createJobPosting(array $data, User $actor): JobPosting
    {
        $tenantId = $this->context->getActiveTenantId();
        $requisition = Requisition::withoutTenantScope()->findOrFail((int) $data['requisition_id']);
        $this->context->assertModelBelongsToActiveTenant($requisition, 'Requisition');

        return DB::transaction(function () use ($data, $actor, $tenantId, $requisition) {
            $code = 'JOB-'.date('Y').'-'.str_pad((string) (JobPosting::count() + 101), 4, '0', STR_PAD_LEFT);
            $baseSlug = Str::slug($data['title']).'-'.strtolower($code);

            $skills = is_array($data['skills_required'] ?? null)
                ? $data['skills_required']
                : array_values(array_filter(array_map('trim', explode(',', (string) ($data['skills_required'] ?? '')))));

            $job = JobPosting::create([
                'tenant_id' => $tenantId,
                'requisition_id' => $requisition->id,
                'slug' => $baseSlug,
                'code' => $code,
                'title' => $data['title'],
                'department_id' => $requisition->department_id,
                'location_id' => $requisition->location_id,
                'employment_type' => $data['employment_type'] ?? $requisition->employment_type,
                'work_mode' => $data['work_mode'] ?? 'HYBRID',
                'experience_level' => $data['experience_level'] ?? 'SENIOR',
                'summary' => $data['summary'],
                'responsibilities' => $data['responsibilities'],
                'qualifications' => $data['qualifications'],
                'skills_required' => $skills,
                'compensation_min' => $data['compensation_min'] ?? $requisition->salary_min,
                'compensation_max' => $data['compensation_max'] ?? $requisition->salary_max,
                'currency_code' => $requisition->currency_code,
                'show_compensation_publicly' => (bool) ($data['show_compensation_publicly'] ?? true),
                'status' => JobPosting::STATUS_DRAFT,
                'current_version' => 1,
                'external_channels_config' => [
                    'linkedin_jobs' => ['status' => 'UNAVAILABLE_UNCONFIGURED', 'reason' => 'External connector credentials not configured'],
                    'indeed_feed' => ['status' => 'UNAVAILABLE_UNCONFIGURED', 'reason' => 'External connector credentials not configured'],
                ],
                'seo_title' => $data['seo_title'] ?? ($data['title'].' | Careers'),
                'seo_description' => $data['seo_description'] ?? Str::limit($data['summary'], 155),
            ]);

            JobPostingVersion::create([
                'tenant_id' => $tenantId,
                'job_posting_id' => $job->id,
                'version_number' => 1,
                'snapshot' => [
                    'title' => $job->title,
                    'summary' => $job->summary,
                    'responsibilities' => $job->responsibilities,
                    'qualifications' => $job->qualifications,
                    'skills_required' => $job->skills_required,
                ],
                'change_summary' => 'Initial job description version',
                'created_by_user_id' => $actor->id,
                'created_at' => now(),
            ]);

            $this->audit->record(
                module: 'jobs',
                action: 'job_posting_created',
                outcome: 'SUCCESS',
                entityType: 'JobPosting',
                entityId: (string) $job->id,
                metadata: ['code' => $job->code, 'requisition_id' => $requisition->id]
            );

            return $job;
        });
    }

    public function approveAndPublish(JobPosting $job, User $actor, bool $publishImmediately = true): JobPosting
    {
        $this->context->assertModelBelongsToActiveTenant($job, 'JobPosting');

        return DB::transaction(function () use ($job, $actor, $publishImmediately) {
            $locked = JobPosting::with('requisition')->lockForUpdate()->findOrFail($job->id);

            if (! in_array($locked->requisition?->status, [Requisition::STATUS_APPROVED, Requisition::STATUS_OPEN], true)) {
                $this->audit->record(
                    module: 'jobs',
                    action: 'job_publish_blocked_unapproved_requisition',
                    outcome: 'DENIED',
                    errorCode: 'APPROVAL_REQUIRED',
                    entityType: 'JobPosting',
                    entityId: (string) $locked->id,
                    metadata: ['requisition_status' => $locked->requisition?->status]
                );

                throw ValidationException::withMessages([
                    'requisition_id' => ['APPROVAL_REQUIRED: Parent workforce requisition must be APPROVED or OPEN before publishing a job posting.'],
                ]);
            }

            $locked->approved_version = $locked->current_version;
            $locked->approved_by_user_id = $actor->id;
            $locked->approved_at = now();

            if ($publishImmediately) {
                $locked->status = JobPosting::STATUS_PUBLISHED;
                $locked->published_at = now();
                $locked->expires_at = $locked->expires_at ?? now()->addDays(60);
            } else {
                $locked->status = JobPosting::STATUS_APPROVED;
            }

            $locked->save();

            $this->audit->record(
                module: 'jobs',
                action: $publishImmediately ? 'job_posting_published' : 'job_posting_approved',
                outcome: 'SUCCESS',
                entityType: 'JobPosting',
                entityId: (string) $locked->id,
                metadata: [
                    'code' => $locked->code,
                    'status' => $locked->status,
                    'approved_version' => $locked->approved_version,
                ]
            );

            return $locked;
        });
    }

    public function transitionPublicationStatus(JobPosting $job, string $targetStatus, User $actor): JobPosting
    {
        $this->context->assertModelBelongsToActiveTenant($job, 'JobPosting');

        return DB::transaction(function () use ($job, $targetStatus) {
            $locked = JobPosting::lockForUpdate()->findOrFail($job->id);
            $target = strtoupper($targetStatus);

            if ($target === JobPosting::STATUS_PUBLISHED && ! $locked->approved_version) {
                throw ValidationException::withMessages([
                    'status' => ['APPROVAL_REQUIRED: Job posting must be approved before publication.'],
                ]);
            }

            $locked->status = $target;
            if ($target === JobPosting::STATUS_CLOSED) {
                $locked->closed_at = now();
            }
            $locked->save();

            $this->audit->record(
                module: 'jobs',
                action: 'job_status_changed',
                outcome: 'SUCCESS',
                entityType: 'JobPosting',
                entityId: (string) $locked->id,
                metadata: ['status' => $target]
            );

            return $locked;
        });
    }
}
