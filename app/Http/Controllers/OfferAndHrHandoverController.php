<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Department;
use App\Models\ExternalEmployeeReference;
use App\Models\HrHandover;
use App\Models\HrValidationCase;
use App\Models\LegalEntity;
use App\Models\Location;
use App\Models\Offer;
use App\Models\OfferTemplate;
use App\Models\TransactionalOutboxEvent;
use App\Services\OfferAndHrHandoverService;
use App\Services\TenantContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OfferAndHrHandoverController extends Controller
{
    public function __construct(
        private readonly OfferAndHrHandoverService $service,
        private readonly TenantContextService $tenantContext,
    ) {}

    public function offersIndex(Request $request): View
    {
        $this->tenantContext->requireTenantId();

        $offersQuery = Offer::query()
            ->with(['application.candidate', 'application.jobPosting', 'currentVersion.legalEntity', 'acceptedVersion', 'hrValidationCase'])
            ->latest();

        if ($request->filled('application_id')) {
            $offersQuery->where('application_id', (int) $request->input('application_id'));
        }

        $offers = $offersQuery->get();

        $templates = OfferTemplate::query()->latest()->get();
        $applications = Application::query()->with(['candidate', 'jobPosting'])->latest()->get();
        $legalEntities = LegalEntity::query()->where('is_active', true)->orderBy('code')->get();
        $departments = Department::query()->where('is_active', true)->orderBy('code')->get();
        $locations = Location::query()->where('is_active', true)->orderBy('code')->get();

        return view('offers.index', [
            'offers' => $offers,
            'templates' => $templates,
            'applications' => $applications,
            'legalEntities' => $legalEntities,
            'departments' => $departments,
            'locations' => $locations,
            'selectedApplicationId' => $request->filled('application_id') ? (int) $request->input('application_id') : null,
        ]);
    }

    public function storeOfferTemplate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:160'],
            'body_template' => ['required', 'string', 'min:10'],
        ]);

        $validated['tenant_id'] = $this->tenantContext->requireTenantId();

        $template = $this->service->createOfferTemplate($validated, $request->user());

        return redirect()
            ->route('offers.index')
            ->with('status', "Offer template {$template->code} created.");
    }

    public function storeOffer(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'application_id' => ['required', 'integer'],
            'job_title' => ['required', 'string', 'max:200'],
            'legal_entity_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'employment_type' => ['required', 'string', 'max:40'],
            'base_salary_minor' => ['required', 'integer', 'min:100'],
            'currency_code' => ['required', 'string', 'size:3'],
            'bonus_terms' => ['nullable', 'string', 'max:1000'],
            'equity_terms' => ['nullable', 'string', 'max:1000'],
            'start_date' => ['required', 'date'],
            'probation_months' => ['nullable', 'integer', 'min:0', 'max:24'],
        ]);

        $application = Application::query()->findOrFail($validated['application_id']);

        $offer = $this->service->createOffer($application, $validated, $request->user());

        return redirect()
            ->route('offers.index')
            ->with('status', "Offer v1 created for application #{$application->id} (snapshot: ".substr((string) $offer->currentVersion?->snapshot_hash, 0, 10).'...).');
    }

    public function issueOffer(Request $request, string $id): RedirectResponse
    {
        $offer = Offer::query()->findOrFail($id);

        $this->service->approveAndIssueOffer($offer, $request->user());

        return redirect()
            ->route('offers.index')
            ->with('status', 'Offer approved and issued to candidate.');
    }

    public function acceptOffer(Request $request, string $id): RedirectResponse
    {
        $offer = Offer::query()->findOrFail($id);

        $this->service->acceptOffer($offer, $request->user());

        return redirect()
            ->route('offers.index')
            ->with('status', 'Offer accepted. Mandatory HR Validation Case opened (Employee creation remains blocked until HR validation & explicit HR approval).');
    }

    public function reviseOffer(Request $request, string $id): RedirectResponse
    {
        $offer = Offer::query()->findOrFail($id);

        $validated = $request->validate([
            'job_title' => ['nullable', 'string', 'max:200'],
            'legal_entity_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'employment_type' => ['nullable', 'string', 'max:40'],
            'base_salary_minor' => ['nullable', 'integer', 'min:100'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'bonus_terms' => ['nullable', 'string', 'max:1000'],
            'equity_terms' => ['nullable', 'string', 'max:1000'],
            'start_date' => ['nullable', 'date'],
            'probation_months' => ['nullable', 'integer', 'min:0', 'max:24'],
        ]);

        $newVersion = $this->service->reviseOfferMaterialTerms(
            $offer,
            array_filter($validated, fn ($v) => $v !== null && $v !== ''),
            $request->user()
        );

        return redirect()
            ->route('offers.index')
            ->with('status', "Offer revised to v{$newVersion->version_number}. Any previous HR validation or approval was automatically invalidated.");
    }

    public function hrHandoverIndex(): View
    {
        $this->tenantContext->requireTenantId();

        $cases = HrValidationCase::query()
            ->with([
                'application.candidate',
                'offerVersion.legalEntity',
                'offerVersion.department',
                'assignedValidator',
                'approvals.approver',
                'handovers.externalEmployeeReference',
            ])
            ->latest()
            ->get();

        $handovers = HrHandover::query()
            ->with(['validationCase.application.candidate', 'approval.approver', 'externalEmployeeReference'])
            ->latest()
            ->get();

        $outboxEvents = TransactionalOutboxEvent::query()->latest()->limit(25)->get();
        $employeeRefs = ExternalEmployeeReference::query()->with(['candidate', 'handover'])->latest()->get();

        return view('hr_handover.index', [
            'cases' => $cases,
            'handovers' => $handovers,
            'outboxEvents' => $outboxEvents,
            'employeeRefs' => $employeeRefs,
        ]);
    }

    public function updateChecklist(Request $request, string $id): RedirectResponse
    {
        $case = HrValidationCase::query()->findOrFail($id);

        $validated = $request->validate([
            'identity_verified' => ['nullable', 'boolean'],
            'right_to_work_verified' => ['nullable', 'boolean'],
            'background_check_status' => ['required', 'string', 'in:pending,passed,waived,failed'],
            'compensation_validated' => ['nullable', 'boolean'],
            'position_mapping_validated' => ['nullable', 'boolean'],
            'legal_entity_validated' => ['nullable', 'boolean'],
            'start_date_confirmed' => ['nullable', 'boolean'],
            'position_code' => ['required', 'string', 'max:64'],
        ]);

        $updated = $this->service->updateValidationChecklist($case, $validated, $request->user());

        return redirect()
            ->route('hr-handover.index')
            ->with('status', "HR validation checklist v{$updated->checklist_version} updated (status: {$updated->status}).");
    }

    public function approveHandover(Request $request, string $id): RedirectResponse
    {
        $case = HrValidationCase::query()->findOrFail($id);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $approval = $this->service->approveForHandover($case, $request->user(), $validated['comment'] ?? null);

        return redirect()
            ->route('hr-handover.index')
            ->with('status', 'Explicit HR Handover Approval recorded (payload hash: '.substr($approval->approved_payload_hash, 0, 12).'...).');
    }

    public function dispatchHandover(Request $request, string $id): RedirectResponse
    {
        $case = HrValidationCase::query()->findOrFail($id);

        $validated = $request->validate([
            'idempotency_key' => ['nullable', 'string', 'max:120'],
            'simulated_outcome' => ['nullable', 'string', 'in:succeeded,ambiguous_timeout,live'],
        ]);

        $handover = $this->service->dispatchHandover(
            $case,
            $request->user(),
            $validated['idempotency_key'] ?? null,
            $validated['simulated_outcome'] ?? 'succeeded',
        );

        return redirect()
            ->route('hr-handover.index')
            ->with('status', "HR Handover dispatch processed (status: {$handover->status}, external ID: ".($handover->external_employee_id ?? 'pending reconciliation').').');
    }

    public function reconcileHandover(Request $request, string $id): RedirectResponse
    {
        $handover = HrHandover::query()->findOrFail($id);

        $validated = $request->validate([
            'found_in_external_system' => ['required', 'boolean'],
        ]);

        $reconciled = $this->service->reconcileHandover(
            $handover,
            $request->user(),
            (bool) $validated['found_in_external_system'],
        );

        return redirect()
            ->route('hr-handover.index')
            ->with('status', "HR Handover reconciled (status: {$reconciled->status}).");
    }
}
