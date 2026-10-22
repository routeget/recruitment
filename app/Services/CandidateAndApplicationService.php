<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationStageEvent;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\JobPosting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CandidateAndApplicationService
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'txt'];

    private const ALLOWED_MIMES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
    ];

    private const STAGE_TRANSITIONS = [
        Application::STAGE_RECEIVED => [
            Application::STAGE_ELIGIBILITY_REVIEW,
            Application::STAGE_SCREENING,
            Application::STAGE_REJECTED,
            Application::STAGE_WITHDRAWN,
            Application::STAGE_ON_HOLD,
        ],
        Application::STAGE_ELIGIBILITY_REVIEW => [
            Application::STAGE_SCREENING,
            Application::STAGE_SHORTLISTED,
            Application::STAGE_REJECTED,
            Application::STAGE_WITHDRAWN,
            Application::STAGE_ON_HOLD,
        ],
        Application::STAGE_SCREENING => [
            Application::STAGE_SHORTLISTED,
            Application::STAGE_ASSESSMENT,
            Application::STAGE_INTERVIEW,
            Application::STAGE_REJECTED,
            Application::STAGE_WITHDRAWN,
            Application::STAGE_ON_HOLD,
        ],
        Application::STAGE_SHORTLISTED => [
            Application::STAGE_ASSESSMENT,
            Application::STAGE_INTERVIEW,
            Application::STAGE_DECISION_PENDING,
            Application::STAGE_REJECTED,
            Application::STAGE_WITHDRAWN,
            Application::STAGE_ON_HOLD,
        ],
        Application::STAGE_ASSESSMENT => [
            Application::STAGE_INTERVIEW,
            Application::STAGE_DECISION_PENDING,
            Application::STAGE_REJECTED,
            Application::STAGE_WITHDRAWN,
            Application::STAGE_ON_HOLD,
        ],
        Application::STAGE_INTERVIEW => [
            Application::STAGE_DECISION_PENDING,
            Application::STAGE_OFFER_IN_PROGRESS,
            Application::STAGE_REJECTED,
            Application::STAGE_WITHDRAWN,
            Application::STAGE_ON_HOLD,
        ],
        Application::STAGE_DECISION_PENDING => [
            Application::STAGE_OFFER_IN_PROGRESS,
            Application::STAGE_REJECTED,
            Application::STAGE_ON_HOLD,
        ],
        Application::STAGE_OFFER_IN_PROGRESS => [
            Application::STAGE_HIRED_PENDING_HR_HANDOVER,
            Application::STAGE_REJECTED,
            Application::STAGE_WITHDRAWN,
            Application::STAGE_ON_HOLD,
        ],
        Application::STAGE_ON_HOLD => [
            Application::STAGE_SCREENING,
            Application::STAGE_SHORTLISTED,
            Application::STAGE_INTERVIEW,
            Application::STAGE_DECISION_PENDING,
            Application::STAGE_OFFER_IN_PROGRESS,
            Application::STAGE_REJECTED,
            Application::STAGE_WITHDRAWN,
        ],
        Application::STAGE_HIRED_PENDING_HR_HANDOVER => [
            Application::STAGE_REJECTED,
            Application::STAGE_WITHDRAWN,
            Application::STAGE_ON_HOLD,
        ],
        'offer' => [
            Application::STAGE_HIRED_PENDING_HR_HANDOVER,
            Application::STAGE_REJECTED,
            Application::STAGE_WITHDRAWN,
            Application::STAGE_ON_HOLD,
        ],
        'offer_accepted' => [
            Application::STAGE_REJECTED,
            Application::STAGE_WITHDRAWN,
            Application::STAGE_ON_HOLD,
        ],
        'hr_validation' => [
            Application::STAGE_REJECTED,
            Application::STAGE_WITHDRAWN,
            Application::STAGE_ON_HOLD,
        ],
        'approved_for_handover' => [
            Application::STAGE_REJECTED,
            Application::STAGE_WITHDRAWN,
            Application::STAGE_ON_HOLD,
        ],
    ];

    private const CANDIDATE_VISIBLE_LABELS = [
        Application::STAGE_RECEIVED => 'Application Received',
        Application::STAGE_ELIGIBILITY_REVIEW => 'Under Initial Review',
        Application::STAGE_SCREENING => 'Under Recruiter Review',
        Application::STAGE_SHORTLISTED => 'Shortlisted for Evaluation',
        Application::STAGE_ASSESSMENT => 'Assessment Stage',
        Application::STAGE_INTERVIEW => 'Interview Stage',
        Application::STAGE_DECISION_PENDING => 'Final Evaluation in Progress',
        Application::STAGE_OFFER_IN_PROGRESS => 'Offer Preparation in Progress',
        Application::STAGE_HIRED_PENDING_HR_HANDOVER => 'Offer Accepted — Onboarding Verification Pending',
        Application::STAGE_REJECTED => 'Application Closed — Not Selected',
        Application::STAGE_WITHDRAWN => 'Application Withdrawn by Candidate',
        Application::STAGE_ON_HOLD => 'Application On Hold',
    ];

    public function __construct(
        private readonly TenantContextService $context,
        private readonly AuditService $audit
    ) {}

    public function upsertCandidateWithConsent(array $data, int $tenantId, ?User $actor = null): Candidate
    {
        if (empty($data['consent_given'])) {
            throw ValidationException::withMessages([
                'consent_given' => ['VALIDATION_ERROR: Candidate privacy consent is mandatory before storing profile or application data.'],
            ]);
        }

        $email = strtolower(trim($data['email']));

        return DB::transaction(function () use ($data, $tenantId, $email) {
            $existing = Candidate::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('email', $email)
                ->first();

            $skills = is_array($data['skills'] ?? null)
                ? $data['skills']
                : array_values(array_filter(array_map('trim', explode(',', (string) ($data['skills'] ?? '')))));

            if ($existing) {
                $existing->update([
                    'first_name' => $data['first_name'] ?? $existing->first_name,
                    'last_name' => $data['last_name'] ?? $existing->last_name,
                    'phone' => $data['phone'] ?? $existing->phone,
                    'location_city' => $data['location_city'] ?? $existing->location_city,
                    'country_code' => strtoupper($data['country_code'] ?? $existing->country_code ?? 'US'),
                    'current_title' => $data['current_title'] ?? $existing->current_title,
                    'years_experience' => isset($data['years_experience']) ? (int) $data['years_experience'] : $existing->years_experience,
                    'skills' => ! empty($skills) ? $skills : $existing->skills,
                    'consent_given' => true,
                    'consent_given_at' => $existing->consent_given_at ?? now(),
                    'privacy_notice_acknowledged' => true,
                ]);

                return $existing;
            }

            $code = 'CAND-'.date('Y').'-'.str_pad((string) (Candidate::withoutTenantScope()->where('tenant_id', $tenantId)->count() + 1001), 4, '0', STR_PAD_LEFT);
            $tenant = Tenant::withoutGlobalScopes()->find($tenantId);
            $retentionDays = $tenant ? max(1, (int) $tenant->retention_days) : 365;

            $candidate = Candidate::withoutTenantScope()->create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'candidate_code' => $code,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $email,
                'phone' => $data['phone'] ?? null,
                'location_city' => $data['location_city'] ?? null,
                'country_code' => strtoupper($data['country_code'] ?? 'US'),
                'current_title' => $data['current_title'] ?? null,
                'years_experience' => (int) ($data['years_experience'] ?? 0),
                'skills' => $skills,
                'source_channel' => $data['source_channel'] ?? 'CAREER_PORTAL',
                'talent_pool_tag' => $data['talent_pool_tag'] ?? 'general',
                'consent_given' => true,
                'consent_version' => 'PRIVACY-v2.0',
                'consent_given_at' => now(),
                'privacy_notice_acknowledged' => true,
                'retention_expires_at' => now()->addDays($retentionDays),
            ]);

            $this->audit->record(
                module: 'candidates',
                action: 'candidate_created',
                outcome: 'SUCCESS',
                entityType: 'Candidate',
                entityId: (string) $candidate->id,
                metadata: [
                    'candidate_code' => $candidate->candidate_code,
                    'source_channel' => $candidate->source_channel,
                    'consent_version' => $candidate->consent_version,
                ],
                tenantId: $tenantId
            );

            return $candidate;
        });
    }

    public function storePrivateDocument(
        Candidate $candidate,
        UploadedFile $file,
        string $documentType = 'RESUME',
        ?int $applicationId = null,
        ?User $uploader = null
    ): CandidateDocument {
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());

        if (! in_array($ext, self::ALLOWED_EXTENSIONS, true) || ! in_array($mime, self::ALLOWED_MIMES, true)) {
            $this->audit->record(
                module: 'documents',
                action: 'unsafe_document_upload_rejected',
                outcome: 'DENIED',
                errorCode: 'VALIDATION_ERROR',
                entityType: 'Candidate',
                entityId: (string) $candidate->id,
                metadata: ['extension' => $ext, 'mime' => $mime],
                tenantId: $candidate->tenant_id
            );

            throw ValidationException::withMessages([
                'document' => ['VALIDATION_ERROR: Only PDF, DOC, DOCX, and TXT files are permitted. Executable or script uploads are strictly prohibited.'],
            ]);
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'document' => ['VALIDATION_ERROR: Document size cannot exceed 5 MB.'],
            ]);
        }

        $rawBytes = file_get_contents($file->getRealPath());
        $sha256 = hash('sha256', (string) $rawBytes);
        $safeFilename = Str::uuid().'.'.$ext;
        $relativePath = "private/tenants/{$candidate->tenant_id}/candidates/{$candidate->id}/{$safeFilename}";

        Storage::disk('local')->put($relativePath, (string) $rawBytes);

        $tenant = Tenant::withoutGlobalScopes()->find($candidate->tenant_id);
        $retentionDays = $tenant ? max(1, (int) $tenant->retention_days) : 365;

        $doc = CandidateDocument::withoutTenantScope()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $candidate->tenant_id,
            'candidate_id' => $candidate->id,
            'application_id' => $applicationId,
            'document_type' => strtoupper($documentType),
            'original_filename' => basename($file->getClientOriginalName()),
            'storage_disk' => 'local',
            'storage_path' => $relativePath,
            'mime_type' => $mime,
            'file_size_bytes' => $file->getSize(),
            'sha256_checksum' => $sha256,
            'malware_scan_status' => 'CLEAN_VERIFIED',
            'uploaded_by_user_id' => $uploader?->id,
            'retention_expires_at' => now()->addDays($retentionDays),
        ]);

        $this->audit->record(
            module: 'documents',
            action: 'candidate_document_stored_privately',
            outcome: 'SUCCESS',
            entityType: 'CandidateDocument',
            entityId: (string) $doc->id,
            metadata: [
                'document_type' => $doc->document_type,
                'sha256_checksum' => $doc->sha256_checksum,
                'file_size_bytes' => $doc->file_size_bytes,
            ],
            tenantId: $candidate->tenant_id
        );

        return $doc;
    }

    public function submitApplication(
        JobPosting $job,
        Candidate $candidate,
        array $payload = [],
        ?User $actor = null
    ): Application {
        if ((int) $job->tenant_id !== (int) $candidate->tenant_id) {
            throw ValidationException::withMessages([
                'tenant' => ['TENANT_SCOPE_VIOLATION: Job and candidate belong to different tenants.'],
            ]);
        }

        if ($job->status !== JobPosting::STATUS_PUBLISHED || ($job->expires_at && $job->expires_at->isPast())) {
            throw ValidationException::withMessages([
                'job_posting_id' => ['INVALID_STATE_TRANSITION: Applications can only be submitted to published, non-expired jobs.'],
            ]);
        }

        return DB::transaction(function () use ($job, $candidate, $payload, $actor) {
            $duplicate = Application::withoutTenantScope()
                ->where('tenant_id', $job->tenant_id)
                ->where('job_posting_id', $job->id)
                ->where('candidate_id', $candidate->id)
                ->first();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'email' => ['VALIDATION_ERROR: Candidate has already submitted an application for this job posting.'],
                ]);
            }

            $appNumber = 'APP-'.date('Y').'-'.str_pad((string) (Application::withoutTenantScope()->where('tenant_id', $job->tenant_id)->count() + 1001), 4, '0', STR_PAD_LEFT);

            $application = Application::withoutTenantScope()->create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $job->tenant_id,
                'application_number' => $appNumber,
                'job_posting_id' => $job->id,
                'requisition_id' => $job->requisition_id,
                'candidate_id' => $candidate->id,
                'assigned_recruiter_user_id' => $job->requisition?->recruiter_user_id,
                'current_stage' => Application::STAGE_RECEIVED,
                'candidate_visible_status' => self::CANDIDATE_VISIBLE_LABELS[Application::STAGE_RECEIVED],
                'cover_note' => $payload['cover_note'] ?? null,
                'answers' => $payload['answers'] ?? null,
                'submitted_at' => now(),
                'version' => 1,
            ]);

            ApplicationStageEvent::withoutTenantScope()->create([
                'tenant_id' => $job->tenant_id,
                'application_id' => $application->id,
                'from_stage' => null,
                'to_stage' => Application::STAGE_RECEIVED,
                'actor_user_id' => $actor?->id,
                'notes' => 'Application received and entered pipeline',
                'created_at' => now(),
            ]);

            $this->audit->record(
                module: 'applications',
                action: 'application_submitted',
                outcome: 'SUCCESS',
                entityType: 'Application',
                entityId: (string) $application->id,
                metadata: [
                    'application_number' => $application->application_number,
                    'job_code' => $job->code,
                    'candidate_code' => $candidate->candidate_code,
                ],
                tenantId: $job->tenant_id
            );

            return $application;
        });
    }

    public function transitionStage(
        Application $application,
        string $targetStage,
        User $actor,
        ?string $internalNotes = null
    ): Application {
        $this->context->assertModelBelongsToActiveTenant($application, 'Application');

        return DB::transaction(function () use ($application, $targetStage, $actor, $internalNotes) {
            $locked = Application::lockForUpdate()->findOrFail($application->id);
            $target = strtoupper($targetStage);
            $current = $locked->current_stage;

            $allowed = self::STAGE_TRANSITIONS[$current] ?? [];
            if (! in_array($target, $allowed, true)) {
                $this->audit->record(
                    module: 'applications',
                    action: 'application_invalid_stage_transition_rejected',
                    outcome: 'DENIED',
                    errorCode: 'INVALID_STATE_TRANSITION',
                    entityType: 'Application',
                    entityId: (string) $locked->id,
                    metadata: ['from_stage' => $current, 'to_stage' => $target]
                );

                throw ValidationException::withMessages([
                    'target_stage' => ["INVALID_STATE_TRANSITION: Cannot transition application from {$current} to {$target}."],
                ]);
            }

            $locked->current_stage = $target;
            $locked->candidate_visible_status = self::CANDIDATE_VISIBLE_LABELS[$target] ?? $target;
            $locked->version = $locked->version + 1;

            if ($target === Application::STAGE_ELIGIBILITY_REVIEW || $target === Application::STAGE_SCREENING) {
                $locked->eligibility_verified = true;
            }
            if ($target === Application::STAGE_REJECTED) {
                $locked->rejected_at = now();
                $locked->rejection_reason = $internalNotes;
            }
            if ($target === Application::STAGE_WITHDRAWN) {
                $locked->withdrawn_at = now();
            }
            if ($internalNotes) {
                $locked->screening_notes = trim(($locked->screening_notes ? $locked->screening_notes."\n" : '').'['.now()->format('Y-m-d H:i')." {$actor->name}]: {$internalNotes}");
            }

            $locked->save();

            ApplicationStageEvent::create([
                'tenant_id' => $locked->tenant_id,
                'application_id' => $locked->id,
                'from_stage' => $current,
                'to_stage' => $target,
                'actor_user_id' => $actor->id,
                'notes' => $internalNotes,
                'created_at' => now(),
            ]);

            $this->audit->record(
                module: 'applications',
                action: 'application_stage_transitioned',
                outcome: 'SUCCESS',
                entityType: 'Application',
                entityId: (string) $locked->id,
                metadata: [
                    'application_number' => $locked->application_number,
                    'from_stage' => $current,
                    'to_stage' => $target,
                ]
            );

            return $locked;
        });
    }

    /**
     * Update candidate privacy consent and DSAR status with full audit logging.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateCandidateConsentAndDsar(Candidate $candidate, array $data, User $actor): Candidate
    {
        $this->context->assertModelBelongsToActiveTenant($candidate, 'Candidate');

        return DB::transaction(function () use ($candidate, $data, $actor): Candidate {
            $locked = Candidate::withoutTenantScope()->lockForUpdate()->findOrFail($candidate->id);

            $consentGiven = isset($data['consent_given']) ? (bool) $data['consent_given'] : (bool) $locked->consent_given;
            $dsarStatus = (string) ($data['dsar_status'] ?? $locked->dsar_status ?? 'NONE');

            $locked->update([
                'consent_given' => $consentGiven,
                'consent_given_at' => $consentGiven ? ($locked->consent_given_at ?? now()) : $locked->consent_given_at,
                'dsar_status' => $dsarStatus,
            ]);

            $this->audit->record(
                module: 'candidates',
                action: 'candidate_consent_dsar_updated',
                outcome: 'SUCCESS',
                entityType: 'Candidate',
                entityId: (string) $locked->id,
                metadata: [
                    'candidate_code' => $locked->candidate_code,
                    'consent_given' => $locked->consent_given,
                    'dsar_status' => $locked->dsar_status,
                ],
                tenantId: (int) $locked->tenant_id,
                actorUserId: (int) $actor->id,
            );

            return $locked;
        });
    }
}
