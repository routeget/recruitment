@extends('layouts.app')

@section('title', 'Job Descriptions & Publishing')

@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Job Descriptions &amp; Publishing</li>
            </ol>
        </nav>
        <h1 class="h3 fw-bold mb-0">Job Descriptions, Versioning &amp; Career Portal Publishing</h1>
        <p class="text-muted small mb-0">Lifecycle: <code>DRAFT → APPROVED → PUBLISHED → UNPUBLISHED / EXPIRED / CLOSED</code></p>
    </div>
    <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#newJobCollapse">
        + Author Job Description
    </button>
</div>

<div class="collapse mb-4" id="newJobCollapse">
    <div class="card stat-card border-primary">
        <div class="card-header bg-primary bg-opacity-10 fw-bold">Author New Job Description from Approved Requisition</div>
        <div class="card-body">
            <form method="POST" action="{{ route('jobs.store') }}" class="row g-3">
                @csrf
                <div class="col-md-5">
                    <label for="requisition_id" class="form-label small fw-semibold">Approved / Open Requisition</label>
                    <select id="requisition_id" name="requisition_id" class="form-select form-select-sm" required>
                        @foreach($requisitions as $req)
                            <option value="{{ $req->id }}">{{ $req->requisition_number }} — {{ $req->title }} ({{ $req->status }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="title" class="form-label small fw-semibold">Public Job Posting Title</label>
                    <input type="text" id="title" name="title" class="form-control form-control-sm" required placeholder="Senior AI Platform Architect">
                </div>
                <div class="col-md-3">
                    <label for="work_mode" class="form-label small fw-semibold">Work Mode &amp; Level</label>
                    <div class="input-group input-group-sm">
                        <select name="work_mode" class="form-select" required>
                            <option value="HYBRID">HYBRID</option>
                            <option value="REMOTE">REMOTE</option>
                            <option value="ONSITE">ONSITE</option>
                        </select>
                        <select name="experience_level" class="form-select" required>
                            <option value="SENIOR">SENIOR</option>
                            <option value="LEAD">LEAD</option>
                            <option value="MID">MID</option>
                            <option value="ENTRY">ENTRY</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-12">
                    <label for="summary" class="form-label small fw-semibold">Role Summary</label>
                    <textarea id="summary" name="summary" class="form-control form-control-sm" rows="2" required placeholder="Lead the design and operational safety of our multi-tenant recruitment AI platform..."></textarea>
                </div>
                <div class="col-md-6">
                    <label for="responsibilities" class="form-label small fw-semibold">Key Responsibilities</label>
                    <textarea id="responsibilities" name="responsibilities" class="form-control form-control-sm" rows="3" required placeholder="- Architect resilient Laravel services&#10;- Enforce tenant isolation &amp; HR safety gates"></textarea>
                </div>
                <div class="col-md-6">
                    <label for="qualifications" class="form-label small fw-semibold">Required Qualifications</label>
                    <textarea id="qualifications" name="qualifications" class="form-control form-control-sm" rows="3" required placeholder="- 5+ years PHP/Laravel and relational schema design&#10;- Experience with enterprise integrations"></textarea>
                </div>
                <div class="col-md-8">
                    <label for="skills_required" class="form-label small fw-semibold">Required Skills (Comma-Separated)</label>
                    <input type="text" id="skills_required" name="skills_required" class="form-control form-control-sm" value="PHP 8.5, Laravel 13, MariaDB, Redis, Security Architecture">
                </div>
                <div class="col-md-4 d-flex align-items-end justify-content-between">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="show_compensation_publicly" id="show_compensation_publicly" value="1" checked>
                        <label class="form-check-label small" for="show_compensation_publicly">Display salary band publicly</label>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary">Save Draft JD</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card stat-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Title</th>
                        <th>Requisition</th>
                        <th>Location / Mode</th>
                        <th>Version</th>
                        <th>Status</th>
                        <th>External Job Boards</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($jobs as $job)
                        <tr>
                            <td><code>{{ $job->code }}</code></td>
                            <td class="fw-semibold">{{ $job->title }}</td>
                            <td><code>{{ $job->requisition?->requisition_number }}</code></td>
                            <td class="small">{{ $job->location?->city }} ({{ $job->work_mode }})</td>
                            <td><span class="badge bg-light text-dark border">v{{ $job->current_version }}</span></td>
                            <td>
                                <span class="badge {{ $job->status === 'PUBLISHED' ? 'bg-success' : 'bg-secondary' }}">
                                    {{ $job->status }}
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-warning text-dark">Unavailable (Unconfigured)</span>
                            </td>
                            <td>
                                <a href="{{ route('jobs.show', $job->id) }}" class="btn btn-sm btn-outline-primary">Manage</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No job descriptions authored yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
