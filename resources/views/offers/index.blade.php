@extends('layouts.app')

@section('title', 'Offers & Templates')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 fw-bold mb-1">Offers &amp; Immutable Version Management</h2>
        <p class="text-muted small mb-0">Generate SHA-256 snapshotted offer versions, issue offers, record candidate acceptance, and manage material revisions.</p>
    </div>
    <span class="badge bg-warning text-dark">HRH-01 Enforced: Offer Acceptance Never Auto-Creates Employee</span>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold py-3">Create Offer (Immutable Version v1)</div>
            <div class="card-body">
                <form method="POST" action="{{ route('offers.store') }}">
                    @csrf
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Application</label>
                            <select name="application_id" class="form-select form-select-sm" required>
                                @foreach($applications as $app)
                                    <option value="{{ $app->id }}">
                                        {{ $app->candidate?->first_name }} {{ $app->candidate?->last_name }} — {{ $app->jobPosting?->title }} ({{ $app->current_stage }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Job Title on Offer</label>
                            <input type="text" name="job_title" class="form-control form-control-sm" placeholder="Principal AI Platform Engineer" required>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Legal Entity</label>
                            <select name="legal_entity_id" class="form-select form-select-sm">
                                @foreach($legalEntities as $le)
                                    <option value="{{ $le->id }}">{{ $le->code }} — {{ $le->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Department</label>
                            <select name="department_id" class="form-select form-select-sm">
                                @foreach($departments as $dept)
                                    <option value="{{ $dept->id }}">{{ $dept->code }} — {{ $dept->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Location</label>
                            <select name="location_id" class="form-select form-select-sm">
                                @foreach($locations as $loc)
                                    <option value="{{ $loc->id }}">{{ $loc->code }} — {{ $loc->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Employment Type</label>
                            <select name="employment_type" class="form-select form-select-sm" required>
                                <option value="full_time">Full-Time</option>
                                <option value="part_time">Part-Time</option>
                                <option value="contract">Contract</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Base Salary (Minor)</label>
                            <input type="number" name="base_salary_minor" class="form-control form-control-sm" value="18500000" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Currency</label>
                            <input type="text" name="currency_code" class="form-control form-control-sm" value="USD" maxlength="3" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Proposed Start Date</label>
                            <input type="date" name="start_date" class="form-control form-control-sm" value="{{ now()->addDays(21)->format('Y-m-d') }}" required>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold">Bonus Terms</label>
                            <input type="text" name="bonus_terms" class="form-control form-control-sm" value="15% annual performance target">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold">Equity Terms</label>
                            <input type="text" name="equity_terms" class="form-control form-control-sm" value="10,000 RSUs over 4 years">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Probation (mo)</label>
                            <input type="number" name="probation_months" class="form-control form-control-sm" value="3">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Create Draft Offer (v1)</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold py-3">Create Offer Template</div>
            <div class="card-body">
                <form method="POST" action="{{ route('offers.templates.store') }}">
                    @csrf
                    <div class="row g-2 mb-2">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Code</label>
                            <input type="text" name="code" class="form-control form-control-sm" placeholder="TPL-US-FT" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold">Template Name</label>
                            <input type="text" name="name" class="form-control form-control-sm" placeholder="US Full-Time Engineering Offer" required>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Body Template</label>
                        <textarea name="body_template" class="form-control form-control-sm" rows="3" placeholder="Dear @{{candidate_name}}, we are pleased to offer you the position of @{{job_title}}..." required></textarea>
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm">Save Offer Template</button>
                </form>
                <hr>
                <div class="small fw-semibold mb-1">Active Offer Templates ({{ $templates->count() }})</div>
                <ul class="list-group list-group-flush small">
                    @forelse($templates as $tpl)
                        <li class="list-group-item px-0 py-1 d-flex justify-content-between">
                            <span><code>{{ $tpl->code }}</code> — {{ $tpl->name }}</span>
                            <span class="badge bg-success">Active</span>
                        </li>
                    @empty
                        <li class="list-group-item px-0 py-1 text-muted">No custom templates yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold py-3 d-flex justify-content-between align-items-center">
        <span>Offers &amp; Version History</span>
        <a href="{{ route('hr-handover.index') }}" class="btn btn-sm btn-outline-dark">Go to HR Validation &amp; Handover Gate &rarr;</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Candidate &amp; Role</th>
                    <th>Status</th>
                    <th>Current Version &amp; Terms</th>
                    <th>Snapshot Hash</th>
                    <th>HR Validation State</th>
                    <th>Lifecycle Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($offers as $offer)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $offer->application?->candidate?->first_name }} {{ $offer->application?->candidate?->last_name }}</div>
                            <div class="small text-muted">{{ $offer->currentVersion?->job_title }}</div>
                        </td>
                        <td>
                            <span class="badge bg-{{ $offer->status === 'accepted' ? 'success' : ($offer->status === 'issued' ? 'primary' : 'secondary') }}">
                                {{ $offer->status }}
                            </span>
                        </td>
                        <td class="small">
                            <div><strong>v{{ $offer->currentVersion?->version_number }}</strong> — {{ $offer->currentVersion?->currency_code }} {{ number_format(($offer->currentVersion?->base_salary_minor ?? 0) / 100, 2) }}</div>
                            <div class="text-muted">Start: {{ $offer->currentVersion?->start_date?->format('Y-m-d') }} | Entity: {{ $offer->currentVersion?->legalEntity?->code ?? 'USMF' }}</div>
                        </td>
                        <td>
                            <code class="small">{{ substr((string) $offer->currentVersion?->snapshot_hash, 0, 14) }}...</code>
                        </td>
                        <td>
                            @if($offer->hrValidationCase)
                                <span class="badge bg-{{ $offer->hrValidationCase->status === 'handed_over' ? 'success' : ($offer->hrValidationCase->invalidated_at ? 'danger' : 'info text-dark') }}">
                                    {{ $offer->hrValidationCase->status }}
                                </span>
                            @else
                                <span class="text-muted small">Not opened yet</span>
                            @endif
                        </td>
                        <td>
                            <div class="d-flex flex-wrap gap-1 mb-2">
                                @if($offer->status === 'draft')
                                    <form method="POST" action="{{ route('offers.issue', $offer->id) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-primary">Approve &amp; Issue</button>
                                    </form>
                                @endif
                                @if(in_array($offer->status, ['issued', 'viewed', 'approved'], true))
                                    <form method="POST" action="{{ route('offers.accept', $offer->id) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-success">Record Candidate Acceptance</button>
                                    </form>
                                @endif
                            </div>
                            <form method="POST" action="{{ route('offers.revise', $offer->id) }}" class="d-flex gap-1 align-items-center">
                                @csrf
                                <input type="date" name="start_date" class="form-control form-control-sm" style="width: 135px;" value="{{ $offer->currentVersion?->start_date?->addDays(7)->format('Y-m-d') }}">
                                <input type="number" name="base_salary_minor" class="form-control form-control-sm" style="width: 120px;" value="{{ ($offer->currentVersion?->base_salary_minor ?? 15000000) + 500000 }}">
                                <button type="submit" class="btn btn-sm btn-outline-warning text-dark">Revise Material Terms (HRH-04)</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No offers created yet. Use the form above to generate an offer.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
