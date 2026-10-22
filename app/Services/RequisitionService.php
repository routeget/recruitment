<?php

namespace App\Services;

use App\Models\Requisition;
use App\Models\RequisitionApproval;
use App\Models\RequisitionVersion;
use App\Models\User;
use App\Support\ApiClientPrincipal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RequisitionService
{
    public function __construct(
        private readonly TenantContextService $context,
        private readonly AuditService $audit
    ) {}

    public function createRequisition(array $data, User|ApiClientPrincipal $actor): Requisition
    {
        $tenantId = $this->context->getActiveTenantId() ?? ($actor instanceof ApiClientPrincipal ? $actor->getTenantId() : $actor->current_tenant_id);
        $actorUserId = $actor instanceof User ? (int) $actor->id : null;
        $actorType = $actor instanceof ApiClientPrincipal ? $actor->getActorType() : 'USER';
        $actorIdentifier = $actor instanceof ApiClientPrincipal ? $actor->getActorIdentifier() : $actor->email;

        return DB::transaction(function () use ($data, $actor, $tenantId, $actorUserId, $actorType, $actorIdentifier) {
            $nextNumber = 'REQ-'.date('Y').'-'.str_pad((string) (Requisition::withoutGlobalScopes()->where('tenant_id', $tenantId)->count() + 101), 4, '0', STR_PAD_LEFT);

            $req = Requisition::create([
                'tenant_id' => $tenantId,
                'requisition_number' => $data['requisition_number'] ?? $nextNumber,
                'title' => $data['title'],
                'department_id' => $data['department_id'],
                'location_id' => $data['location_id'],
                'legal_entity_id' => $data['legal_entity_id'],
                'hiring_manager_user_id' => $data['hiring_manager_user_id'] ?? $actorUserId,
                'recruiter_user_id' => $data['recruiter_user_id'] ?? null,
                'created_by_user_id' => $actorUserId,
                'headcount' => (int) ($data['headcount'] ?? 1),
                'filled_headcount' => 0,
                'employment_type' => $data['employment_type'] ?? 'FULL_TIME',
                'requisition_reason' => $data['requisition_reason'] ?? 'NEW_ROLE',
                'budget_reference' => $data['budget_reference'],
                'salary_min' => $data['salary_min'],
                'salary_max' => $data['salary_max'],
                'currency_code' => strtoupper($data['currency_code'] ?? 'USD'),
                'target_start_date' => $data['target_start_date'] ?? null,
                'justification' => $data['justification'],
                'status' => Requisition::STATUS_DRAFT,
                'current_version' => 1,
            ]);

            $this->recordVersion($req, $actor, 'Initial draft created');

            $this->audit->record(
                module: 'requisitions',
                action: 'requisition_created',
                outcome: 'SUCCESS',
                entityType: 'Requisition',
                entityId: (string) $req->id,
                metadata: [
                    'requisition_number' => $req->requisition_number,
                    'status' => $req->status,
                    'version' => $req->current_version,
                ],
                tenantId: (int) $tenantId,
                actorUserId: $actorUserId,
                actorType: $actorType,
                actorIdentifier: $actorIdentifier,
            );

            return $req;
        });
    }

    public function updateRequisition(Requisition $req, array $data, User $actor): Requisition
    {
        $this->context->assertModelBelongsToActiveTenant($req, 'Requisition');

        return DB::transaction(function () use ($req, $data, $actor) {
            $locked = Requisition::lockForUpdate()->findOrFail($req->id);

            if (in_array($locked->status, [Requisition::STATUS_CLOSED, Requisition::STATUS_CANCELLED], true)) {
                throw ValidationException::withMessages([
                    'status' => ['INVALID_STATE_TRANSITION: Closed or cancelled requisitions cannot be edited.'],
                ]);
            }

            $wasApprovedOrOpen = in_array($locked->status, [Requisition::STATUS_APPROVED, Requisition::STATUS_OPEN, Requisition::STATUS_IN_APPROVAL], true);

            $locked->fill([
                'title' => $data['title'] ?? $locked->title,
                'department_id' => $data['department_id'] ?? $locked->department_id,
                'location_id' => $data['location_id'] ?? $locked->location_id,
                'legal_entity_id' => $data['legal_entity_id'] ?? $locked->legal_entity_id,
                'headcount' => isset($data['headcount']) ? (int) $data['headcount'] : $locked->headcount,
                'employment_type' => $data['employment_type'] ?? $locked->employment_type,
                'budget_reference' => $data['budget_reference'] ?? $locked->budget_reference,
                'salary_min' => $data['salary_min'] ?? $locked->salary_min,
                'salary_max' => $data['salary_max'] ?? $locked->salary_max,
                'justification' => $data['justification'] ?? $locked->justification,
            ]);

            $locked->current_version = $locked->current_version + 1;

            if ($wasApprovedOrOpen) {
                $locked->status = Requisition::STATUS_DRAFT;
                $locked->approved_version = null;
                $locked->approved_by_user_id = null;
                $locked->approved_at = null;
            }

            $locked->save();

            $this->recordVersion(
                $locked,
                $actor,
                $data['change_summary'] ?? ($wasApprovedOrOpen ? 'Material edit resetting approval' : 'Updated requisition')
            );

            $this->audit->record(
                module: 'requisitions',
                action: 'requisition_updated',
                outcome: 'SUCCESS',
                entityType: 'Requisition',
                entityId: (string) $locked->id,
                metadata: [
                    'version' => $locked->current_version,
                    'status' => $locked->status,
                    'approval_invalidated' => $wasApprovedOrOpen,
                ]
            );

            return $locked;
        });
    }

    public function submitForApproval(Requisition $req, User $actor): Requisition
    {
        $this->context->assertModelBelongsToActiveTenant($req, 'Requisition');

        return DB::transaction(function () use ($req) {
            $locked = Requisition::lockForUpdate()->findOrFail($req->id);

            if ($locked->status !== Requisition::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => ["INVALID_STATE_TRANSITION: Cannot submit requisition from status {$locked->status}."],
                ]);
            }

            $locked->status = Requisition::STATUS_IN_APPROVAL;
            $locked->save();

            $this->audit->record(
                module: 'requisitions',
                action: 'requisition_submitted_for_approval',
                outcome: 'SUCCESS',
                entityType: 'Requisition',
                entityId: (string) $locked->id,
                metadata: [
                    'requisition_number' => $locked->requisition_number,
                    'version' => $locked->current_version,
                    'status' => $locked->status,
                ]
            );

            return $locked;
        });
    }

    public function decideApproval(Requisition $req, User $approver, string $decision, ?string $comments = null): Requisition
    {
        $this->context->assertModelBelongsToActiveTenant($req, 'Requisition');

        if (! $approver->hasPermission('requisitions.approve', $req->tenant_id)) {
            $this->audit->record(
                module: 'requisitions',
                action: 'requisition_approval_denied_permission',
                outcome: 'DENIED',
                errorCode: 'PERMISSION_DENIED',
                entityType: 'Requisition',
                entityId: (string) $req->id
            );

            throw new AccessDeniedHttpException('PERMISSION_DENIED: User lacks requisitions.approve permission.');
        }

        return DB::transaction(function () use ($req, $approver, $decision, $comments) {
            $locked = Requisition::lockForUpdate()->findOrFail($req->id);

            if (! in_array($locked->status, [Requisition::STATUS_SUBMITTED, Requisition::STATUS_IN_APPROVAL], true)) {
                throw ValidationException::withMessages([
                    'status' => ["INVALID_STATE_TRANSITION: Requisition {$locked->requisition_number} is in {$locked->status}, not awaiting approval."],
                ]);
            }

            // Enforce self-approval prohibition per Spec §4
            if ((int) $locked->created_by_user_id === (int) $approver->id) {
                $this->audit->record(
                    module: 'requisitions',
                    action: 'requisition_self_approval_blocked',
                    outcome: 'DENIED',
                    errorCode: 'PERMISSION_DENIED',
                    entityType: 'Requisition',
                    entityId: (string) $locked->id,
                    metadata: ['creator_id' => $locked->created_by_user_id, 'approver_id' => $approver->id]
                );

                throw new AccessDeniedHttpException('PERMISSION_DENIED: Self-approval of workforce requisitions is prohibited.');
            }

            $normalizedDecision = strtoupper($decision);
            if (! in_array($normalizedDecision, ['APPROVED', 'REJECTED'], true)) {
                throw ValidationException::withMessages(['decision' => ['Decision must be APPROVED or REJECTED.']]);
            }

            RequisitionApproval::create([
                'tenant_id' => $locked->tenant_id,
                'requisition_id' => $locked->id,
                'version_number' => $locked->current_version,
                'step_order' => 1,
                'approver_user_id' => $approver->id,
                'decision' => $normalizedDecision,
                'comments' => $comments,
                'decided_at' => now(),
            ]);

            if ($normalizedDecision === 'APPROVED') {
                $locked->status = Requisition::STATUS_APPROVED;
                $locked->approved_version = $locked->current_version;
                $locked->approved_by_user_id = $approver->id;
                $locked->approved_at = now();
            } else {
                $locked->status = Requisition::STATUS_DRAFT;
                $locked->approved_version = null;
                $locked->approved_by_user_id = null;
                $locked->approved_at = null;
            }

            $locked->save();

            $this->audit->record(
                module: 'requisitions',
                action: 'requisition_approval_decided',
                outcome: 'SUCCESS',
                entityType: 'Requisition',
                entityId: (string) $locked->id,
                metadata: [
                    'decision' => $normalizedDecision,
                    'version' => $locked->current_version,
                    'new_status' => $locked->status,
                ]
            );

            return $locked;
        });
    }

    public function transitionLifecycleState(Requisition $req, string $targetStatus, User $actor, ?string $reason = null): Requisition
    {
        $this->context->assertModelBelongsToActiveTenant($req, 'Requisition');

        $allowedMap = [
            Requisition::STATUS_APPROVED => [Requisition::STATUS_OPEN, Requisition::STATUS_ON_HOLD, Requisition::STATUS_CANCELLED],
            Requisition::STATUS_OPEN => [Requisition::STATUS_ON_HOLD, Requisition::STATUS_CLOSED, Requisition::STATUS_CANCELLED],
            Requisition::STATUS_ON_HOLD => [Requisition::STATUS_OPEN, Requisition::STATUS_CANCELLED],
        ];

        return DB::transaction(function () use ($req, $targetStatus, $allowedMap, $reason) {
            $locked = Requisition::lockForUpdate()->findOrFail($req->id);
            $target = strtoupper($targetStatus);

            $allowedTargets = $allowedMap[$locked->status] ?? [];
            if (! in_array($target, $allowedTargets, true)) {
                $this->audit->record(
                    module: 'requisitions',
                    action: 'requisition_invalid_transition_rejected',
                    outcome: 'DENIED',
                    errorCode: 'INVALID_STATE_TRANSITION',
                    entityType: 'Requisition',
                    entityId: (string) $locked->id,
                    metadata: ['from' => $locked->status, 'to' => $target]
                );

                throw ValidationException::withMessages([
                    'status' => ["INVALID_STATE_TRANSITION: Cannot transition requisition from {$locked->status} to {$target}."],
                ]);
            }

            $fromStatus = $locked->status;
            $locked->status = $target;
            if ($target === Requisition::STATUS_ON_HOLD) {
                $locked->hold_reason = $reason;
            }
            if ($target === Requisition::STATUS_CANCELLED) {
                $locked->cancel_reason = $reason;
            }
            $locked->save();

            $this->audit->record(
                module: 'requisitions',
                action: 'requisition_state_transitioned',
                outcome: 'SUCCESS',
                entityType: 'Requisition',
                entityId: (string) $locked->id,
                metadata: [
                    'from_status' => $fromStatus,
                    'to_status' => $target,
                    'reason' => $reason,
                ]
            );

            return $locked;
        });
    }

    private function recordVersion(Requisition $req, User|ApiClientPrincipal $actor, string $summary): RequisitionVersion
    {
        return RequisitionVersion::create([
            'tenant_id' => $req->tenant_id,
            'requisition_id' => $req->id,
            'version_number' => $req->current_version,
            'snapshot' => [
                'title' => $req->title,
                'department_id' => $req->department_id,
                'location_id' => $req->location_id,
                'legal_entity_id' => $req->legal_entity_id,
                'headcount' => $req->headcount,
                'employment_type' => $req->employment_type,
                'budget_reference' => $req->budget_reference,
                'salary_min' => (string) $req->salary_min,
                'salary_max' => (string) $req->salary_max,
                'currency_code' => $req->currency_code,
                'justification' => $req->justification,
            ],
            'change_summary' => $summary,
            'created_by_user_id' => $actor instanceof User ? $actor->id : null,
            'created_at' => now(),
        ]);
    }
}
