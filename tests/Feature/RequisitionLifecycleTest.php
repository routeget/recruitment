<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\LegalEntity;
use App\Models\Location;
use App\Models\Requisition;
use App\Models\User;
use Database\Seeders\AlphaSafeBootstrapSeeder;
use Tests\TestCase;

class RequisitionLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AlphaSafeBootstrapSeeder::class);
    }

    public function test_recruiter_can_create_submit_and_hiring_manager_can_approve_requisition(): void
    {
        $recruiter = User::where('email', 'recruiter@acme-demo.test')->firstOrFail();
        $manager = User::where('email', 'manager@acme-demo.test')->firstOrFail();

        $dept = Department::withoutTenantScope()->where('code', 'ENG-AI')->firstOrFail();
        $loc = Location::withoutTenantScope()->where('code', 'NYC-HQ')->firstOrFail();
        $le = LegalEntity::withoutTenantScope()->where('code', 'USMF')->firstOrFail();

        $createResp = $this->actingAs($recruiter)->post('/requisitions', [
            'title' => 'Staff Backend Engineer',
            'department_id' => $dept->id,
            'location_id' => $loc->id,
            'legal_entity_id' => $le->id,
            'headcount' => 2,
            'employment_type' => 'FULL_TIME',
            'requisition_reason' => 'EXPANSION',
            'budget_reference' => 'BUD-2026-ENG-202',
            'salary_min' => 150000,
            'salary_max' => 190000,
            'currency_code' => 'USD',
            'target_start_date' => '2026-12-15',
            'justification' => 'Core recruitment workflow expansion.',
        ]);

        $req = Requisition::withoutTenantScope()->where('title', 'Staff Backend Engineer')->firstOrFail();
        $createResp->assertRedirect(route('requisitions.show', $req->id));
        $this->assertSame(Requisition::STATUS_DRAFT, $req->status);
        $this->assertSame(1, $req->current_version);

        // Submit for approval
        $this->actingAs($recruiter)
            ->post("/requisitions/{$req->id}/submit")
            ->assertRedirect(route('requisitions.show', $req->id));

        $this->assertSame(Requisition::STATUS_IN_APPROVAL, $req->fresh()->status);

        // Hiring manager approves
        $this->actingAs($manager)
            ->post("/requisitions/{$req->id}/approve", [
                'decision' => 'APPROVED',
                'comments' => 'Approved against Q4 engineering budget.',
            ])
            ->assertRedirect(route('requisitions.show', $req->id));

        $req->refresh();
        $this->assertSame(Requisition::STATUS_APPROVED, $req->status);
        $this->assertSame(1, $req->approved_version);

        // Transition to OPEN
        $this->actingAs($recruiter)
            ->post("/requisitions/{$req->id}/transition", [
                'target_status' => 'OPEN',
            ])
            ->assertRedirect(route('requisitions.show', $req->id));

        $this->assertSame(Requisition::STATUS_OPEN, $req->fresh()->status);
    }

    public function test_self_approval_of_requisition_is_blocked(): void
    {
        $admin = User::where('email', 'admin@acme-demo.test')->firstOrFail();
        $dept = Department::withoutTenantScope()->where('code', 'ENG-AI')->firstOrFail();
        $loc = Location::withoutTenantScope()->where('code', 'NYC-HQ')->firstOrFail();
        $le = LegalEntity::withoutTenantScope()->where('code', 'USMF')->firstOrFail();

        $this->actingAs($admin)->post('/requisitions', [
            'title' => 'Self Approval Attempt Role',
            'department_id' => $dept->id,
            'location_id' => $loc->id,
            'legal_entity_id' => $le->id,
            'headcount' => 1,
            'employment_type' => 'FULL_TIME',
            'requisition_reason' => 'NEW_ROLE',
            'budget_reference' => 'BUD-SELF-01',
            'salary_min' => 120000,
            'salary_max' => 150000,
            'currency_code' => 'USD',
            'justification' => 'Testing self approval guard.',
        ]);

        $req = Requisition::withoutTenantScope()->where('title', 'Self Approval Attempt Role')->firstOrFail();
        $this->actingAs($admin)->post("/requisitions/{$req->id}/submit");

        // Creator attempting to approve their own requisition must receive 403
        $response = $this->actingAs($admin)->post("/requisitions/{$req->id}/approve", [
            'decision' => 'APPROVED',
            'comments' => 'Approving my own requisition.',
        ]);

        $response->assertStatus(403);
        $this->assertSame(Requisition::STATUS_IN_APPROVAL, $req->fresh()->status);
    }

    public function test_illegal_transition_from_draft_to_open_bypasses_approval_and_is_rejected(): void
    {
        $recruiter = User::where('email', 'recruiter@acme-demo.test')->firstOrFail();
        $dept = Department::withoutTenantScope()->where('code', 'ENG-AI')->firstOrFail();
        $loc = Location::withoutTenantScope()->where('code', 'NYC-HQ')->firstOrFail();
        $le = LegalEntity::withoutTenantScope()->where('code', 'USMF')->firstOrFail();

        $this->actingAs($recruiter)->post('/requisitions', [
            'title' => 'Draft Bypass Test Role',
            'department_id' => $dept->id,
            'location_id' => $loc->id,
            'legal_entity_id' => $le->id,
            'headcount' => 1,
            'employment_type' => 'FULL_TIME',
            'requisition_reason' => 'NEW_ROLE',
            'budget_reference' => 'BUD-BYPASS-01',
            'salary_min' => 100000,
            'salary_max' => 130000,
            'currency_code' => 'USD',
            'justification' => 'Testing state transition guard.',
        ]);

        $req = Requisition::withoutTenantScope()->where('title', 'Draft Bypass Test Role')->firstOrFail();

        $response = $this->actingAs($recruiter)->postJson("/requisitions/{$req->id}/transition", [
            'target_status' => 'OPEN',
        ]);

        $response->assertStatus(422);
        $this->assertSame(Requisition::STATUS_DRAFT, $req->fresh()->status);
    }

    public function test_material_edit_on_approved_requisition_increments_version_and_invalidates_approval(): void
    {
        $recruiter = User::where('email', 'recruiter@acme-demo.test')->firstOrFail();
        $req = Requisition::withoutTenantScope()->where('requisition_number', 'REQ-2026-0101')->firstOrFail();
        $this->assertSame(Requisition::STATUS_OPEN, $req->status);
        $this->assertSame(1, $req->approved_version);

        $this->actingAs($recruiter)->put("/requisitions/{$req->id}", [
            'title' => $req->title,
            'headcount' => 5,
            'budget_reference' => $req->budget_reference,
            'salary_min' => 180000,
            'salary_max' => 240000,
            'justification' => 'Increased headcount and salary band.',
            'change_summary' => 'Increased headcount from 2 to 5',
        ])->assertRedirect(route('requisitions.show', $req->id));

        $req->refresh();
        $this->assertSame(2, $req->current_version);
        $this->assertSame(Requisition::STATUS_DRAFT, $req->status);
        $this->assertNull($req->approved_version);
    }

    public function test_tenant_a_user_cannot_view_or_mutate_tenant_b_requisition(): void
    {
        $acmeAdmin = User::where('email', 'admin@acme-demo.test')->firstOrFail();
        $globexReq = Requisition::withoutTenantScope()->where('requisition_number', 'REQ-2026-0901')->firstOrFail();

        // Listing requisitions in Tenant A must not contain Tenant B's requisition
        $listResp = $this->actingAs($acmeAdmin)->get('/requisitions');
        $listResp->assertStatus(200)
            ->assertSee('REQ-2026-0101')
            ->assertDontSee('REQ-2026-0901');

        // Direct IDOR attempt on Tenant B requisition must return 403 TENANT_SCOPE_VIOLATION
        $showResp = $this->actingAs($acmeAdmin)->get("/requisitions/{$globexReq->id}");
        $showResp->assertStatus(403);
    }
}
