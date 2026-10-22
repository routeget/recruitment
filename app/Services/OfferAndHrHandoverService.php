<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\HrHandoverGateNotSatisfiedException;
use App\Models\Application;
use App\Models\ApplicationStageEvent;
use App\Models\ExternalEmployeeReference;
use App\Models\HrHandover;
use App\Models\HrHandoverApproval;
use App\Models\HrValidationCase;
use App\Models\Offer;
use App\Models\OfferTemplate;
use App\Models\OfferVersion;
use App\Models\TransactionalOutboxEvent;
use App\Models\User;
use App\Support\ApiClientPrincipal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OfferAndHrHandoverService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createOfferTemplate(array $data, User $actor): OfferTemplate
    {
        return DB::transaction(function () use ($data, $actor): OfferTemplate {
            $template = OfferTemplate::query()->create([
                'tenant_id' => (int) $data['tenant_id'],
                'code' => strtoupper(trim((string) $data['code'])),
                'name' => trim((string) $data['name']),
                'body_template' => $data['body_template'],
                'compensation_rules' => $data['compensation_rules'] ?? ['max_bonus_pct' => 25],
                'is_active' => true,
            ]);

            $this->auditService->record(
                module: 'offers',
                action: 'offer_template.created',
                entityType: 'offer_template',
                entityId: (string) $template->id,
                metadata: ['code' => $template->code],
                tenantId: (int) $template->tenant_id,
                actorUserId: (int) $actor->id,
            );

            return $template;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createOffer(Application $application, array $data, User $actor): Offer
    {
        return DB::transaction(function () use ($application, $data, $actor): Offer {
            $offer = Offer::query()->create([
                'tenant_id' => (int) $application->tenant_id,
                'application_id' => (int) $application->id,
                'status' => 'draft',
                'expires_at' => $data['expires_at'] ?? now()->addDays(14),
            ]);

            $version = $this->createOfferVersionRecord($offer, 1, $data, $actor);

            $offer->update([
                'current_version_id' => $version->id,
            ]);

            $this->auditService->record(
                module: 'offers',
                action: 'offer.created',
                entityType: 'offer',
                entityId: (string) $offer->id,
                metadata: [
                    'application_id' => $application->id,
                    'version_number' => 1,
                    'snapshot_hash' => $version->snapshot_hash,
                ],
                tenantId: (int) $offer->tenant_id,
                actorUserId: (int) $actor->id,
            );

            return $offer->fresh(['currentVersion', 'application.candidate']);
        });
    }

    public function approveAndIssueOffer(Offer $offer, User $actor): Offer
    {
        return DB::transaction(function () use ($offer, $actor): Offer {
            /** @var Offer $lockedOffer */
            $lockedOffer = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            /** @var OfferVersion $currentVersion */
            $currentVersion = OfferVersion::query()->whereKey($lockedOffer->current_version_id)->lockForUpdate()->firstOrFail();

            $currentVersion->update([
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
            ]);

            $lockedOffer->update([
                'status' => 'issued',
            ]);

            $application = Application::query()->whereKey($lockedOffer->application_id)->lockForUpdate()->firstOrFail();
            if ($application->current_stage !== 'offer') {
                $fromStage = $application->current_stage;
                $application->update(['current_stage' => 'offer', 'status' => 'ACTIVE']);
                ApplicationStageEvent::query()->create([
                    'tenant_id' => $application->tenant_id,
                    'application_id' => $application->id,
                    'from_stage' => $fromStage,
                    'to_stage' => 'offer',
                    'actor_user_id' => $actor->id,
                    'notes' => 'Offer v'.$currentVersion->version_number.' approved and issued.',
                    'created_at' => now(),
                ]);
            }

            $this->auditService->record(
                module: 'offers',
                action: 'offer.issued',
                entityType: 'offer',
                entityId: (string) $lockedOffer->id,
                metadata: [
                    'version_id' => $currentVersion->id,
                    'version_number' => $currentVersion->version_number,
                    'snapshot_hash' => $currentVersion->snapshot_hash,
                ],
                tenantId: (int) $lockedOffer->tenant_id,
                actorUserId: (int) $actor->id,
            );

            return $lockedOffer->fresh(['currentVersion']);
        });
    }

    /**
     * Rule HRH-01: Offer acceptance locks the accepted offer version and opens an HR validation case,
     * but NEVER creates an employee or dispatches an HR handover.
     */
    public function acceptOffer(Offer $offer, ?User $actor = null): Offer
    {
        return DB::transaction(function () use ($offer, $actor): Offer {
            /** @var Offer $lockedOffer */
            $lockedOffer = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if (! in_array($lockedOffer->status, ['issued', 'viewed', 'approved'], true)) {
                throw new HrHandoverGateNotSatisfiedException(
                    "Offer cannot be accepted from status '{$lockedOffer->status}'.",
                    'OFFER_INVALID_STATE'
                );
            }

            $lockedOffer->update([
                'status' => 'accepted',
                'accepted_version_id' => $lockedOffer->current_version_id,
                'accepted_at' => now(),
            ]);

            $application = Application::query()->whereKey($lockedOffer->application_id)->lockForUpdate()->firstOrFail();
            $fromStage = $application->current_stage;
            $application->update([
                'current_stage' => 'offer_accepted',
                'status' => 'ACTIVE',
            ]);

            ApplicationStageEvent::query()->create([
                'tenant_id' => $application->tenant_id,
                'application_id' => $application->id,
                'from_stage' => $fromStage,
                'to_stage' => 'offer_accepted',
                'actor_user_id' => $actor?->id,
                'notes' => 'Candidate accepted offer; awaiting mandatory HR validation & approval gate.',
                'created_at' => now(),
            ]);

            // Open or reset HR validation case in 'not_started' state.
            // Offer acceptance NEVER creates an HrHandover or ExternalEmployeeReference!
            $validationCase = HrValidationCase::query()->updateOrCreate(
                [
                    'tenant_id' => $lockedOffer->tenant_id,
                    'application_id' => $lockedOffer->application_id,
                ],
                [
                    'offer_version_id' => $lockedOffer->current_version_id,
                    'status' => 'not_started',
                    'invalidated_at' => null,
                    'invalidation_reason' => null,
                ]
            );

            $this->auditService->record(
                module: 'offers',
                action: 'offer.accepted',
                entityType: 'offer',
                entityId: (string) $lockedOffer->id,
                metadata: [
                    'accepted_version_id' => $lockedOffer->accepted_version_id,
                    'hr_validation_case_id' => $validationCase->id,
                    'hr_handover_triggered' => false,
                ],
                tenantId: (int) $lockedOffer->tenant_id,
                actorUserId: $actor ? (int) $actor->id : null,
            );

            return $lockedOffer->fresh(['currentVersion', 'acceptedVersion', 'hrValidationCase']);
        });
    }

    /**
     * Rule HRH-04 / HRH-06: Modifying material offer terms after HR validation invalidates validation
     * and any existing HR handover approvals, blocking handover until re-validated and re-approved.
     *
     * @param  array<string, mixed>  $data
     */
    public function reviseOfferMaterialTerms(Offer $offer, array $data, User $actor): OfferVersion
    {
        return DB::transaction(function () use ($offer, $data, $actor): OfferVersion {
            /** @var Offer $lockedOffer */
            $lockedOffer = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            /** @var OfferVersion $previousVersion */
            $previousVersion = OfferVersion::query()->whereKey($lockedOffer->current_version_id)->firstOrFail();

            $nextVersionNumber = ((int) $lockedOffer->versions()->max('version_number')) + 1;

            $mergedData = [
                'job_title' => $data['job_title'] ?? $previousVersion->job_title,
                'legal_entity_id' => $data['legal_entity_id'] ?? $previousVersion->legal_entity_id,
                'department_id' => $data['department_id'] ?? $previousVersion->department_id,
                'location_id' => $data['location_id'] ?? $previousVersion->location_id,
                'employment_type' => $data['employment_type'] ?? $previousVersion->employment_type,
                'base_salary_minor' => isset($data['base_salary_minor']) ? (int) $data['base_salary_minor'] : $previousVersion->base_salary_minor,
                'currency_code' => $data['currency_code'] ?? $previousVersion->currency_code,
                'bonus_terms' => $data['bonus_terms'] ?? $previousVersion->bonus_terms,
                'equity_terms' => $data['equity_terms'] ?? $previousVersion->equity_terms,
                'start_date' => $data['start_date'] ?? $previousVersion->start_date->format('Y-m-d'),
                'probation_months' => isset($data['probation_months']) ? (int) $data['probation_months'] : $previousVersion->probation_months,
            ];

            $newVersion = $this->createOfferVersionRecord($lockedOffer, $nextVersionNumber, $mergedData, $actor);

            $lockedOffer->update([
                'current_version_id' => $newVersion->id,
                'accepted_version_id' => $lockedOffer->status === 'accepted' ? $newVersion->id : $lockedOffer->accepted_version_id,
            ]);

            /** @var HrValidationCase|null $validationCase */
            $validationCase = HrValidationCase::query()
                ->where('tenant_id', $lockedOffer->tenant_id)
                ->where('application_id', $lockedOffer->application_id)
                ->lockForUpdate()
                ->first();

            if ($validationCase !== null) {
                $validationCase->update([
                    'offer_version_id' => $newVersion->id,
                    'status' => 'pending_fixes',
                    'completed_at' => null,
                    'compensation_validated_at' => null,
                    'position_mapping_validated_at' => null,
                    'legal_entity_validated_at' => null,
                    'start_date_confirmed_at' => null,
                    'invalidated_at' => now(),
                    'invalidation_reason' => 'Material offer terms modified (start date, compensation, legal entity, or position). Re-validation and re-approval required.',
                ]);

                HrHandoverApproval::query()
                    ->where('hr_validation_case_id', $validationCase->id)
                    ->where('decision', 'approved')
                    ->update([
                        'decision' => 'invalidated',
                        'comment' => 'Automatically invalidated due to material change in OfferVersion v'.$nextVersionNumber,
                    ]);

                HrHandover::query()
                    ->where('hr_validation_case_id', $validationCase->id)
                    ->whereIn('status', ['blocked_pending_validation', 'ready_for_dispatch', 'queued'])
                    ->update([
                        'status' => 'cancelled',
                        'error_code' => 'INVALIDATED_BY_MATERIAL_CHANGE',
                    ]);

                $this->auditService->record(
                    module: 'hr_handover',
                    action: 'hr_validation_case.invalidated_by_material_change',
                    entityType: 'hr_validation_case',
                    entityId: (string) $validationCase->id,
                    metadata: [
                        'offer_id' => $lockedOffer->id,
                        'previous_version_id' => $previousVersion->id,
                        'new_version_id' => $newVersion->id,
                        'new_version_number' => $nextVersionNumber,
                    ],
                    tenantId: (int) $lockedOffer->tenant_id,
                    actorUserId: (int) $actor->id,
                );
            }

            return $newVersion;
        });
    }

    /**
     * @param  array<string, mixed>  $checklistData
     */
    public function updateValidationChecklist(HrValidationCase $case, array $checklistData, User $validator): HrValidationCase
    {
        $tenantId = (int) $case->tenant_id;

        if (! $validator->hasPermission('hr_validation.manage', $tenantId) && ! $validator->hasRole('tenant_admin', $tenantId)) {
            throw new AuthorizationException('User does not have permission to perform HR validation (hr_validation.manage).');
        }

        return DB::transaction(function () use ($case, $checklistData, $validator, $tenantId): HrValidationCase {
            /** @var HrValidationCase $lockedCase */
            $lockedCase = HrValidationCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

            $now = now();
            $wasInvalidated = $lockedCase->invalidated_at !== null;

            $lockedCase->fill([
                'identity_verified_at' => ! empty($checklistData['identity_verified']) ? ($lockedCase->identity_verified_at ?? $now) : null,
                'right_to_work_verified_at' => ! empty($checklistData['right_to_work_verified']) ? ($lockedCase->right_to_work_verified_at ?? $now) : null,
                'background_check_status' => $checklistData['background_check_status'] ?? $lockedCase->background_check_status,
                'compensation_validated_at' => ! empty($checklistData['compensation_validated']) ? ($lockedCase->compensation_validated_at ?? $now) : null,
                'position_mapping_validated_at' => ! empty($checklistData['position_mapping_validated']) ? ($lockedCase->position_mapping_validated_at ?? $now) : null,
                'legal_entity_validated_at' => ! empty($checklistData['legal_entity_validated']) ? ($lockedCase->legal_entity_validated_at ?? $now) : null,
                'start_date_confirmed_at' => ! empty($checklistData['start_date_confirmed']) ? ($lockedCase->start_date_confirmed_at ?? $now) : null,
                'position_code' => $checklistData['position_code'] ?? $lockedCase->position_code ?? 'POS-DYN-100',
                'assigned_to_hr_validator_id' => $validator->id,
                'invalidated_at' => null,
                'invalidation_reason' => null,
                'checklist_version' => $wasInvalidated ? $lockedCase->checklist_version + 1 : $lockedCase->checklist_version,
            ]);

            if ($lockedCase->isChecklistComplete()) {
                $lockedCase->status = 'ready_for_approval';
                $lockedCase->completed_at = $now;
            } else {
                $lockedCase->status = 'in_validation';
                $lockedCase->completed_at = null;
            }

            $lockedCase->save();

            $application = Application::query()->whereKey($lockedCase->application_id)->lockForUpdate()->firstOrFail();
            if (in_array($application->current_stage, ['offer', 'offer_accepted'], true)) {
                $fromStage = $application->current_stage;
                $application->update(['current_stage' => 'hr_validation']);
                ApplicationStageEvent::query()->create([
                    'tenant_id' => $application->tenant_id,
                    'application_id' => $application->id,
                    'from_stage' => $fromStage,
                    'to_stage' => 'hr_validation',
                    'actor_user_id' => $validator->id,
                    'notes' => 'HR validation checklist updated (status: '.$lockedCase->status.').',
                    'created_at' => $now,
                ]);
            }

            $this->auditService->record(
                module: 'hr_handover',
                action: 'hr_validation_case.checklist_updated',
                entityType: 'hr_validation_case',
                entityId: (string) $lockedCase->id,
                metadata: [
                    'status' => $lockedCase->status,
                    'checklist_complete' => $lockedCase->isChecklistComplete(),
                    'checklist_version' => $lockedCase->checklist_version,
                ],
                tenantId: $tenantId,
                actorUserId: (int) $validator->id,
            );

            return $lockedCase->fresh(['offerVersion', 'application.candidate']);
        });
    }

    /**
     * Rule HRH-02 & HRH-03: Only an authorized HR approver (`hr_handover.approve`) can approve handover,
     * and only when the offer is accepted and every checklist item is complete and not invalidated.
     */
    public function approveForHandover(HrValidationCase $case, User $approver, ?string $comment = null): HrHandoverApproval
    {
        $tenantId = (int) $case->tenant_id;

        if (
            ! $approver->hasPermission('hr_handover.approve', $tenantId)
            || ($approver->hasRole('recruiter', $tenantId) && ! $approver->hasRole('hr_approver', $tenantId))
            || ($approver->hasRole('hiring_manager', $tenantId) && ! $approver->hasRole('hr_approver', $tenantId))
        ) {
            throw new AuthorizationException('Recruiters and hiring managers are prohibited from approving HR employee handover.');
        }

        return DB::transaction(function () use ($case, $approver, $comment, $tenantId): HrHandoverApproval {
            /** @var HrValidationCase $lockedCase */
            $lockedCase = HrValidationCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

            if ($lockedCase->invalidated_at !== null) {
                throw new HrHandoverGateNotSatisfiedException(
                    'HR validation case was invalidated due to material change and must be re-validated before approval.',
                    'HR_HANDOVER_GATE_NOT_SATISFIED'
                );
            }

            if (! $lockedCase->isChecklistComplete() || $lockedCase->status !== 'ready_for_approval') {
                throw new HrHandoverGateNotSatisfiedException(
                    'HR validation checklist is incomplete. All mandatory verification items must be completed before handover approval.',
                    'HR_HANDOVER_GATE_NOT_SATISFIED'
                );
            }

            /** @var Offer|null $offer */
            $offer = Offer::query()
                ->where('tenant_id', $tenantId)
                ->where('application_id', $lockedCase->application_id)
                ->lockForUpdate()
                ->first();

            if ($offer === null || $offer->status !== 'accepted' || (int) $offer->accepted_version_id !== (int) $lockedCase->offer_version_id) {
                throw new HrHandoverGateNotSatisfiedException(
                    'Offer is not in an accepted state matching the validated offer version.',
                    'HR_HANDOVER_GATE_NOT_SATISFIED'
                );
            }

            $snapshot = $this->buildCanonicalHandoverPayload($lockedCase);
            $payloadHash = hash('sha256', (string) json_encode($snapshot));

            $approval = HrHandoverApproval::query()->create([
                'tenant_id' => $tenantId,
                'hr_validation_case_id' => $lockedCase->id,
                'approver_user_id' => $approver->id,
                'decision' => 'approved',
                'comment' => $comment ?? 'Explicit HR handover approval granted after full checklist verification.',
                'approved_payload_snapshot' => $snapshot,
                'approved_payload_hash' => $payloadHash,
                'decided_at' => now(),
            ]);

            $lockedCase->update([
                'status' => 'approved_for_handover',
            ]);

            $application = Application::query()->whereKey($lockedCase->application_id)->lockForUpdate()->firstOrFail();
            $fromStage = $application->current_stage;
            $application->update(['current_stage' => 'approved_for_handover']);
            ApplicationStageEvent::query()->create([
                'tenant_id' => $tenantId,
                'application_id' => $application->id,
                'from_stage' => $fromStage,
                'to_stage' => 'approved_for_handover',
                'actor_user_id' => $approver->id,
                'notes' => 'Explicit HR Handover Approval recorded (hash: '.substr($payloadHash, 0, 12).'...).',
                'created_at' => now(),
            ]);

            $this->auditService->record(
                module: 'hr_handover',
                action: 'hr_handover.approved',
                entityType: 'hr_validation_case',
                entityId: (string) $lockedCase->id,
                metadata: [
                    'approval_id' => $approval->id,
                    'approved_payload_hash' => $payloadHash,
                ],
                tenantId: $tenantId,
                actorUserId: (int) $approver->id,
            );

            return $approval;
        });
    }

    /**
     * Rules HRH-02, HRH-04, HRH-05, HRH-06, HRH-07, HRH-08:
     * Dispatches the HR handover only when all 5 safety gate preconditions hold, enforces idempotency,
     * blocks blind retry when reconciliation is required, writes TransactionalOutboxEvent atomically,
     * and stores ExternalEmployeeReference on confirmed creation.
     */
    public function dispatchHandover(
        HrValidationCase $case,
        User|ApiClientPrincipal $actor,
        ?string $idempotencyKey = null,
        string $simulatedOutcome = 'succeeded',
    ): HrHandover {
        $tenantId = (int) $case->tenant_id;
        $actorUserId = $actor instanceof User ? (int) $actor->id : null;
        $actorType = $actor instanceof ApiClientPrincipal ? $actor->getActorType() : 'USER';
        $actorIdentifier = $actor instanceof ApiClientPrincipal ? $actor->getActorIdentifier() : $actor->email;

        if (! $actor->hasPermission('hr_handover.dispatch', $tenantId) && ! $actor->hasRole('tenant_admin', $tenantId)) {
            throw new AuthorizationException('User does not have permission to dispatch HR handover (hr_handover.dispatch).');
        }

        return DB::transaction(function () use ($case, $actorUserId, $actorType, $actorIdentifier, $idempotencyKey, $simulatedOutcome, $tenantId): HrHandover {
            /** @var HrValidationCase $lockedCase */
            $lockedCase = HrValidationCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

            $effectiveKey = $idempotencyKey ?: 'hr-handover-'.$lockedCase->id.'-v'.$lockedCase->checklist_version;

            // Rule HRH-05: Idempotency check
            /** @var HrHandover|null $existingByKey */
            $existingByKey = HrHandover::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $effectiveKey)
                ->lockForUpdate()
                ->first();

            if ($existingByKey !== null) {
                if ($existingByKey->requires_reconciliation) {
                    throw new HrHandoverGateNotSatisfiedException(
                        'Handover is in an ambiguous timeout state and requires reconciliation check before retry.',
                        'HR_HANDOVER_RECONCILIATION_REQUIRED'
                    );
                }

                if (in_array($existingByKey->status, ['succeeded', 'reconciled', 'in_flight'], true)) {
                    $this->auditService->record(
                        module: 'hr_handover',
                        action: 'hr_handover.idempotent_replay_prevented',
                        entityType: 'hr_handover',
                        entityId: (string) $existingByKey->id,
                        metadata: [
                            'idempotency_key' => $effectiveKey,
                            'status' => $existingByKey->status,
                        ],
                        tenantId: $tenantId,
                        actorUserId: $actorUserId,
                        actorType: $actorType,
                        actorIdentifier: $actorIdentifier,
                    );

                    return $existingByKey->fresh(['externalEmployeeReference', 'approval']);
                }
            }

            // Check if any handover for this validation case requires reconciliation first (Rule HRH-06)
            $unreconciled = HrHandover::query()
                ->where('hr_validation_case_id', $lockedCase->id)
                ->where('requires_reconciliation', true)
                ->first();

            if ($unreconciled !== null) {
                throw new HrHandoverGateNotSatisfiedException(
                    'Previous handover attempt experienced an ambiguous timeout. Reconciliation check is required before retrying.',
                    'HR_HANDOVER_RECONCILIATION_REQUIRED'
                );
            }

            // Rule HRH-02 & HRH-04: Verify all safety gate preconditions
            if ($lockedCase->invalidated_at !== null) {
                throw new HrHandoverGateNotSatisfiedException(
                    'HR validation case was invalidated by a material change. Re-validation and re-approval are required.',
                    'HR_HANDOVER_GATE_NOT_SATISFIED'
                );
            }

            if (! $lockedCase->isChecklistComplete()) {
                throw new HrHandoverGateNotSatisfiedException(
                    'HR validation checklist is incomplete. Handover dispatch is blocked.',
                    'HR_HANDOVER_GATE_NOT_SATISFIED'
                );
            }

            /** @var Offer|null $offer */
            $offer = Offer::query()
                ->where('tenant_id', $tenantId)
                ->where('application_id', $lockedCase->application_id)
                ->lockForUpdate()
                ->first();

            if ($offer === null || $offer->status !== 'accepted' || (int) $offer->accepted_version_id !== (int) $lockedCase->offer_version_id) {
                throw new HrHandoverGateNotSatisfiedException(
                    'Offer is not accepted or accepted version does not match the validated offer version.',
                    'HR_HANDOVER_GATE_NOT_SATISFIED'
                );
            }

            /** @var HrHandoverApproval|null $approval */
            $approval = HrHandoverApproval::query()
                ->where('hr_validation_case_id', $lockedCase->id)
                ->where('decision', 'approved')
                ->latest('decided_at')
                ->first();

            if ($approval === null) {
                throw new HrHandoverGateNotSatisfiedException(
                    'Explicit HR handover approval by an authorized HR approver is missing.',
                    'HR_HANDOVER_GATE_NOT_SATISFIED'
                );
            }

            $currentSnapshot = $this->buildCanonicalHandoverPayload($lockedCase);
            $currentHash = hash('sha256', (string) json_encode($currentSnapshot));

            if (! hash_equals($approval->approved_payload_hash, $currentHash)) {
                throw new HrHandoverGateNotSatisfiedException(
                    'Approved payload hash no longer matches current offer/candidate data. Material change detected.',
                    'HR_HANDOVER_GATE_NOT_SATISFIED'
                );
            }

            $handover = $existingByKey ?? HrHandover::query()->create([
                'tenant_id' => $tenantId,
                'hr_validation_case_id' => $lockedCase->id,
                'approval_id' => $approval->id,
                'idempotency_key' => $effectiveKey,
                'status' => 'queued',
                'target_system' => 'dynamics_365_finance_hr',
                'payload_snapshot' => $currentSnapshot,
                'payload_hash' => $currentHash,
                'attempt_count' => 0,
            ]);

            $outboxEvent = TransactionalOutboxEvent::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'idempotency_key' => $effectiveKey,
                ],
                [
                    'aggregate_type' => 'hr_handover',
                    'aggregate_id' => (int) $handover->id,
                    'event_type' => 'hr.handover.approved_for_dispatch',
                    'payload' => $currentSnapshot,
                    'status' => 'pending',
                    'available_at' => now(),
                ]
            );

            $handover->increment('attempt_count');
            $outboxEvent->increment('attempts');

            if ($simulatedOutcome === 'ambiguous_timeout') {
                $handover->update([
                    'status' => 'failed_retryable',
                    'requires_reconciliation' => true,
                    'error_code' => 'DYNAMICS_TIMEOUT_AMBIGUOUS',
                    'error_detail' => 'Ambiguous timeout waiting for Dynamics 365 Finance HR response; reconciliation check required before retry.',
                    'dispatched_at' => now(),
                ]);

                $outboxEvent->update([
                    'status' => 'failed',
                    'last_error' => 'DYNAMICS_TIMEOUT_AMBIGUOUS',
                ]);

                $this->auditService->record(
                    module: 'hr_handover',
                    action: 'hr_handover.ambiguous_timeout',
                    outcome: 'FAILURE',
                    errorCode: 'DYNAMICS_TIMEOUT_AMBIGUOUS',
                    entityType: 'hr_handover',
                    entityId: (string) $handover->id,
                    metadata: [
                        'idempotency_key' => $effectiveKey,
                        'requires_reconciliation' => true,
                    ],
                    tenantId: $tenantId,
                    actorUserId: $actorUserId,
                    actorType: $actorType,
                    actorIdentifier: $actorIdentifier,
                );

                return $handover->fresh(['approval']);
            }

            // Default: deterministic sandbox success (Rule HRH-07)
            $externalRequestId = 'DYN-REQ-'.strtoupper(substr((string) $handover->uuid, 0, 8));
            $externalEmployeeId = 'DYN-EMP-'.strtoupper(substr(hash('sha256', $effectiveKey), 0, 10));
            $workerNumber = 'WRK-'.strtoupper(substr(hash('sha256', (string) $lockedCase->application_id), 0, 6));

            $handover->update([
                'status' => 'succeeded',
                'requires_reconciliation' => false,
                'external_request_id' => $externalRequestId,
                'external_employee_id' => $externalEmployeeId,
                'error_code' => null,
                'error_detail' => null,
                'dispatched_at' => now(),
                'completed_at' => now(),
            ]);

            $outboxEvent->update([
                'status' => 'dispatched',
                'dispatched_at' => now(),
                'last_error' => null,
            ]);

            $lockedCase->update([
                'status' => 'handed_over',
            ]);

            $application = Application::query()->whereKey($lockedCase->application_id)->lockForUpdate()->firstOrFail();
            $fromStage = $application->current_stage;
            $application->update([
                'current_stage' => 'handed_over',
                'status' => 'HIRED',
            ]);

            ApplicationStageEvent::query()->create([
                'tenant_id' => $tenantId,
                'application_id' => $application->id,
                'from_stage' => $fromStage,
                'to_stage' => 'handed_over',
                'actor_user_id' => $actorUserId,
                'notes' => 'Employee handover succeeded in Dynamics 365 Finance HR ('.$externalEmployeeId.').',
                'created_at' => now(),
            ]);

            ExternalEmployeeReference::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'external_system' => 'dynamics_365_finance_hr',
                    'external_employee_id' => $externalEmployeeId,
                ],
                [
                    'candidate_id' => $application->candidate_id,
                    'application_id' => $application->id,
                    'hr_handover_id' => $handover->id,
                    'external_worker_number' => $workerNumber,
                    'legal_entity_code' => (string) ($currentSnapshot['legal_entity_code'] ?? 'USMF'),
                    'synced_at' => now(),
                    'sync_status' => 'synced',
                    'raw_reference_payload' => [
                        'external_request_id' => $externalRequestId,
                        'external_employee_id' => $externalEmployeeId,
                        'external_worker_number' => $workerNumber,
                        'idempotency_key' => $effectiveKey,
                        'mode' => 'alpha_sandbox',
                    ],
                ]
            );

            $this->auditService->record(
                module: 'hr_handover',
                action: 'hr_handover.succeeded',
                entityType: 'hr_handover',
                entityId: (string) $handover->id,
                metadata: [
                    'idempotency_key' => $effectiveKey,
                    'external_employee_id' => $externalEmployeeId,
                    'external_worker_number' => $workerNumber,
                ],
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                actorType: $actorType,
                actorIdentifier: $actorIdentifier,
            );

            return $handover->fresh(['externalEmployeeReference', 'approval']);
        });
    }

    /**
     * Rule HRH-06: Reconciles an ambiguous handover before any retry is permitted.
     */
    public function reconcileHandover(HrHandover $handover, User $actor, bool $foundInExternalSystem = true): HrHandover
    {
        $tenantId = (int) $handover->tenant_id;

        if (! $actor->hasPermission('hr_handover.dispatch', $tenantId) && ! $actor->hasRole('tenant_admin', $tenantId)) {
            throw new AuthorizationException('User does not have permission to reconcile HR handovers.');
        }

        return DB::transaction(function () use ($handover, $actor, $foundInExternalSystem, $tenantId): HrHandover {
            /** @var HrHandover $lockedHandover */
            $lockedHandover = HrHandover::query()->whereKey($handover->id)->lockForUpdate()->firstOrFail();
            /** @var HrValidationCase $validationCase */
            $validationCase = HrValidationCase::query()->whereKey($lockedHandover->hr_validation_case_id)->lockForUpdate()->firstOrFail();
            /** @var Application $application */
            $application = Application::query()->whereKey($validationCase->application_id)->lockForUpdate()->firstOrFail();

            if ($foundInExternalSystem) {
                $externalEmployeeId = 'DYN-EMP-'.strtoupper(substr(hash('sha256', $lockedHandover->idempotency_key), 0, 10));
                $workerNumber = 'WRK-'.strtoupper(substr(hash('sha256', (string) $application->id), 0, 6));

                $lockedHandover->update([
                    'status' => 'reconciled',
                    'requires_reconciliation' => false,
                    'last_reconciled_at' => now(),
                    'external_employee_id' => $externalEmployeeId,
                    'error_code' => null,
                    'error_detail' => null,
                    'completed_at' => now(),
                ]);

                $validationCase->update([
                    'status' => 'handed_over',
                ]);

                $application->update([
                    'current_stage' => 'handed_over',
                    'status' => 'HIRED',
                ]);

                ExternalEmployeeReference::query()->updateOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'external_system' => 'dynamics_365_finance_hr',
                        'external_employee_id' => $externalEmployeeId,
                    ],
                    [
                        'candidate_id' => $application->candidate_id,
                        'application_id' => $application->id,
                        'hr_handover_id' => $lockedHandover->id,
                        'external_worker_number' => $workerNumber,
                        'legal_entity_code' => (string) ($lockedHandover->payload_snapshot['legal_entity_code'] ?? 'USMF'),
                        'synced_at' => now(),
                        'sync_status' => 'reconciled',
                        'raw_reference_payload' => [
                            'reconciled_by_idempotency_key' => $lockedHandover->idempotency_key,
                            'external_employee_id' => $externalEmployeeId,
                        ],
                    ]
                );

                $this->auditService->record(
                    module: 'hr_handover',
                    action: 'hr_handover.reconciled',
                    entityType: 'hr_handover',
                    entityId: (string) $lockedHandover->id,
                    metadata: [
                        'idempotency_key' => $lockedHandover->idempotency_key,
                        'found_in_external_system' => true,
                        'external_employee_id' => $externalEmployeeId,
                    ],
                    tenantId: $tenantId,
                    actorUserId: (int) $actor->id,
                );
            } else {
                $lockedHandover->update([
                    'status' => 'ready_for_dispatch',
                    'requires_reconciliation' => false,
                    'last_reconciled_at' => now(),
                    'error_code' => null,
                    'error_detail' => 'Reconciled: no duplicate worker found in Dynamics 365; cleared for safe retry.',
                ]);

                $this->auditService->record(
                    module: 'hr_handover',
                    action: 'hr_handover.reconciled_not_found',
                    entityType: 'hr_handover',
                    entityId: (string) $lockedHandover->id,
                    metadata: [
                        'idempotency_key' => $lockedHandover->idempotency_key,
                        'found_in_external_system' => false,
                    ],
                    tenantId: $tenantId,
                    actorUserId: (int) $actor->id,
                );
            }

            return $lockedHandover->fresh(['externalEmployeeReference', 'approval']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createOfferVersionRecord(Offer $offer, int $versionNumber, array $data, User $actor): OfferVersion
    {
        $canonicalTerms = [
            'offer_id' => (int) $offer->id,
            'version_number' => $versionNumber,
            'job_title' => trim((string) $data['job_title']),
            'legal_entity_id' => isset($data['legal_entity_id']) ? (int) $data['legal_entity_id'] : null,
            'department_id' => isset($data['department_id']) ? (int) $data['department_id'] : null,
            'location_id' => isset($data['location_id']) ? (int) $data['location_id'] : null,
            'employment_type' => $data['employment_type'] ?? 'full_time',
            'base_salary_minor' => (int) $data['base_salary_minor'],
            'currency_code' => strtoupper((string) ($data['currency_code'] ?? 'USD')),
            'bonus_terms' => $data['bonus_terms'] ?? null,
            'equity_terms' => $data['equity_terms'] ?? null,
            'start_date' => (string) $data['start_date'],
            'probation_months' => (int) ($data['probation_months'] ?? 3),
        ];

        $snapshotHash = hash('sha256', (string) json_encode($canonicalTerms));

        return OfferVersion::query()->create([
            'tenant_id' => $offer->tenant_id,
            'offer_id' => $offer->id,
            'version_number' => $versionNumber,
            'job_title' => $canonicalTerms['job_title'],
            'legal_entity_id' => $canonicalTerms['legal_entity_id'],
            'department_id' => $canonicalTerms['department_id'],
            'location_id' => $canonicalTerms['location_id'],
            'employment_type' => $canonicalTerms['employment_type'],
            'base_salary_minor' => $canonicalTerms['base_salary_minor'],
            'currency_code' => $canonicalTerms['currency_code'],
            'bonus_terms' => $canonicalTerms['bonus_terms'],
            'equity_terms' => $canonicalTerms['equity_terms'],
            'start_date' => $canonicalTerms['start_date'],
            'probation_months' => $canonicalTerms['probation_months'],
            'document_storage_key' => 'offers/'.$offer->tenant_id.'/'.Str::slug($canonicalTerms['job_title']).'-v'.$versionNumber.'.pdf',
            'snapshot_hash' => $snapshotHash,
            'created_by_user_id' => $actor->id,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildCanonicalHandoverPayload(HrValidationCase $validationCase): array
    {
        $offerVersion = OfferVersion::query()->with(['legalEntity', 'department', 'location'])->findOrFail($validationCase->offer_version_id);
        $application = Application::query()->with('candidate')->findOrFail($validationCase->application_id);
        $candidate = $application->candidate;

        return [
            'tenant_id' => (int) $validationCase->tenant_id,
            'application_id' => (int) $application->id,
            'candidate_id' => (int) $candidate->id,
            'candidate_email' => $candidate->primary_email,
            'candidate_first_name' => $candidate->first_name,
            'candidate_last_name' => $candidate->last_name,
            'offer_version_id' => (int) $offerVersion->id,
            'offer_version_number' => (int) $offerVersion->version_number,
            'offer_snapshot_hash' => $offerVersion->snapshot_hash,
            'job_title' => $offerVersion->job_title,
            'legal_entity_id' => (int) $offerVersion->legal_entity_id,
            'legal_entity_code' => $offerVersion->legalEntity?->code ?? 'USMF',
            'department_id' => (int) $offerVersion->department_id,
            'department_code' => $offerVersion->department?->code ?? 'ENG',
            'location_id' => (int) $offerVersion->location_id,
            'employment_type' => $offerVersion->employment_type,
            'base_salary_minor' => (int) $offerVersion->base_salary_minor,
            'currency_code' => $offerVersion->currency_code,
            'start_date' => $offerVersion->start_date->format('Y-m-d'),
            'position_code' => $validationCase->position_code ?? 'POS-DYN-100',
            'checklist_version' => (int) $validationCase->checklist_version,
        ];
    }
}
