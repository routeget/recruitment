@extends('layouts.app')

@section('title', 'Append-Only Security & Audit Trail')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Security &amp; Audit Trail</li>
            </ol>
        </nav>
        <h1 class="h3 fw-bold mb-0">Append-Only Tenant Security &amp; Compliance Audit Log</h1>
    </div>
</div>

<div class="card stat-card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.audit.index') }}" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label for="q" class="form-label small fw-semibold">Search Action / Actor / Correlation ID</label>
                <input type="text" id="q" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="e.g. hr_handover or corr-...">
            </div>
            <div class="col-md-3">
                <label for="module" class="form-label small fw-semibold">Module</label>
                <input type="text" id="module" name="module" value="{{ request('module') }}" class="form-control form-control-sm" placeholder="auth, requisitions, hr_handover...">
            </div>
            <div class="col-md-3">
                <label for="outcome" class="form-label small fw-semibold">Outcome</label>
                <select id="outcome" name="outcome" class="form-select form-select-sm">
                    <option value="">All Outcomes</option>
                    <option value="SUCCESS" @selected(request('outcome') === 'SUCCESS')>SUCCESS</option>
                    <option value="DENIED" @selected(request('outcome') === 'DENIED')>DENIED</option>
                    <option value="FAILED" @selected(request('outcome') === 'FAILED')>FAILED</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
                <a href="{{ route('admin.audit.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card stat-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Timestamp (UTC)</th>
                        <th>Correlation ID</th>
                        <th>Actor</th>
                        <th>Module / Action</th>
                        <th>Entity</th>
                        <th>Outcome</th>
                        <th>Redacted Metadata</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($events as $event)
                        <tr>
                            <td><code>#{{ $event->id }}</code></td>
                            <td class="small">{{ $event->created_at?->format('Y-m-d H:i:s') }}</td>
                            <td><code class="small">{{ $event->correlation_id }}</code></td>
                            <td class="small">
                                <span class="badge bg-light text-dark border">{{ $event->actor_type }}</span>
                                {{ $event->actor_identifier }}
                            </td>
                            <td>
                                <span class="badge bg-secondary">{{ $event->module }}</span>
                                <span class="fw-semibold small">{{ $event->action }}</span>
                            </td>
                            <td class="small">
                                @if($event->entity_type)
                                    {{ $event->entity_type }} #{{ $event->entity_id }}
                                @else
                                    &mdash;
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $event->outcome === 'SUCCESS' ? 'bg-success' : 'bg-danger' }}">
                                    {{ $event->outcome }}
                                </span>
                                @if($event->error_code)
                                    <div><code class="small text-danger">{{ $event->error_code }}</code></div>
                                @endif
                            </td>
                            <td class="small"><code class="text-break">{{ json_encode($event->metadata) }}</code></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No matching audit events found for this tenant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($events->hasPages())
        <div class="card-footer bg-white">
            {{ $events->links() }}
        </div>
    @endif
</div>
@endsection
