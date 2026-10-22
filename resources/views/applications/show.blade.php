@extends('layouts.app')

@section('title', $application->application_number . ' — ' . $application->candidate?->full_name)

@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('applications.index') }}">Applications</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $application->application_number }}</li>
            </ol>
        </nav>
        <h1 class="h3 fw-bold mb-0">
            {{ $application->candidate?->full_name }} &mdash; {{ $application->jobPosting?->title }}
            <span class="badge bg-primary ms-2">{{ $application->current_stage }}</span>
        </h1>
        <p class="text-muted small mb-0">
            Application #: <code>{{ $application->application_number }}</code> |
            Candidate-Visible Status: <span class="badge bg-light text-dark border">{{ $application->candidate_visible_status }}</span>
        </p>
    </div>

    <div class="d-flex gap-2">
        @if(Route::has('evaluations.index'))
            <a href="{{ route('evaluations.index', ['application_id' => $application->id]) }}" class="btn btn-outline-primary btn-sm">
                Screening, Interviews &amp; Decision
            </a>
        @endif
        @if(Route::has('offers.index'))
            <a href="{{ route('offers.index', ['application_id' => $application->id]) }}" class="btn btn-outline-success btn-sm">
                Offer Management
            </a>
        @endif
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        @if(in_array('applications.manage', $activeUserPermissions ?? []))
        <div class="card stat-card border-primary mb-4">
            <div class="card-header bg-primary bg-opacity-10 fw-bold">Guarded Stage Transition</div>
            <div class="card-body">
                <form method="POST" action="{{ route('applications.transition', $application->id) }}">
                    @csrf
                    <div class="mb-2">
                        <label for="target_stage" class="form-label small fw-semibold">Next Stage</label>
                        <select id="target_stage" name="target_stage" class="form-select form-select-sm" required>
                            @foreach(['ELIGIBILITY_REVIEW','SCREENING','SHORTLISTED','ASSESSMENT','INTERVIEW','DECISION_PENDING','OFFER_IN_PROGRESS','HIRED_PENDING_HR_HANDOVER','ON_HOLD','REJECTED','WITHDRAWN'] as $st)
                                <option value="{{ $st }}">{{ $st }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="notes" class="form-label small fw-semibold">Confidential Internal Recruiter Note (Never shown to candidate)</label>
                        <textarea id="notes" name="notes" class="form-control form-control-sm" rows="2" placeholder="Verified eligibility and core distributed systems experience."></textarea>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary w-100">Execute Stage Transition</button>
                </form>
            </div>
        </div>
        @endif

        <div class="card stat-card">
            <div class="card-header bg-white fw-bold">Candidate Summary &amp; Private Documents</div>
            <div class="card-body small">
                <p class="mb-2">
                    <strong>Email:</strong> <code>{{ $application->candidate?->email }}</code> |
                    <strong>Experience:</strong> {{ $application->candidate?->years_experience }} yrs
                </p>
                <p class="mb-2"><strong>Cover Note:</strong> {{ $application->cover_note ?? '—' }}</p>
                <hr>
                <div class="fw-bold mb-1">Confidential Recruiter Notes (Internal Only):</div>
                <pre class="bg-light p-2 border rounded small mb-3" style="white-space: pre-wrap;">{{ $application->screening_notes ?? 'No internal notes recorded.' }}</pre>

                <div class="fw-bold mb-1">Private Documents:</div>
                <ul class="list-group list-group-flush">
                    @forelse($application->candidate?->documents ?? [] as $doc)
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span>
                                <span class="badge bg-secondary me-1">{{ $doc->document_type }}</span>
                                {{ $doc->original_filename }}
                            </span>
                            <a href="{{ route('documents.download', $doc->id) }}" class="btn btn-sm btn-outline-dark">Download</a>
                        </li>
                    @empty
                        <li class="list-group-item px-0 text-muted">No documents attached.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card stat-card">
            <div class="card-header bg-white fw-bold">Immutable Stage History ({{ $application->stageEvents->count() }})</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Timestamp</th>
                                <th>Transition</th>
                                <th>Actor</th>
                                <th>Internal Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($application->stageEvents as $ev)
                                <tr>
                                    <td class="small text-muted">{{ $ev->created_at?->format('Y-m-d H:i:s') }}</td>
                                    <td class="small">
                                        <code>{{ $ev->from_stage ?? 'START' }}</code> &rarr;
                                        <span class="badge bg-primary">{{ $ev->to_stage }}</span>
                                    </td>
                                    <td class="small">{{ $ev->actor?->name ?? 'Candidate Portal' }}</td>
                                    <td class="small">{{ $ev->notes ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
