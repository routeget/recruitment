@extends('layouts.app')

@section('title', $job->code . ' — ' . $job->title)

@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('jobs.index') }}">Job Descriptions</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $job->code }}</li>
            </ol>
        </nav>
        <h1 class="h3 fw-bold mb-0">
            {{ $job->title }}
            <span class="badge {{ $job->status === 'PUBLISHED' ? 'bg-success' : 'bg-secondary' }} ms-2">{{ $job->status }}</span>
        </h1>
        <p class="text-muted small mb-0">
            Job Code: <code>{{ $job->code }}</code> |
            Requisition: <code>{{ $job->requisition?->requisition_number }} ({{ $job->requisition?->status }})</code> |
            Version: <code>v{{ $job->current_version }}</code>
        </p>
    </div>

    <div class="d-flex gap-2">
        @if($job->status !== 'PUBLISHED' && in_array('jobs.publish', $activeUserPermissions ?? []))
            <form method="POST" action="{{ route('jobs.publish', $job->id) }}">
                @csrf
                <input type="hidden" name="publish_now" value="1">
                <button type="submit" class="btn btn-success fw-semibold">Approve &amp; Publish to Career Portal</button>
            </form>
        @endif

        @if($job->status === 'PUBLISHED')
            <a href="{{ route('careers.show', [$activeTenant->slug, $job->slug]) }}" class="btn btn-outline-primary" target="_blank">
                View Live Career Portal Page
            </a>
            @if(in_array('jobs.publish', $activeUserPermissions ?? []))
                <form method="POST" action="{{ route('jobs.status', $job->id) }}">
                    @csrf
                    <input type="hidden" name="target_status" value="UNPUBLISHED">
                    <button type="submit" class="btn btn-outline-warning">Unpublish</button>
                </form>
                <form method="POST" action="{{ route('jobs.status', $job->id) }}">
                    @csrf
                    <input type="hidden" name="target_status" value="CLOSED">
                    <button type="submit" class="btn btn-outline-secondary">Close Posting</button>
                </form>
                <form method="POST" action="{{ route('jobs.status', $job->id) }}">
                    @csrf
                    <input type="hidden" name="target_status" value="EXPIRED">
                    <button type="submit" class="btn btn-outline-danger">Mark Expired</button>
                </form>
            @endif
        @endif
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card stat-card mb-4">
            <div class="card-header bg-white fw-bold">Structured Job Description (v{{ $job->current_version }})</div>
            <div class="card-body">
                <h2 class="h6 fw-bold">Summary</h2>
                <p class="small">{{ $job->summary }}</p>

                <h2 class="h6 fw-bold">Responsibilities</h2>
                <div class="small bg-light p-3 rounded border mb-3" style="white-space: pre-line; line-height: 1.6;">{{ $job->responsibilities }}</div>

                <h2 class="h6 fw-bold">Qualifications</h2>
                <div class="small bg-light p-3 rounded border mb-3" style="white-space: pre-line; line-height: 1.6;">{{ $job->qualifications }}</div>

                <h2 class="h6 fw-bold">Required Skills</h2>
                <div class="d-flex flex-wrap gap-1">
                    @foreach($job->skills_required ?? [] as $skill)
                        <span class="badge bg-primary-subtle text-primary border">{{ $skill }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card stat-card mb-4">
            <div class="card-header bg-white fw-bold">Publication &amp; External Channels</div>
            <div class="card-body small">
                <div class="mb-2">
                    <strong>Internal Career Portal:</strong>
                    <span class="badge {{ $job->status === 'PUBLISHED' ? 'bg-success' : 'bg-secondary' }}">{{ $job->status }}</span>
                </div>
                <hr>
                <div class="fw-semibold mb-2">External Job Board Syndication:</div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span>LinkedIn Jobs</span>
                    <span class="badge bg-light text-secondary border">Not Configured</span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span>Indeed Job Feed</span>
                    <span class="badge bg-light text-secondary border">Not Configured</span>
                </div>
            </div>
        </div>

        <div class="card stat-card mb-4">
            <div class="card-header bg-white fw-bold">Immutable Version History ({{ $job->versions->count() }})</div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush small">
                    @forelse($job->versions as $ver)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <strong>v{{ $ver->version_number }}</strong> — {{ $ver->change_summary }}
                                <div class="text-muted">{{ $ver->creator?->name ?? 'System' }}</div>
                            </div>
                            <code>{{ $ver->created_at?->format('Y-m-d') }}</code>
                        </li>
                    @empty
                        <li class="list-group-item text-muted text-center">No version snapshots recorded.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
