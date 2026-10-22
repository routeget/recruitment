@extends('layouts.app')

@section('title', $candidate->candidate_code . ' — ' . $candidate->full_name)

@section('content')
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('candidates.index') }}">Candidate CRM</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $candidate->candidate_code }}</li>
            </ol>
        </nav>
        <h1 class="h3 fw-bold mb-0">{{ $candidate->full_name }}</h1>
        <p class="text-muted small mb-0">
            Candidate Code: <code>{{ $candidate->candidate_code }}</code> |
            Email: <code>{{ $candidate->email }}</code> |
            Consent: <span class="badge {{ $candidate->consent_given ? 'bg-success' : 'bg-danger' }}">{{ $candidate->consent_given ? $candidate->consent_version : 'WITHDRAWN' }}</span> |
            DSAR: <span class="badge bg-info text-dark">{{ $candidate->dsar_status ?? 'NONE' }}</span> |
            Retention Until: <code>{{ $candidate->retention_expires_at?->format('Y-m-d') ?? 'N/A' }}</code>
        </p>
    </div>
    <div>
        @php
            $statusBadgeClass = match($candidate->resume_parse_status ?? 'NONE') {
                'COMPLETED' => 'bg-success',
                'REVIEW_REQUIRED' => 'bg-warning text-dark',
                'FAILED' => 'bg-danger',
                default => 'bg-secondary',
            };
        @endphp
        <span class="badge {{ $statusBadgeClass }}">AI Resume Parse: {{ $candidate->resume_parse_status ?? 'NONE' }}</span>
        @if($candidate->resume_schema_version)
            <span class="badge bg-light text-dark border ms-1">{{ $candidate->resume_schema_version }}</span>
        @endif
    </div>
</div>

@if(in_array('candidates.manage', $activeUserPermissions ?? []))
<div class="card stat-card mb-4">
    <div class="card-header bg-white fw-bold">Privacy Consent &amp; DSAR Governance</div>
    <div class="card-body py-2">
        <form method="POST" action="{{ route('candidates.consent.update', $candidate->id) }}" class="row g-2 align-items-center">
            @csrf
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Consent Status</label>
                <select name="consent_given" class="form-select form-select-sm">
                    <option value="1" @selected($candidate->consent_given)>CONSENT_ACTIVE ({{ $candidate->consent_version }})</option>
                    <option value="0" @selected(!$candidate->consent_given)>CONSENT_WITHDRAWN</option>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label small fw-semibold mb-1">Data Subject Access Request (DSAR) Status</label>
                <select name="dsar_status" class="form-select form-select-sm">
                    @foreach(['NONE', 'EXPORT_REQUESTED', 'DELETION_REQUESTED', 'ANONYMIZED'] as $dsar)
                        <option value="{{ $dsar }}" @selected(($candidate->dsar_status ?? 'NONE') === $dsar)>{{ $dsar }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end pt-3">
                <button type="submit" class="btn btn-sm btn-outline-primary w-100">Update Privacy / DSAR</button>
            </div>
        </form>
    </div>
</div>
@endif

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-bold">Private Candidate Documents ({{ $candidate->documents->count() }})</div>
            <div class="card-body">
                @if(in_array('candidates.manage', $activeUserPermissions ?? []))
                <form method="POST" action="{{ route('candidates.documents.upload', $candidate->id) }}" enctype="multipart/form-data" class="row g-2 mb-3">
                    @csrf
                    <div class="col-md-4">
                        <label for="document_type" class="form-label small fw-semibold">Type</label>
                        <select id="document_type" name="document_type" class="form-select form-select-sm" required>
                            <option value="RESUME">RESUME (Auto-Parsed)</option>
                            <option value="COVER_LETTER">COVER_LETTER</option>
                            <option value="ID_VERIFICATION">ID_VERIFICATION</option>
                            <option value="SIGNED_OFFER">SIGNED_OFFER</option>
                            <option value="OTHER">OTHER</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label for="document" class="form-label small fw-semibold">File (PDF/DOCX/DOC/TXT)</label>
                        <input type="file" id="document" name="document" class="form-control form-control-sm" required accept=".pdf,.doc,.docx,.txt">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-sm btn-primary w-100">Upload Private</button>
                    </div>
                </form>
                @endif

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Type</th>
                                <th>Filename</th>
                                <th>SHA-256 Checksum</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($candidate->documents as $doc)
                                <tr>
                                    <td><span class="badge bg-secondary">{{ $doc->document_type }}</span></td>
                                    <td class="small">{{ $doc->original_filename }}</td>
                                    <td><code class="small" title="{{ $doc->sha256_checksum }}">{{ substr($doc->sha256_checksum, 0, 14) }}...</code></td>
                                    <td class="d-flex gap-1 flex-wrap">
                                        <a href="{{ route('documents.download', $doc->id) }}" class="btn btn-sm btn-outline-dark">
                                            Authorized Download
                                        </a>
                                        @if($doc->document_type === 'RESUME' && in_array('candidates.manage', $activeUserPermissions ?? []))
                                            <form method="POST" action="{{ route('candidates.documents.parse', [$candidate->id, $doc->id]) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Reprocess AI</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-3">No private documents uploaded yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-bold">Applications ({{ $candidate->applications->count() }})</div>
            <div class="card-body">
                @if(in_array('applications.manage', $activeUserPermissions ?? []) && $publishedJobs->count() > 0)
                <form method="POST" action="{{ route('applications.store') }}" class="row g-2 mb-3">
                    @csrf
                    <input type="hidden" name="candidate_id" value="{{ $candidate->id }}">
                    <div class="col-md-8">
                        <label for="job_posting_id" class="form-label small fw-semibold">Submit Candidate to Published Job</label>
                        <select id="job_posting_id" name="job_posting_id" class="form-select form-select-sm" required>
                            @foreach($publishedJobs as $pj)
                                <option value="{{ $pj->id }}">{{ $pj->code }} — {{ $pj->title }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-sm btn-success w-100">Create Application</button>
                    </div>
                </form>
                @endif

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Application #</th>
                                <th>Job</th>
                                <th>Internal Stage</th>
                                <th>AI Match</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($candidate->applications as $app)
                                <tr>
                                    <td><code>{{ $app->application_number }}</code></td>
                                    <td class="small">{{ $app->jobPosting?->title }}</td>
                                    <td><span class="badge bg-primary">{{ $app->current_stage }}</span></td>
                                    <td>
                                        @if($app->ai_match_score !== null)
                                            <span class="badge bg-info text-dark">{{ $app->ai_match_score }}% Match</span>
                                        @else
                                            <span class="text-muted small">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('applications.show', $app->id) }}" class="btn btn-sm btn-outline-primary">Pipeline</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3">No applications recorded for this candidate.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Intelligent Resume Parsing, Provenance & Human Review Panel --}}
<div class="card stat-card mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <span class="fw-bold">Intelligent Resume Extraction, Provenance &amp; Recruiter Review</span>
            <div class="small text-muted">Zero-hallucination structured extraction with prompt-injection defense and side-by-side human correction.</div>
        </div>
        @if(isset($latestParseJob) && $latestParseJob)
            <div class="d-flex gap-2 align-items-center">
                <span class="badge bg-light text-dark border">
                    Provider: {{ strtoupper($latestParseJob->provider_key ?? 'N/A') }} ({{ $latestParseJob->model_name ?? 'N/A' }})
                </span>
                <span class="badge bg-light text-dark border">Schema: {{ $latestParseJob->schema_version }}</span>
                @if($latestParseJob->prompt_injection_detected)
                    <span class="badge bg-danger">Prompt Injection Neutralized</span>
                @else
                    <span class="badge bg-success">Input Sanitized</span>
                @endif
                @if($latestParseJob->is_scanned_document)
                    <span class="badge bg-warning text-dark">Scanned PDF (OCR Required)</span>
                @endif
            </div>
        @endif
    </div>
    <div class="card-body">
        @if(isset($latestParseJob) && $latestParseJob)
            @php
                $effective = $latestParseJob->getEffectiveResumeData();
                $missingFields = (array) ($latestParseJob->missing_fields ?? []);
            @endphp

            @if($latestParseJob->error_message)
                <div class="alert alert-{{ $latestParseJob->status === 'FAILED' ? 'danger' : 'warning' }} small mb-3">
                    <strong>{{ $latestParseJob->error_code ?? $latestParseJob->status }}:</strong> {{ $latestParseJob->error_message }}
                </div>
            @endif

            @if(!empty($missingFields))
                <div class="alert alert-info py-2 px-3 small mb-3">
                    <strong>Unstated / Missing Resume Fields (Preserved as Null without Hallucination):</strong>
                    @foreach($missingFields as $mf)
                        <code class="me-1">{{ $mf }}</code>
                    @endforeach
                </div>
            @endif

            <div class="row g-4">
                <div class="col-lg-6">
                    <h3 class="h6 fw-bold border-bottom pb-2">Structured Candidate Profile Facts (Extracted)</h3>
                    <div class="small mb-3">
                        <strong>Professional Summary:</strong>
                        <p class="text-muted mb-2">{{ $candidate->professional_summary ?? ($effective['professional_summary'] ?? 'Not stated in resume.') }}</p>

                        <strong>Extracted Skills:</strong>
                        <div class="d-flex flex-wrap gap-1 my-1">
                            @forelse((array) ($candidate->skills ?? ($effective['skills'] ?? [])) as $skill)
                                <span class="badge bg-primary bg-opacity-10 text-primary border">{{ $skill }}</span>
                            @empty
                                <span class="text-muted">None extracted</span>
                            @endforelse
                        </div>
                    </div>

                    <div class="small mb-3">
                        <strong>Work Experience ({{ count((array) ($candidate->work_experience ?? ($effective['work_experience'] ?? []))) }}):</strong>
                        <ul class="list-group list-group-flush mt-1">
                            @forelse((array) ($candidate->work_experience ?? ($effective['work_experience'] ?? [])) as $exp)
                                <li class="list-group-item px-0 py-2">
                                    <div class="fw-semibold">{{ $exp['job_title'] ?? 'Role' }} @ {{ $exp['employer'] ?? 'Employer' }}</div>
                                    <div class="text-muted">{{ $exp['start_date'] ?? '?' }} &ndash; {{ ($exp['is_current'] ?? false) ? 'Present' : ($exp['end_date'] ?? '?') }}</div>
                                    @if(!empty($exp['responsibilities']))
                                        <div class="text-secondary mt-1">{{ $exp['responsibilities'] }}</div>
                                    @endif
                                </li>
                            @empty
                                <li class="list-group-item px-0 text-muted">No structured work experience entries extracted.</li>
                            @endforelse
                        </ul>
                    </div>

                    <div class="small">
                        <strong>Education ({{ count((array) ($candidate->education ?? ($effective['education'] ?? []))) }}) &amp; Certifications ({{ count((array) ($candidate->certifications ?? ($effective['certifications'] ?? []))) }}):</strong>
                        <ul class="list-group list-group-flush mt-1">
                            @foreach((array) ($candidate->education ?? ($effective['education'] ?? [])) as $edu)
                                <li class="list-group-item px-0 py-1">
                                    <strong>{{ $edu['degree'] ?? 'Qualification' }}</strong> — {{ $edu['institution'] ?? 'Institution' }} ({{ $edu['graduation_date'] ?? 'Year N/A' }})
                                </li>
                            @endforeach
                            @foreach((array) ($candidate->certifications ?? ($effective['certifications'] ?? [])) as $cert)
                                <li class="list-group-item px-0 py-1">
                                    <span class="badge bg-secondary me-1">CERT</span> {{ $cert['name'] ?? '' }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                <div class="col-lg-6">
                    <h3 class="h6 fw-bold border-bottom pb-2">
                        Recruiter Human Review &amp; Structured Correction
                        @if($latestParseJob->reviewed_at)
                            <span class="badge bg-success ms-2">Verified {{ $latestParseJob->reviewed_at->format('Y-m-d H:i') }}</span>
                        @endif
                    </h3>
                    @if(in_array('candidates.manage', $activeUserPermissions ?? []))
                        <form method="POST" action="{{ route('candidates.resume-parses.review', [$candidate->id, $latestParseJob->id]) }}" class="row g-2">
                            @csrf
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold mb-1">Full Name *</label>
                                <input type="text" name="full_name" class="form-control form-control-sm" value="{{ $effective['full_name'] ?? $candidate->full_name }}" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold mb-1">Email Address *</label>
                                <input type="email" name="email" class="form-control form-control-sm" value="{{ $effective['emails'][0] ?? $candidate->email }}" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold mb-1">Phone</label>
                                <input type="text" name="phone" class="form-control form-control-sm" value="{{ $effective['phones'][0] ?? $candidate->phone }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold mb-1">City / Location</label>
                                <input type="text" name="location" class="form-control form-control-sm" value="{{ $effective['location'] ?? $candidate->location_city }}">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold mb-1">Country</label>
                                <input type="text" name="country_code" class="form-control form-control-sm" value="{{ $effective['country_code'] ?? $candidate->country_code }}" maxlength="2">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label small fw-semibold mb-1">Current Title</label>
                                <input type="text" name="current_title" class="form-control form-control-sm" value="{{ $effective['current_title'] ?? $candidate->current_title }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold mb-1">Total Years Exp.</label>
                                <input type="number" name="total_years_experience" class="form-control form-control-sm" value="{{ $effective['total_years_experience'] ?? $candidate->years_experience }}" min="0">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold mb-1">Skills (Comma-Separated)</label>
                                <input type="text" name="skills" class="form-control form-control-sm" value="{{ implode(', ', (array) ($effective['skills'] ?? $candidate->skills ?? [])) }}">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold mb-1">Professional Summary</label>
                                <textarea name="professional_summary" class="form-control form-control-sm" rows="3">{{ $effective['professional_summary'] ?? $candidate->professional_summary }}</textarea>
                            </div>
                            <div class="col-12 text-end pt-2">
                                <button type="submit" class="btn btn-sm btn-primary">Confirm Review &amp; Save to Candidate Profile</button>
                            </div>
                        </form>
                    @else
                        <div class="text-muted small">You have read-only access to this candidate's parsed resume profile.</div>
                    @endif
                </div>
            </div>
        @else
            <div class="text-muted small py-2">
                No AI resume parsing job has been run for this candidate yet. Upload a resume above or click <strong>Reprocess AI</strong> next to an existing resume document.
            </div>
        @endif
    </div>
</div>
@endsection
