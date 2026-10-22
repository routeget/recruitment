@extends('layouts.app')

@section('title', $candidate->candidate_code . ' — ' . $candidate->full_name)

@section('content')
<div class="mb-4">
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
        Consent: <span class="badge bg-success">{{ $candidate->consent_version }}</span> |
        Retention Until: <code>{{ $candidate->retention_expires_at?->format('Y-m-d') ?? 'N/A' }}</code>
    </p>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card stat-card mb-4">
            <div class="card-header bg-white fw-bold">Private Candidate Documents ({{ $candidate->documents->count() }})</div>
            <div class="card-body">
                @if(in_array('candidates.manage', $activeUserPermissions ?? []))
                <form method="POST" action="{{ route('candidates.documents.upload', $candidate->id) }}" enctype="multipart/form-data" class="row g-2 mb-3">
                    @csrf
                    <div class="col-md-4">
                        <label for="document_type" class="form-label small fw-semibold">Type</label>
                        <select id="document_type" name="document_type" class="form-select form-select-sm" required>
                            <option value="RESUME">RESUME</option>
                            <option value="COVER_LETTER">COVER_LETTER</option>
                            <option value="ID_VERIFICATION">ID_VERIFICATION</option>
                            <option value="SIGNED_OFFER">SIGNED_OFFER</option>
                            <option value="OTHER">OTHER</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label for="document" class="form-label small fw-semibold">File (PDF/DOCX/TXT)</label>
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
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($candidate->documents as $doc)
                                <tr>
                                    <td><span class="badge bg-secondary">{{ $doc->document_type }}</span></td>
                                    <td class="small">{{ $doc->original_filename }}</td>
                                    <td><code class="small" title="{{ $doc->sha256_checksum }}">{{ substr($doc->sha256_checksum, 0, 14) }}...</code></td>
                                    <td>
                                        <a href="{{ route('documents.download', $doc->id) }}" class="btn btn-sm btn-outline-dark">
                                            Authorized Download
                                        </a>
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
        <div class="card stat-card mb-4">
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
                                        <a href="{{ route('applications.show', $app->id) }}" class="btn btn-sm btn-outline-primary">Pipeline</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-3">No applications recorded for this candidate.</td>
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
