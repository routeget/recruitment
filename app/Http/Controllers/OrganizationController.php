<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\LegalEntity;
use App\Models\Location;
use App\Services\AuditService;
use App\Services\TenantContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function index(TenantContextService $context): View
    {
        return view('organization.index', [
            'tenant' => $context->getActiveTenant(),
            'legalEntities' => LegalEntity::orderBy('code')->get(),
            'departments' => Department::with('legalEntity')->orderBy('code')->get(),
            'locations' => Location::orderBy('code')->get(),
        ]);
    }

    public function storeLegalEntity(Request $request, AuditService $audit): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:160'],
            'country_code' => ['required', 'string', 'size:2'],
            'currency_code' => ['required', 'string', 'size:3'],
            'dynamics_company_code' => ['nullable', 'string', 'max:32'],
        ]);

        $entity = LegalEntity::updateOrCreate(
            ['code' => strtoupper($validated['code'])],
            [
                'name' => $validated['name'],
                'country_code' => strtoupper($validated['country_code']),
                'currency_code' => strtoupper($validated['currency_code']),
                'dynamics_company_code' => $validated['dynamics_company_code'] ? strtoupper($validated['dynamics_company_code']) : strtoupper($validated['code']),
                'is_active' => true,
            ]
        );

        $audit->record(
            module: 'organization',
            action: 'legal_entity_saved',
            outcome: 'SUCCESS',
            entityType: 'LegalEntity',
            entityId: (string) $entity->id,
            metadata: ['code' => $entity->code, 'name' => $entity->name]
        );

        return redirect()->route('organization.index')
            ->with('status', "Legal entity {$entity->code} ({$entity->name}) saved.");
    }

    public function storeDepartment(Request $request, AuditService $audit): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:160'],
            'legal_entity_id' => ['nullable', 'integer', 'exists:legal_entities,id'],
            'cost_centre_code' => ['nullable', 'string', 'max:48'],
        ]);

        if (! empty($validated['legal_entity_id'])) {
            LegalEntity::findOrFail((int) $validated['legal_entity_id']);
        }

        $dept = Department::updateOrCreate(
            ['code' => strtoupper($validated['code'])],
            [
                'name' => $validated['name'],
                'legal_entity_id' => $validated['legal_entity_id'] ?? null,
                'cost_centre_code' => $validated['cost_centre_code'] ?? null,
                'is_active' => true,
            ]
        );

        $audit->record(
            module: 'organization',
            action: 'department_saved',
            outcome: 'SUCCESS',
            entityType: 'Department',
            entityId: (string) $dept->id,
            metadata: ['code' => $dept->code, 'name' => $dept->name]
        );

        return redirect()->route('organization.index')
            ->with('status', "Department {$dept->code} ({$dept->name}) saved.");
    }

    public function storeLocation(Request $request, AuditService $audit): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:160'],
            'city' => ['required', 'string', 'max:100'],
            'country_code' => ['required', 'string', 'size:2'],
            'timezone' => ['required', 'string', 'max:64'],
            'work_mode' => ['required', 'in:ONSITE,HYBRID,REMOTE'],
        ]);

        $loc = Location::updateOrCreate(
            ['code' => strtoupper($validated['code'])],
            [
                'name' => $validated['name'],
                'city' => $validated['city'],
                'country_code' => strtoupper($validated['country_code']),
                'timezone' => $validated['timezone'],
                'work_mode' => $validated['work_mode'],
                'is_active' => true,
            ]
        );

        $audit->record(
            module: 'organization',
            action: 'location_saved',
            outcome: 'SUCCESS',
            entityType: 'Location',
            entityId: (string) $loc->id,
            metadata: ['code' => $loc->code, 'city' => $loc->city]
        );

        return redirect()->route('organization.index')
            ->with('status', "Location {$loc->code} ({$loc->name}) saved.");
    }
}
