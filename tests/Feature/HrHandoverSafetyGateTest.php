<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\ExternalEmployeeReference;
use App\Models\HrHandover;
use App\Models\HrHandoverApproval;
use App\Models\HrValidationCase;
use App\Models\Offer;
use App\Models\Tenant;
use App\Models\TransactionalOutboxEvent;
use App\Models\User;
use Database\Seeders\AlphaSafeBootstrapSeeder;
use Tests\TestCase;

class HrHandoverSafetyGateTest extends TestCase
{
    private Tenant $tenant;

    private User $admin;

    private User $recruiter;

    private User $hiringManager;

    private User $hrValidator;

    private User $hrApprover;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AlphaSafeBootstrapSeeder::class);

        $this->tenant = Tenant::query()->where('slug', 'acme-demo')->firstOrFail();
        $this->admin = User::query()->where('email', 'admin@acme-demo.test')->firstOrFail();
        $this->recruiter = User::query()->where('email', 'recruiter@acme-demo.test')->firstOrFail();
        $this->hiringManager = User::query()->where('email', 'manager@acme-demo.test')->firstOrFail();
        $this->hrValidator = User::query()->where('email', 'hr.validator@acme-demo.test')->firstOrFail();
        $this->hrApprover = User::query()->where('email', 'hr.approver@acme-demo.test')->firstOrFail();

        $this->application = Application::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->firstOrFail();
    }

    public function test_hrh_01_through_hrh_08_mandatory_hr_handover_safety_gate(): void
    {
        // 1. Create Draft Offer (v1), Approve & Issue, and Accept Offer
        $this->actingAs($this->admin)
            ->withSession(['active_tenant_id' => $this->tenant->id])
            ->post('/offers', [
                'application_id' => $this->application->id,
                'job_title' => 'Principal Platform Architect',
                'employment_type' => 'full_time',
                'base_salary_minor' => 19500000,
                'currency_code' => 'USD',
                'start_date' => now()->addDays(21)->format('Y-m-d'),
                'probation_months' => 3,
            ])
            ->assertRedirect(route('offers.index'));

        $offer = Offer::withoutGlobalScopes()
            ->where('application_id', $this->application->id)
            ->latest('id')
            ->firstOrFail();

        $this->actingAs($this->admin)
            ->withSession(['active_tenant_id' => $this->tenant->id])
            ->post("/offers/{$offer->id}/issue")
            ->assertRedirect(route('offers.index'));

        $this->actingAs($this->admin)
            ->withSession(['active_tenant_id' => $this->tenant->id])
            ->post("/offers/{$offer->id}/accept")
            ->assertRedirect(route('offers.index'));

        // HRH-01: Offer acceptance alone NEVER enqueues or executes employee creation!
        $this->assertSame(0, HrHandover::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, TransactionalOutboxEvent::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, ExternalEmployeeReference::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());

        $case = HrValidationCase::withoutGlobalScopes()
            ->where('application_id', $this->application->id)
            ->firstOrFail();
        $this->assertSame('not_started', $case->status);

        // HRH-02: Handover dispatch fails with 409 HR_HANDOVER_GATE_NOT_SATISFIED when checklist is incomplete
        $this->actingAs($this->hrApprover)
            ->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/hr-handover/{$case->id}/dispatch", [
                'idempotency_key' => 'hrh-test-key-01',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'HR_HANDOVER_GATE_NOT_SATISFIED');

        // Partial checklist update still blocks approval & dispatch with 409
        $this->actingAs($this->hrValidator)
            ->withSession(['active_tenant_id' => $this->tenant->id])
            ->post("/hr-handover/{$case->id}/checklist", [
                'identity_verified' => true,
                'right_to_work_verified' => true,
                'background_check_status' => 'pending', // Incomplete!
                'position_code' => 'POS-ARC-01',
            ])
            ->assertRedirect(route('hr-handover.index'));

        $this->actingAs($this->hrApprover)
            ->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/hr-handover/{$case->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'HR_HANDOVER_GATE_NOT_SATISFIED');

        // Complete all mandatory checklist items as HR Validator
        $this->actingAs($this->hrValidator)
            ->withSession(['active_tenant_id' => $this->tenant->id])
            ->post("/hr-handover/{$case->id}/checklist", [
                'identity_verified' => true,
                'right_to_work_verified' => true,
                'background_check_status' => 'passed',
                'compensation_validated' => true,
                'position_mapping_validated' => true,
                'legal_entity_validated' => true,
                'start_date_confirmed' => true,
                'position_code' => 'POS-ARC-01',
            ])
            ->assertRedirect(route('hr-handover.index'));

        $case->refresh();
        $this->assertSame('ready_for_approval', $case->status);

        // HRH-03: Recruiter or Hiring Manager CANNOT approve HR handover (403 Forbidden)
        $this->actingAs($this->recruiter)
            ->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/hr-handover/{$case->id}/approve")
            ->assertStatus(403);

        $this->actingAs($this->hiringManager)
            ->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/hr-handover/{$case->id}/approve")
            ->assertStatus(403);

        // Authorized HR Approver approves the handover
        $this->actingAs($this->hrApprover)
            ->withSession(['active_tenant_id' => $this->tenant->id])
            ->post("/hr-handover/{$case->id}/approve", [
                'comment' => 'All HR checks verified; approved for Dynamics 365 employee creation.',
            ])
            ->assertRedirect(route('hr-handover.index'));

        $case->refresh();
        $this->assertSame('approved_for_handover', $case->status);

        // HRH-04: Modifying start date or compensation after HR validation & approval invalidates validation and blocks handover
        $this->actingAs($this->admin)
            ->withSession(['active_tenant_id' => $this->tenant->id])
            ->post("/offers/{$offer->id}/revise", [
                'base_salary_minor' => 20500000,
                'start_date' => now()->addDays(30)->format('Y-m-d'),
            ])
            ->assertRedirect(route('offers.index'));

        $case->refresh();
        $this->assertNotNull($case->invalidated_at);
        $this->assertSame('pending_fixes', $case->status);
        $this->assertSame(
            'invalidated',
            HrHandoverApproval::withoutGlobalScopes()->where('hr_validation_case_id', $case->id)->firstOrFail()->decision
        );

        // Attempting to dispatch after material-change invalidation fails with 409 HR_HANDOVER_GATE_NOT_SATISFIED
        $this->actingAs($this->hrApprover)
            ->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/hr-handover/{$case->id}/dispatch", [
                'idempotency_key' => 'hrh-test-key-invalidated',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'HR_HANDOVER_GATE_NOT_SATISFIED');

        // Re-validate checklist (v2) and re-approve with HR Approver
        $this->actingAs($this->hrValidator)
            ->withSession(['active_tenant_id' => $this->tenant->id])
            ->post("/hr-handover/{$case->id}/checklist", [
                'identity_verified' => true,
                'right_to_work_verified' => true,
                'background_check_status' => 'passed',
                'compensation_validated' => true,
                'position_mapping_validated' => true,
                'legal_entity_validated' => true,
                'start_date_confirmed' => true,
                'position_code' => 'POS-ARC-02',
            ])
            ->assertRedirect(route('hr-handover.index'));

        $this->actingAs($this->hrApprover)
            ->withSession(['active_tenant_id' => $this->tenant->id])
            ->post("/hr-handover/{$case->id}/approve", [
                'comment' => 'Re-approved OfferVersion v2 after compensation revision.',
            ])
            ->assertRedirect(route('hr-handover.index'));

        // HRH-06: Ambiguous timeout triggers reconciliation requirement before retry
        $this->actingAs($this->hrApprover)
            ->withSession(['active_tenant_id' => $this->tenant->id])
            ->post("/hr-handover/{$case->id}/dispatch", [
                'idempotency_key' => 'hrh-key-timeout-01',
                'simulated_outcome' => 'ambiguous_timeout',
            ])
            ->assertRedirect(route('hr-handover.index'));

        $timeoutHandover = HrHandover::withoutGlobalScopes()
            ->where('idempotency_key', 'hrh-key-timeout-01')
            ->firstOrFail();
        $this->assertSame('failed_retryable', $timeoutHandover->status);
        $this->assertTrue($timeoutHandover->requires_reconciliation);

        // Attempting blind retry without reconciliation is blocked with 409 HR_HANDOVER_RECONCILIATION_REQUIRED
        $this->actingAs($this->hrApprover)
            ->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/hr-handover/{$case->id}/dispatch", [
                'idempotency_key' => 'hrh-key-timeout-01',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'HR_HANDOVER_RECONCILIATION_REQUIRED');

        // Reconcile as 'not found in external system' so we can test clean dispatch + idempotency
        $this->actingAs($this->hrApprover)
            ->withSession(['active_tenant_id' => $this->tenant->id])
            ->post("/hr-handover/handovers/{$timeoutHandover->id}/reconcile", [
                'found_in_external_system' => false,
            ])
            ->assertRedirect(route('hr-handover.index'));

        // HRH-07: Confirmed creation stores ExternalEmployeeReference and marks HrHandover succeeded
        $this->actingAs($this->hrApprover)
            ->withSession(['active_tenant_id' => $this->tenant->id])
            ->post("/hr-handover/{$case->id}/dispatch", [
                'idempotency_key' => 'hrh-key-timeout-01',
                'simulated_outcome' => 'succeeded',
            ])
            ->assertRedirect(route('hr-handover.index'));

        $timeoutHandover->refresh();
        $this->assertSame('succeeded', $timeoutHandover->status);
        $this->assertNotNull($timeoutHandover->external_employee_id);
        $this->assertSame(2, $timeoutHandover->attempt_count);

        $extRef = ExternalEmployeeReference::withoutGlobalScopes()
            ->where('hr_handover_id', $timeoutHandover->id)
            ->firstOrFail();
        $this->assertSame($timeoutHandover->external_employee_id, $extRef->external_employee_id);

        // HRH-05: Duplicate handover dispatch with the same idempotency key does NOT create a second handover or extra attempt
        $this->actingAs($this->hrApprover)
            ->withSession(['active_tenant_id' => $this->tenant->id])
            ->post("/hr-handover/{$case->id}/dispatch", [
                'idempotency_key' => 'hrh-key-timeout-01',
                'simulated_outcome' => 'succeeded',
            ])
            ->assertRedirect(route('hr-handover.index'));

        $timeoutHandover->refresh();
        $this->assertSame(2, $timeoutHandover->attempt_count);
        $this->assertSame(1, HrHandover::withoutGlobalScopes()->where('idempotency_key', 'hrh-key-timeout-01')->count());
        $this->assertSame(1, ExternalEmployeeReference::withoutGlobalScopes()->where('application_id', $this->application->id)->count());

        // HRH-08: Verify all validation, approval, invalidation, timeout, reconciliation, and success actions created AuditEvent records
        $recordedActions = AuditEvent::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->pluck('action')
            ->all();

        foreach ([
            'offer.created',
            'offer.issued',
            'offer.accepted',
            'hr_validation_case.checklist_updated',
            'hr_handover.approved',
            'hr_validation_case.invalidated_by_material_change',
            'hr_handover.ambiguous_timeout',
            'hr_handover.reconciled_not_found',
            'hr_handover.succeeded',
            'hr_handover.idempotent_replay_prevented',
        ] as $expectedAction) {
            $this->assertContains($expectedAction, $recordedActions, "Missing expected audit action: {$expectedAction}");
        }
    }
}
