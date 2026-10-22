<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\LegalEntity;
use App\Models\Location;
use App\Models\Requisition;
use App\Services\RequisitionService;
use App\Services\TenantContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RequisitionController extends Controller
{
    public function index(Request $request): View
    {
        $query = Requisition::with(['department', 'location', 'legalEntity', 'hiringManager', 'creator'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('q')) {
            $q = $request->string('q')->toString();
            $query->where(function ($sub) use ($q) {
                $sub->where('title', 'like', "%{$q}%")
                    ->orWhere('requisition_number', 'like', "%{$q}%")
                    ->orWhere('budget_reference', 'like', "%{$q}%");
            });
        }

        return view('requisitions.index', [
            'requisitions' => $query->paginate(15)->withQueryString(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(),
            'locations' => Location::where('is_active', true)->orderBy('name')->get(),
            'legalEntities' => LegalEntity::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function show(int $requisitionId, TenantContextService $context): View
    {
        $requisition = Requisition::withoutTenantScope()->with([
            'department', 'location', 'legalEntity', 'hiringManager', 'creator', 'approver', 'versions.creator', 'approvals.approver',
        ])->findOrFail($requisitionId);

        $context->assertModelBelongsToActiveTenant($requisition, 'Requisition');

        return view('requisitions.show', [
            'requisition' => $requisition,
            'departments' => Department::where('is_active', true)->orderBy('name')->get(),
            'locations' => Location::where('is_active', true)->orderBy('name')->get(),
            'legalEntities' => LegalEntity::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, RequisitionService $service): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'legal_entity_id' => ['required', 'integer', 'exists:legal_entities,id'],
            'headcount' => ['required', 'integer', 'min:1', 'max:200'],
            'employment_type' => ['required', 'in:FULL_TIME,PART_TIME,CONTRACT,INTERNSHIP'],
            'requisition_reason' => ['required', 'in:NEW_ROLE,REPLACEMENT,EXPANSION'],
            'budget_reference' => ['required', 'string', 'max:80'],
            'salary_min' => ['required', 'numeric', 'min:0'],
            'salary_max' => ['required', 'numeric', 'gte:salary_min'],
            'currency_code' => ['required', 'string', 'size:3'],
            'target_start_date' => ['nullable', 'date'],
            'justification' => ['required', 'string', 'min:10'],
        ]);

        // Ensure referenced organization records belong to active tenant
        Department::findOrFail((int) $validated['department_id']);
        Location::findOrFail((int) $validated['location_id']);
        LegalEntity::findOrFail((int) $validated['legal_entity_id']);

        $req = $service->createRequisition($validated, Auth::user());

        return redirect()->route('requisitions.show', $req->id)
            ->with('status', "Created requisition {$req->requisition_number} in DRAFT status (Version 1).");
    }

    public function update(Request $request, int $requisitionId, RequisitionService $service): RedirectResponse
    {
        $requisition = Requisition::withoutTenantScope()->findOrFail($requisitionId);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'headcount' => ['required', 'integer', 'min:1', 'max:200'],
            'budget_reference' => ['required', 'string', 'max:80'],
            'salary_min' => ['required', 'numeric', 'min:0'],
            'salary_max' => ['required', 'numeric', 'gte:salary_min'],
            'justification' => ['required', 'string', 'min:10'],
            'change_summary' => ['nullable', 'string', 'max:255'],
        ]);

        $updated = $service->updateRequisition($requisition, $validated, Auth::user());

        return redirect()->route('requisitions.show', $updated->id)
            ->with('status', "Updated requisition {$updated->requisition_number} to Version {$updated->current_version} ({$updated->status}).");
    }

    public function submit(int $requisitionId, RequisitionService $service): RedirectResponse
    {
        $requisition = Requisition::withoutTenantScope()->findOrFail($requisitionId);
        $updated = $service->submitForApproval($requisition, Auth::user());

        return redirect()->route('requisitions.show', $updated->id)
            ->with('status', "Submitted requisition {$updated->requisition_number} for approval ({$updated->status}).");
    }

    public function decideApproval(Request $request, int $requisitionId, RequisitionService $service): RedirectResponse
    {
        $requisition = Requisition::withoutTenantScope()->findOrFail($requisitionId);

        $validated = $request->validate([
            'decision' => ['required', 'in:APPROVED,REJECTED'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $service->decideApproval($requisition, Auth::user(), $validated['decision'], $validated['comments'] ?? null);

        return redirect()->route('requisitions.show', $updated->id)
            ->with('status', "Recorded {$validated['decision']} decision on {$updated->requisition_number} (Status: {$updated->status}).");
    }

    public function transitionState(Request $request, int $requisitionId, RequisitionService $service): RedirectResponse
    {
        $requisition = Requisition::withoutTenantScope()->findOrFail($requisitionId);

        $validated = $request->validate([
            'target_status' => ['required', 'in:OPEN,ON_HOLD,CLOSED,CANCELLED'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $updated = $service->transitionLifecycleState($requisition, $validated['target_status'], Auth::user(), $validated['reason'] ?? null);

        return redirect()->route('requisitions.show', $updated->id)
            ->with('status', "Transitioned {$updated->requisition_number} to {$updated->status}.");
    }
}
