@extends('layouts.app')

@section('title', 'Applications & Pipeline')

@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Applications &amp; Pipeline</li>
            </ol>
        </nav>
        <h1 class="h3 fw-bold mb-0">Candidate Applications &amp; Stage Pipeline</h1>
        <p class="text-muted small mb-0">
            Guarded transitions: <code>RECEIVED → ELIGIBILITY_REVIEW → SCREENING → SHORTLISTED → ASSESSMENT → INTERVIEW → DECISION_PENDING → OFFER_IN_PROGRESS → HIRED_PENDING_HR_HANDOVER</code>
        </p>
    </div>
</div>

<div class="card stat-card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('applications.index') }}" class="row g-2 align-items-end">
            <div class="col-md-8">
                <label for="stage" class="form-label small fw-semibold">Filter by Pipeline Stage</label>
                <select id="stage" name="stage" class="form-select form-select-sm">
                    <option value="">All Stages</option>
                    @foreach(['RECEIVED','ELIGIBILITY_REVIEW','SCREENING','SHORTLISTED','ASSESSMENT','INTERVIEW','DECISION_PENDING','OFFER_IN_PROGRESS','HIRED_PENDING_HR_HANDOVER','REJECTED','WITHDRAWN','ON_HOLD'] as $st)
                        <option value="{{ $st }}" @selected(request('stage') === $st)>{{ $st }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
                <a href="{{ route('applications.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
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
                        <th>App #</th>
                        <th>Candidate</th>
                        <th>Job Posting / Requisition</th>
                        <th>Internal Stage</th>
                        <th>Candidate-Safe Status</th>
                        <th>Submitted</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($applications as $app)
                        <tr>
                            <td><code>{{ $app->application_number }}</code></td>
                            <td>
                                <div class="fw-semibold">{{ $app->candidate?->full_name }}</div>
                                <code class="small">{{ $app->candidate?->email }}</code>
                            </td>
                            <td class="small">
                                <div class="fw-semibold">{{ $app->jobPosting?->title }}</div>
                                <code>{{ $app->requisition?->requisition_number }}</code>
                            </td>
                            <td><span class="badge bg-primary">{{ $app->current_stage }}</span></td>
                            <td class="small text-muted">{{ $app->candidate_visible_status }}</td>
                            <td class="small">{{ $app->submitted_at?->format('Y-m-d') }}</td>
                            <td>
                                <a href="{{ route('applications.show', $app->id) }}" class="btn btn-sm btn-outline-primary">Manage Pipeline</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No applications found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($applications->hasPages())
            <div class="card-footer bg-white">
                {{ $applications->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
