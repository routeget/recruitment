@extends('layouts.app')

@section('title', 'Workforce Requisitions')

@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Workforce Requisitions</li>
            </ol>
        </nav>
        <h1 class="h3 fw-bold mb-0">Workforce Requisitions &amp; Headcount Approvals</h1>
        <p class="text-muted small mb-0">Lifecycle: <code>DRAFT → SUBMITTED/IN_APPROVAL → APPROVED → OPEN / ON_HOLD → CLOSED / CANCELLED</code></p>
    </div>
    @if(in_array('requisitions.create', $activeUserPermissions ?? []))
        <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#newRequisitionCollapse" aria-expanded="false">
            + Create Workforce Requisition
        </button>
    @endif
</div>

@if(in_array('requisitions.create', $activeUserPermissions ?? []))
<div class="collapse mb-4" id="newRequisitionCollapse">
    <div class="card stat-card border-primary">
        <div class="card-header bg-primary bg-opacity-10 fw-bold">Create New Workforce Requisition</div>
        <div class="card-body">
            <form method="POST" action="{{ route('requisitions.store') }}" class="row g-3">
                @csrf
                <div class="col-md-5">
                    <label for="title" class="form-label fw-semibold small">Role Title</label>
                    <input type="text" id="title" name="title" class="form-control form-control-sm" required placeholder="Principal Distributed Systems Engineer">
                </div>
                <div class="col-md-3">
                    <label for="department_id" class="form-label fw-semibold small">Department</label>
                    <select id="department_id" name="department_id" class="form-select form-select-sm" required>
                        @foreach($departments as $d)
                            <option value="{{ $d->id }}">{{ $d->code }} — {{ $d->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="location_id" class="form-label fw-semibold small">Location</label>
                    <select id="location_id" name="location_id" class="form-select form-select-sm" required>
                        @foreach($locations as $l)
                            <option value="{{ $l->id }}">{{ $l->code }} ({{ $l->city }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="legal_entity_id" class="form-label fw-semibold small">Legal Entity</label>
                    <select id="legal_entity_id" name="legal_entity_id" class="form-select form-select-sm" required>
                        @foreach($legalEntities as $le)
                            <option value="{{ $le->id }}">{{ $le->code }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label for="headcount" class="form-label fw-semibold small">Headcount</label>
                    <input type="number" id="headcount" name="headcount" class="form-control form-control-sm" value="1" min="1" max="200" required>
                </div>
                <div class="col-md-2">
                    <label for="employment_type" class="form-label fw-semibold small">Employment Type</label>
                    <select id="employment_type" name="employment_type" class="form-select form-select-sm" required>
                        <option value="FULL_TIME">FULL_TIME</option>
                        <option value="PART_TIME">PART_TIME</option>
                        <option value="CONTRACT">CONTRACT</option>
                        <option value="INTERNSHIP">INTERNSHIP</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="requisition_reason" class="form-label fw-semibold small">Reason</label>
                    <select id="requisition_reason" name="requisition_reason" class="form-select form-select-sm" required>
                        <option value="NEW_ROLE">NEW_ROLE</option>
                        <option value="REPLACEMENT">REPLACEMENT</option>
                        <option value="EXPANSION">EXPANSION</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="budget_reference" class="form-label fw-semibold small">Budget Ref</label>
                    <input type="text" id="budget_reference" name="budget_reference" class="form-control form-control-sm" required placeholder="BUD-2026-ENG-09">
                </div>
                <div class="col-md-2">
                    <label for="salary_min" class="form-label fw-semibold small">Salary Min</label>
                    <input type="number" step="0.01" id="salary_min" name="salary_min" class="form-control form-control-sm" value="140000" required>
                </div>
                <div class="col-md-2">
                    <label for="salary_max" class="form-label fw-semibold small">Salary Max</label>
                    <input type="number" step="0.01" id="salary_max" name="salary_max" class="form-control form-control-sm" value="185000" required>
                </div>
                <input type="hidden" name="currency_code" value="{{ $activeTenant->default_currency ?? 'USD' }}">

                <div class="col-md-9">
                    <label for="justification" class="form-label fw-semibold small">Business Justification</label>
                    <input type="text" id="justification" name="justification" class="form-control form-control-sm" required placeholder="Critical headcount required for Q4 platform delivery and reliability.">
                </div>
                <div class="col-md-3">
                    <label for="target_start_date" class="form-label fw-semibold small">Target Start Date</label>
                    <input type="date" id="target_start_date" name="target_start_date" class="form-control form-control-sm">
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-sm btn-primary">Save Draft Requisition</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

<div class="card stat-card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('requisitions.index') }}" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label for="q" class="form-label small fw-semibold">Search Requisition # / Title / Budget</label>
                <input type="text" id="q" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="REQ-2026... or title">
            </div>
            <div class="col-md-4">
                <label for="status" class="form-label small fw-semibold">Status Filter</label>
                <select id="status" name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    @foreach(['DRAFT','IN_APPROVAL','APPROVED','OPEN','ON_HOLD','CLOSED','CANCELLED'] as $st)
                        <option value="{{ $st }}" @selected(request('status') === $st)>{{ $st }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
                <a href="{{ route('requisitions.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card stat-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Requisition #</th>
                        <th>Role Title</th>
                        <th>Dept / Location / Entity</th>
                        <th>Headcount</th>
                        <th>Budget &amp; Band</th>
                        <th>Version</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($requisitions as $req)
                        <tr>
                            <td><code>{{ $req->requisition_number }}</code></td>
                            <td class="fw-semibold">{{ $req->title }}</td>
                            <td class="small">
                                {{ $req->department?->code }} &bull;
                                {{ $req->location?->code }} &bull;
                                <code>{{ $req->legalEntity?->code }}</code>
                            </td>
                            <td>{{ $req->filled_headcount }} / {{ $req->headcount }}</td>
                            <td class="small">
                                <div><code>{{ $req->budget_reference }}</code></div>
                                <div class="text-muted">{{ $req->currency_code }} {{ number_format((float)$req->salary_min) }} – {{ number_format((float)$req->salary_max) }}</div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border">v{{ $req->current_version }}</span>
                                @if($req->approved_version)
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Approved v{{ $req->approved_version }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ in_array($req->status, ['APPROVED','OPEN']) ? 'bg-success' : ($req->status === 'IN_APPROVAL' ? 'bg-warning text-dark' : 'bg-secondary') }}">
                                    {{ $req->status }}
                                </span>
                            </td>
                            <td>
                                <a href="{{ route('requisitions.show', $req->id) }}" class="btn btn-sm btn-outline-primary">Manage</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No requisitions found in this tenant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($requisitions->hasPages())
        <div class="card-footer bg-white">
            {{ $requisitions->links() }}
        </div>
    @endif
</div>
@endsection
