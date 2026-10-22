@extends('layouts.app')

@section('title', $requisition->requisition_number . ' — ' . $requisition->title)

@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('requisitions.index') }}">Workforce Requisitions</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $requisition->requisition_number }}</li>
            </ol>
        </nav>
        <h1 class="h3 fw-bold mb-0">
            {{ $requisition->title }}
            <span class="badge {{ in_array($requisition->status, ['APPROVED','OPEN']) ? 'bg-success' : ($requisition->status === 'IN_APPROVAL' ? 'bg-warning text-dark' : 'bg-secondary') }} ms-2">
                {{ $requisition->status }}
            </span>
        </h1>
        <p class="text-muted small mb-0">
            Requisition #: <code>{{ $requisition->requisition_number }}</code> |
            Current Version: <code>v{{ $requisition->current_version }}</code> |
            Approved Version: <code>{{ $requisition->approved_version ? 'v'.$requisition->approved_version : 'None' }}</code> |
            Created By: {{ $requisition->creator?->name }}
        </p>
    </div>

    <div class="d-flex gap-2">
        @if($requisition->status === 'DRAFT' && in_array('requisitions.create', $activeUserPermissions ?? []))
            <form method="POST" action="{{ route('requisitions.submit', $requisition->id) }}">
                @csrf
                <button type="submit" class="btn btn-warning fw-semibold">Submit for Approval</button>
            </form>
        @endif

        @if(in_array($requisition->status, ['APPROVED', 'OPEN', 'ON_HOLD']) && in_array('requisitions.create', $activeUserPermissions ?? []))
            @if($requisition->status === 'APPROVED' || $requisition->status === 'ON_HOLD')
                <form method="POST" action="{{ route('requisitions.transition', $requisition->id) }}">
                    @csrf
                    <input type="hidden" name="target_status" value="OPEN">
                    <button type="submit" class="btn btn-success fw-semibold">Transition to OPEN</button>
                </form>
            @endif
            @if($requisition->status === 'APPROVED' || $requisition->status === 'OPEN')
                <form method="POST" action="{{ route('requisitions.transition', $requisition->id) }}">
                    @csrf
                    <input type="hidden" name="target_status" value="ON_HOLD">
                    <input type="hidden" name="reason" value="Paused for headcount review">
                    <button type="submit" class="btn btn-outline-warning">Place ON_HOLD</button>
                </form>
            @endif
            @if($requisition->status === 'OPEN')
                <form method="POST" action="{{ route('requisitions.transition', $requisition->id) }}">
                    @csrf
                    <input type="hidden" name="target_status" value="CLOSED">
                    <button type="submit" class="btn btn-outline-secondary">Mark CLOSED</button>
                </form>
            @endif
        @endif

        @if(!in_array($requisition->status, ['CLOSED', 'CANCELLED']) && in_array('requisitions.create', $activeUserPermissions ?? []))
            <form method="POST" action="{{ route('requisitions.transition', $requisition->id) }}">
                @csrf
                <input type="hidden" name="target_status" value="CANCELLED">
                <input type="hidden" name="reason" value="Cancelled by authorized requisition owner">
                <button type="submit" class="btn btn-outline-danger">Cancel Requisition</button>
            </form>
        @endif
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card stat-card mb-4">
            <div class="card-header bg-white fw-bold">Requisition Details &amp; Versioned Edit</div>
            <div class="card-body">
                <dl class="row small mb-3">
                    <dt class="col-sm-4 text-muted">Department / Location / Legal Entity</dt>
                    <dd class="col-sm-8">
                        {{ $requisition->department?->name }} (<code>{{ $requisition->department?->code }}</code>) &bull;
                        {{ $requisition->location?->name }} ({{ $requisition->location?->city }}) &bull;
                        {{ $requisition->legalEntity?->name }} (<code>{{ $requisition->legalEntity?->code }}</code>)
                    </dd>
                    <dt class="col-sm-4 text-muted">Employment Type &amp; Reason</dt>
                    <dd class="col-sm-8">{{ $requisition->employment_type }} ({{ $requisition->requisition_reason }})</dd>
                    <dt class="col-sm-4 text-muted">Approved By</dt>
                    <dd class="col-sm-8">
                        @if($requisition->approver)
                            {{ $requisition->approver->name }} at {{ $requisition->approved_at?->format('Y-m-d H:i:s') }} UTC
                        @else
                            <span class="text-muted">Not yet approved</span>
                        @endif
                    </dd>
                </dl>

                @if(in_array('requisitions.create', $activeUserPermissions ?? []) && !in_array($requisition->status, ['CLOSED', 'CANCELLED']))
                    <hr>
                    <h2 class="h6 fw-bold">Edit Requisition (Creates New Version; Resets Approval if Materially Changed)</h2>
                    <form method="POST" action="{{ route('requisitions.update', $requisition->id) }}" class="row g-2">
                        @csrf
                        @method('PUT')
                        <div class="col-md-8">
                            <label for="title" class="form-label small fw-semibold">Title</label>
                            <input type="text" id="title" name="title" class="form-control form-control-sm" value="{{ $requisition->title }}" required>
                        </div>
                        <div class="col-md-4">
                            <label for="headcount" class="form-label small fw-semibold">Headcount</label>
                            <input type="number" id="headcount" name="headcount" class="form-control form-control-sm" value="{{ $requisition->headcount }}" min="1" required>
                        </div>
                        <div class="col-md-4">
                            <label for="budget_reference" class="form-label small fw-semibold">Budget Ref</label>
                            <input type="text" id="budget_reference" name="budget_reference" class="form-control form-control-sm" value="{{ $requisition->budget_reference }}" required>
                        </div>
                        <div class="col-md-4">
                            <label for="salary_min" class="form-label small fw-semibold">Salary Min ({{ $requisition->currency_code }})</label>
                            <input type="number" step="0.01" id="salary_min" name="salary_min" class="form-control form-control-sm" value="{{ $requisition->salary_min }}" required>
                        </div>
                        <div class="col-md-4">
                            <label for="salary_max" class="form-label small fw-semibold">Salary Max ({{ $requisition->currency_code }})</label>
                            <input type="number" step="0.01" id="salary_max" name="salary_max" class="form-control form-control-sm" value="{{ $requisition->salary_max }}" required>
                        </div>
                        <div class="col-12">
                            <label for="justification" class="form-label small fw-semibold">Business Justification</label>
                            <textarea id="justification" name="justification" class="form-control form-control-sm" rows="2" required>{{ $requisition->justification }}</textarea>
                        </div>
                        <div class="col-md-8">
                            <label for="change_summary" class="form-label small fw-semibold">Version Change Summary</label>
                            <input type="text" id="change_summary" name="change_summary" class="form-control form-control-sm" placeholder="Reason for revision...">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-sm btn-outline-primary w-100">Save New Version</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        <div class="card stat-card">
            <div class="card-header bg-white fw-bold">Version History ({{ $requisition->versions->count() }})</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Version</th>
                                <th>Summary</th>
                                <th>Author</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($requisition->versions as $v)
                                <tr>
                                    <td><code>v{{ $v->version_number }}</code></td>
                                    <td class="small">{{ $v->change_summary }}</td>
                                    <td class="small">{{ $v->creator?->name }}</td>
                                    <td class="small text-muted">{{ $v->created_at?->format('Y-m-d H:i:s') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        @if($requisition->status === 'IN_APPROVAL' && in_array('requisitions.approve', $activeUserPermissions ?? []))
            <div class="card stat-card border-warning mb-4">
                <div class="card-header bg-warning bg-opacity-10 fw-bold">
                    Approval Governance Gate (Version v{{ $requisition->current_version }})
                </div>
                <div class="card-body">
                    @if((int)$requisition->created_by_user_id === (int)auth()->id())
                        <div class="alert alert-warning small mb-2">
                            <strong>Self-Approval Prohibited:</strong> You created this requisition ({{ auth()->user()->email }}). Per Specification §4, a different authorized approver must approve it.
                        </div>
                    @endif
                    <form method="POST" action="{{ route('requisitions.approve', $requisition->id) }}">
                        @csrf
                        <div class="mb-2">
                            <label for="decision" class="form-label small fw-semibold">Approval Decision</label>
                            <select id="decision" name="decision" class="form-select form-select-sm" required>
                                <option value="APPROVED">APPROVED — Authorize Requisition</option>
                                <option value="REJECTED">REJECTED — Return to Draft</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="comments" class="form-label small fw-semibold">Approver Rationale / Comments</label>
                            <textarea id="comments" name="comments" class="form-control form-control-sm" rows="2" placeholder="Verified against approved FY26 headcount budget."></textarea>
                        </div>
                        <button type="submit" class="btn btn-sm btn-success w-100 fw-semibold">Record Approval Decision</button>
                    </form>
                </div>
            </div>
        @endif

        <div class="card stat-card">
            <div class="card-header bg-white fw-bold">Approval Audit Trail ({{ $requisition->approvals->count() }})</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Version</th>
                                <th>Approver</th>
                                <th>Decision</th>
                                <th>Comments / Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($requisition->approvals as $ap)
                                <tr>
                                    <td><code>v{{ $ap->version_number }}</code></td>
                                    <td class="small">{{ $ap->approver?->name }}</td>
                                    <td>
                                        <span class="badge {{ $ap->decision === 'APPROVED' ? 'bg-success' : 'bg-danger' }}">
                                            {{ $ap->decision }}
                                        </span>
                                    </td>
                                    <td class="small">
                                        <div>{{ $ap->comments ?? '—' }}</div>
                                        <div class="text-muted">{{ $ap->decided_at?->format('Y-m-d H:i:s') }}</div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-3">No approval decisions recorded yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
