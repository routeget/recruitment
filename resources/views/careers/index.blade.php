@extends('layouts.app')

@section('title', 'Public Career Portal — Open Roles')

@section('content')
<div class="py-3">
    <div class="p-4 mb-4 rounded-3 bg-dark text-white">
        <div class="container-fluid py-2">
            <span class="badge bg-info text-dark mb-2">{{ $platformBranding['career_portal_banner'] ?? 'Public Career Portal & CMS' }}</span>
            <h1 class="display-6 fw-bold">Explore Approved Open Positions</h1>
            <p class="col-md-8 fs-6 text-light mb-0">
                Only approved, published job postings appear here with verified Schema.org <code>JobPosting</code> metadata.
            </p>
        </div>
    </div>

    <div class="card stat-card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('careers.index') }}" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label for="q" class="form-label small fw-semibold">Search Keyword / Role</label>
                    <input type="text" id="q" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Architect, Engineer, Clinical...">
                </div>
                <div class="col-md-3">
                    <label for="tenant" class="form-label small fw-semibold">Organization / Tenant</label>
                    <select id="tenant" name="tenant" class="form-select form-select-sm">
                        <option value="">All Organizations</option>
                        @foreach($tenants as $t)
                            <option value="{{ $t->slug }}" @selected(request('tenant') === $t->slug)>{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="work_mode" class="form-label small fw-semibold">Work Mode</label>
                    <select id="work_mode" name="work_mode" class="form-select form-select-sm">
                        <option value="">All Modes</option>
                        <option value="HYBRID" @selected(request('work_mode') === 'HYBRID')>HYBRID</option>
                        <option value="REMOTE" @selected(request('work_mode') === 'REMOTE')>REMOTE</option>
                        <option value="ONSITE" @selected(request('work_mode') === 'ONSITE')>ONSITE</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100">Search</button>
                    <a href="{{ route('careers.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3">
        @forelse($jobs as $job)
            <div class="col-md-6">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="badge bg-primary-subtle text-primary border">{{ $job->tenant?->name }}</span>
                                <span class="badge bg-light text-dark border">{{ $job->work_mode }} &bull; {{ $job->location?->city }}</span>
                            </div>
                            <h2 class="h5 fw-bold mb-1">
                                <a href="{{ route('careers.show', [$job->tenant->slug, $job->slug]) }}" class="text-decoration-none">
                                    {{ $job->title }}
                                </a>
                            </h2>
                            <p class="small text-muted mb-2">
                                Department: {{ $job->department?->name }} | Code: <code>{{ $job->code }}</code>
                            </p>
                            <p class="small mb-3">{{ Str::limit($job->summary, 160) }}</p>
                        </div>
                        <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                            <span class="small fw-semibold">
                                @if($job->show_compensation_publicly && $job->compensation_min)
                                    {{ $job->currency_code }} {{ number_format((float)$job->compensation_min) }} – {{ number_format((float)$job->compensation_max) }}
                                @else
                                    Competitive Compensation
                                @endif
                            </span>
                            <a href="{{ route('careers.show', [$job->tenant->slug, $job->slug]) }}" class="btn btn-sm btn-primary">
                                View &amp; Apply
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="card stat-card p-4 text-center text-muted">
                    No published positions match your current filter.
                </div>
            </div>
        @endforelse
    </div>
</div>
@endsection
