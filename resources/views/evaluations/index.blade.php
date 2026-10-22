@extends('layouts.app')

@section('title', 'Screening, Assessments, Interviews & Hiring Decisions')

@section('content')
<div class="mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-1 small">
            <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item active" aria-current="page">Screening, Interviews &amp; Decisions</li>
        </ol>
    </nav>
    <h1 class="h3 fw-bold mb-0">Candidate Screening, Assessments, Interview Scorecards &amp; Hiring Decisions</h1>
    <p class="text-muted small mb-0">Evidence-based evaluation workflows with structured scorecards and immutable hiring decision packets.</p>
</div>

<div class="row g-4 mb-4">
    @if(in_array('screening.manage', $activeUserPermissions ?? []))
    <div class="col-lg-4">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-bold">1. Record Screening &amp; Assessment</div>
            <div class="card-body">
                <form method="POST" action="{{ route('evaluations.screening.store') }}" class="mb-3">
                    @csrf
                    <div class="mb-2">
                        <label for="scr_app_id" class="form-label small fw-semibold">Application</label>
                        <select id="scr_app_id" name="application_id" class="form-select form-select-sm" required>
                            @foreach($applications as $app)
                                <option value="{{ $app->id }}" @selected($app->id === $selectedAppId)>
                                    {{ $app->application_number }} — {{ $app->candidate?->full_name }} ({{ $app->current_stage }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label for="overall_score" class="form-label small fw-semibold">Score (0-100)</label>
                            <input type="number" step="0.01" id="overall_score" name="overall_score" class="form-control form-control-sm" value="90" required>
                        </div>
                        <div class="col-6">
                            <label for="recommendation" class="form-label small fw-semibold">Recommendation</label>
                            <select id="recommendation" name="recommendation" class="form-select form-select-sm" required>
                                <option value="STRONG_YES">STRONG_YES</option>
                                <option value="YES">YES</option>
                                <option value="NEUTRAL">NEUTRAL</option>
                                <option value="NO">NO</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label for="scr_notes" class="form-label small fw-semibold">Criteria Evidence Notes</label>
                        <input type="text" id="scr_notes" name="notes" class="form-control form-control-sm" placeholder="Meets PHP 8.5, Laravel 13, and security requirements.">
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary w-100">Save Screening Evaluation</button>
                </form>

                <hr>
                <form method="POST" action="{{ route('evaluations.assessments.store') }}">
                    @csrf
                    <input type="hidden" name="application_id" value="{{ $selectedAppId }}">
                    <div class="row g-2 mb-2">
                        <div class="col-7">
                            <label for="ass_title" class="form-label small fw-semibold">Assessment Title</label>
                            <input type="text" id="ass_title" name="title" class="form-control form-control-sm" required placeholder="Distributed Architecture Case Study">
                        </div>
                        <div class="col-5">
                            <label for="ass_score" class="form-label small fw-semibold">Score</label>
                            <input type="number" step="0.01" id="ass_score" name="score" class="form-control form-control-sm" value="94">
                        </div>
                    </div>
                    <input type="hidden" name="assessment_type" value="CASE_STUDY">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">Record Assessment Result</button>
                </form>
            </div>
        </div>
    </div>
    @endif

    @if(in_array('interviews.manage', $activeUserPermissions ?? []))
    <div class="col-lg-4">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-bold">2. Schedule Interview &amp; Submit Scorecard</div>
            <div class="card-body">
                <form method="POST" action="{{ route('evaluations.interviews.store') }}" class="mb-3">
                    @csrf
                    <div class="mb-2">
                        <label for="int_app_id" class="form-label small fw-semibold">Application</label>
                        <select id="int_app_id" name="application_id" class="form-select form-select-sm" required>
                            @foreach($applications as $app)
                                <option value="{{ $app->id }}" @selected($app->id === $selectedAppId)>
                                    {{ $app->application_number }} — {{ $app->candidate?->full_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-7">
                            <label for="stage_name" class="form-label small fw-semibold">Interview Stage</label>
                            <input type="text" id="stage_name" name="stage_name" class="form-control form-control-sm" value="Architecture Panel" required>
                        </div>
                        <div class="col-5">
                            <label for="interview_mode" class="form-label small fw-semibold">Mode</label>
                            <select id="interview_mode" name="interview_mode" class="form-select form-select-sm" required>
                                <option value="VIDEO">VIDEO</option>
                                <option value="PANEL">PANEL</option>
                                <option value="ONSITE">ONSITE</option>
                            </select>
                        </div>
                    </div>
                    <input type="hidden" name="scheduled_start_at" value="{{ now()->addDay()->format('Y-m-d H:i:s') }}">
                    <input type="hidden" name="scheduled_end_at" value="{{ now()->addDay()->addHour()->format('Y-m-d H:i:s') }}">
                    <button type="submit" class="btn btn-sm btn-primary w-100">Schedule Interview</button>
                </form>

                @if($interviews->count() > 0)
                <hr>
                <form method="POST" action="{{ route('evaluations.scorecards.store') }}">
                    @csrf
                    <div class="mb-2">
                        <label for="interview_id" class="form-label small fw-semibold">Submit Scorecard for Interview</label>
                        <select id="interview_id" name="interview_id" class="form-select form-select-sm" required>
                            @foreach($interviews as $int)
                                <option value="{{ $int->id }}">#{{ $int->id }} {{ $int->stage_name }} ({{ $int->application?->candidate?->full_name }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-5">
                            <label for="overall_rating" class="form-label small fw-semibold">Rating (1-5)</label>
                            <input type="number" id="overall_rating" name="overall_rating" class="form-control form-control-sm" value="5" min="1" max="5" required>
                        </div>
                        <div class="col-7">
                            <label for="sc_rec" class="form-label small fw-semibold">Recommendation</label>
                            <select id="sc_rec" name="recommendation" class="form-select form-select-sm" required>
                                <option value="STRONG_HIRE">STRONG_HIRE</option>
                                <option value="HIRE">HIRE</option>
                                <option value="NO_HIRE">NO_HIRE</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label for="strengths_evidence" class="form-label small fw-semibold">Job-Related Evidence</label>
                        <input type="text" id="strengths_evidence" name="strengths_evidence" class="form-control form-control-sm" required placeholder="Summarize verified job-related technical and behavioral evidence...">
                    </div>
                    <button type="submit" class="btn btn-sm btn-success w-100">Submit Structured Scorecard</button>
                </form>
                @endif
            </div>
        </div>
    </div>
    @endif

    @if(in_array('decisions.manage', $activeUserPermissions ?? []))
    <div class="col-lg-4">
        <div class="card stat-card border-success h-100">
            <div class="card-header bg-success bg-opacity-10 fw-bold">3. Authorized Hiring Decision Packet</div>
            <div class="card-body">
                <form method="POST" action="{{ route('evaluations.decisions.store') }}">
                    @csrf
                    <div class="mb-2">
                        <label for="dec_app_id" class="form-label small fw-semibold">Application</label>
                        <select id="dec_app_id" name="application_id" class="form-select form-select-sm" required>
                            @foreach($applications as $app)
                                <option value="{{ $app->id }}" @selected($app->id === $selectedAppId)>
                                    {{ $app->application_number }} — {{ $app->candidate?->full_name }} ({{ $app->current_stage }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label for="decision" class="form-label small fw-semibold">Authorized Decision</label>
                        <select id="decision" name="decision" class="form-select form-select-sm" required>
                            <option value="APPROVE_FOR_OFFER">APPROVE_FOR_OFFER — Advance to Offer</option>
                            <option value="HOLD">HOLD — Place Application on Hold</option>
                            <option value="REJECT">REJECT — Close Application</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="rationale" class="form-label small fw-semibold">Decision Rationale &amp; Evidence Summary</label>
                        <textarea id="rationale" name="rationale" class="form-control form-control-sm" rows="3" required placeholder="Unanimous hire recommendation across technical screening and architecture panel scorecards."></textarea>
                    </div>
                    <button type="submit" class="btn btn-sm btn-success w-100 fw-semibold">Record Immutable Hiring Decision</button>
                </form>
            </div>
        </div>
    </div>
    @endif
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card stat-card">
            <div class="card-header bg-white fw-bold">Submitted Interview Scorecards ({{ $scorecards->count() }})</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Candidate</th>
                                <th>Interviewer</th>
                                <th>Rating</th>
                                <th>Recommendation</th>
                                <th>Evidence</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($scorecards as $sc)
                                <tr>
                                    <td class="small fw-semibold">{{ $sc->interview?->application?->candidate?->full_name }}</td>
                                    <td class="small">{{ $sc->interviewer?->name }}</td>
                                    <td><span class="badge bg-primary">{{ $sc->overall_rating }} / 5</span></td>
                                    <td><span class="badge bg-success">{{ $sc->recommendation }}</span></td>
                                    <td class="small">{{ $sc->strengths_evidence }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3">No scorecards submitted yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card stat-card">
            <div class="card-header bg-white fw-bold">Immutable Hiring Decisions ({{ $decisions->count() }})</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Candidate</th>
                                <th>Decision</th>
                                <th>Decided By</th>
                                <th>Rationale &amp; Packet</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($decisions as $dec)
                                <tr>
                                    <td class="small fw-semibold">{{ $dec->application?->candidate?->full_name }}</td>
                                    <td>
                                        <span class="badge {{ $dec->decision === 'APPROVE_FOR_OFFER' ? 'bg-success' : 'bg-secondary' }}">
                                            {{ $dec->decision }}
                                        </span>
                                    </td>
                                    <td class="small">{{ $dec->decisionMaker?->name }}</td>
                                    <td class="small">
                                        <div>{{ $dec->rationale }}</div>
                                        <code class="small text-muted">{{ json_encode($dec->evidence_summary) }}</code>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-3">No hiring decisions recorded yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-lg-6">
        <div class="card stat-card">
            <div class="card-header bg-white fw-bold">Screening Evaluations ({{ $screeningEvaluations->count() }})</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Application</th>
                                <th>Evaluator</th>
                                <th>Score</th>
                                <th>Recommendation</th>
                                <th>Evidence Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($screeningEvaluations as $scr)
                                <tr>
                                    <td class="small fw-semibold">{{ $scr->application?->application_number }} — {{ $scr->application?->candidate?->full_name }}</td>
                                    <td class="small">{{ $scr->evaluator?->name }}</td>
                                    <td><span class="badge bg-primary">{{ $scr->overall_score }}</span></td>
                                    <td><span class="badge bg-info text-dark">{{ $scr->recommendation }}</span></td>
                                    <td class="small">{{ $scr->notes }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3">No screening evaluations recorded yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card stat-card">
            <div class="card-header bg-white fw-bold">Assessment Results ({{ $assessments->count() }})</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Application</th>
                                <th>Title</th>
                                <th>Type</th>
                                <th>Score / Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($assessments as $ass)
                                <tr>
                                    <td class="small fw-semibold">{{ $ass->application?->application_number }} — {{ $ass->application?->candidate?->full_name }}</td>
                                    <td class="small">{{ $ass->title }}</td>
                                    <td><code>{{ $ass->assessment_type }}</code></td>
                                    <td>
                                        <span class="badge bg-success">{{ $ass->score ?? 'N/A' }}</span>
                                        <span class="badge bg-secondary">{{ $ass->status }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-3">No assessment results recorded yet.</td>
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
