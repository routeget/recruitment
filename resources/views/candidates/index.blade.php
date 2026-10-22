@extends('layouts.app')

@section('title', 'Candidate CRM & Private Documents')

@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Candidate CRM</li>
            </ol>
        </nav>
        <h1 class="h3 fw-bold mb-0">Candidate CRM, Consent &amp; Private Document Repository</h1>
        <p class="text-muted small mb-0">Tenant-scoped candidate records with deduplication by email, consent tracking, and SHA-256 verified private document storage.</p>
    </div>
    @if(in_array('candidates.manage', $activeUserPermissions ?? []))
        <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#newCandidateCollapse">
            + Add Candidate Profile
        </button>
    @endif
</div>

@if(in_array('candidates.manage', $activeUserPermissions ?? []))
<div class="collapse mb-4" id="newCandidateCollapse">
    <div class="card stat-card border-primary">
        <div class="card-header bg-primary bg-opacity-10 fw-bold">Register / Deduplicate Candidate Profile</div>
        <div class="card-body">
            <form method="POST" action="{{ route('candidates.store') }}" enctype="multipart/form-data" class="row g-3">
                @csrf
                <div class="col-md-3">
                    <label for="first_name" class="form-label small fw-semibold">First Name</label>
                    <input type="text" id="first_name" name="first_name" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-3">
                    <label for="last_name" class="form-label small fw-semibold">Last Name</label>
                    <input type="text" id="last_name" name="last_name" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-4">
                    <label for="email" class="form-label small fw-semibold">Email (Deduplication Key)</label>
                    <input type="email" id="email" name="email" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-2">
                    <label for="source_channel" class="form-label small fw-semibold">Source</label>
                    <select id="source_channel" name="source_channel" class="form-select form-select-sm" required>
                        <option value="SOURCED">SOURCED</option>
                        <option value="REFERRAL">REFERRAL</option>
                        <option value="CAREER_PORTAL">CAREER_PORTAL</option>
                        <option value="AGENCY">AGENCY</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="current_title" class="form-label small fw-semibold">Current Role</label>
                    <input type="text" id="current_title" name="current_title" class="form-control form-control-sm" placeholder="Senior Cloud Architect">
                </div>
                <div class="col-md-2">
                    <label for="years_experience" class="form-label small fw-semibold">Years Exp.</label>
                    <input type="number" id="years_experience" name="years_experience" class="form-control form-control-sm" value="6" min="0">
                </div>
                <div class="col-md-4">
                    <label for="skills" class="form-label small fw-semibold">Skills (Comma-Separated)</label>
                    <input type="text" id="skills" name="skills" class="form-control form-control-sm" placeholder="PHP, Laravel, MariaDB, Redis">
                </div>
                <div class="col-md-2">
                    <label for="country_code" class="form-label small fw-semibold">Country</label>
                    <input type="text" id="country_code" name="country_code" class="form-control form-control-sm" value="US" maxlength="2" required>
                </div>
                <div class="col-md-6">
                    <label for="resume_file" class="form-label small fw-semibold">Private Resume File (PDF/DOCX/TXT)</label>
                    <input type="file" id="resume_file" name="resume_file" class="form-control form-control-sm" accept=".pdf,.doc,.docx,.txt">
                </div>
                <div class="col-md-6 d-flex align-items-end justify-content-between">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="consent_given" name="consent_given" value="1" checked required>
                        <label class="form-check-label small" for="consent_given">Privacy consent recorded (<code>PRIVACY-v2.0</code>)</label>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary">Save Candidate</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

<div class="card stat-card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('candidates.index') }}" class="row g-2 align-items-end">
            <div class="col-md-9">
                <label for="q" class="form-label small fw-semibold">Search Candidate Name, Email, or Code</label>
                <input type="text" id="q" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="CAND-2026... or name/email">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary w-100">Search</button>
                <a href="{{ route('candidates.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
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
                        <th>Code</th>
                        <th>Name &amp; Email</th>
                        <th>Title &amp; Experience</th>
                        <th>Source</th>
                        <th>Consent</th>
                        <th>Docs / Apps</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($candidates as $cand)
                        <tr>
                            <td><code>{{ $cand->candidate_code }}</code></td>
                            <td>
                                <div class="fw-semibold">{{ $cand->full_name }}</div>
                                <code class="small">{{ $cand->email }}</code>
                            </td>
                            <td class="small">
                                <div>{{ $cand->current_title ?? '—' }}</div>
                                <div class="text-muted">{{ $cand->years_experience }} yrs exp</div>
                            </td>
                            <td><span class="badge bg-light text-dark border">{{ $cand->source_channel }}</span></td>
                            <td>
                                @if($cand->consent_given)
                                    <span class="badge bg-success">CONSENTED ({{ $cand->consent_version }})</span>
                                @else
                                    <span class="badge bg-danger">MISSING</span>
                                @endif
                            </td>
                            <td class="small">{{ $cand->documents_count }} docs / {{ $cand->applications_count }} apps</td>
                            <td>
                                <a href="{{ route('candidates.show', $cand->id) }}" class="btn btn-sm btn-outline-primary">View Profile</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No candidates found in this tenant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
