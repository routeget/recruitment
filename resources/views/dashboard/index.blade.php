@extends('layouts.app')

@section('title', 'Dashboard — ' . ($tenant?->name ?? 'Unified Recruitment AI Platform'))

@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item active" aria-current="page">Workspace Dashboard</li>
            </ol>
        </nav>
        <h1 class="h3 fw-bold mb-0">{{ $tenant?->name ?? 'No Active Tenant' }}</h1>
        <p class="text-muted small mb-0">
            Tenant Slug: <code>{{ $tenant?->slug }}</code> |
            Timezone: <code>{{ $tenant?->default_timezone }}</code> |
            Default Currency: <code>{{ $tenant?->default_currency }}</code>
        </p>
    </div>

    @if($userMemberships->pluck('tenant_id')->unique()->count() > 1)
        <form method="POST" action="{{ route('tenant.switch') }}" class="d-flex align-items-center gap-2">
            @csrf
            <label for="tenant_id_switch" class="form-label mb-0 small fw-semibold">Switch Tenant:</label>
            <select name="tenant_id" id="tenant_id_switch" class="form-select form-select-sm" style="width:auto;">
                @foreach($userMemberships->unique('tenant_id') as $membership)
                    <option value="{{ $membership->tenant_id }}" @selected($membership->tenant_id === $tenant?->id)>
                        {{ $membership->tenant?->name }}
                    </option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-sm btn-outline-primary">Switch</button>
        </form>
    @endif
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card p-3 h-100">
            <div class="text-muted small fw-semibold">Open / Active Requisitions</div>
            <div class="fs-2 fw-bold text-primary">{{ $metrics['open_requisitions'] }}</div>
            <div class="small text-muted">Departments configured: {{ $metrics['departments'] }}</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card p-3 h-100">
            <div class="text-muted small fw-semibold">Published Career Portal Jobs</div>
            <div class="fs-2 fw-bold text-success">{{ $metrics['published_jobs'] }}</div>
            <div class="small text-muted">Approved &amp; public with Schema.org JSON-LD</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card p-3 h-100">
            <div class="text-muted small fw-semibold">Candidates &amp; Active Applications</div>
            <div class="fs-2 fw-bold text-dark">{{ $metrics['candidates'] }} / {{ $metrics['active_applications'] }}</div>
            <div class="small text-muted">Candidates / In-progress applications</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card p-3 h-100">
            <div class="text-muted small fw-semibold">Accepted Offers / Pending HR Handovers</div>
            <div class="fs-2 fw-bold text-warning">{{ $metrics['accepted_offers'] }} / {{ $metrics['pending_hr_handovers'] }}</div>
            <div class="small text-muted">Protected by Mandatory HR Approval Gate</div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-bold">Active Persona &amp; Security Controls</div>
            <div class="card-body">
                <dl class="row small mb-0">
                    <dt class="col-sm-5 text-muted">Authenticated User</dt>
                    <dd class="col-sm-7 fw-semibold">{{ $user->name }} (<code>{{ $user->email }}</code>)</dd>

                    <dt class="col-sm-5 text-muted">Assigned Role(s)</dt>
                    <dd class="col-sm-7">
                        @foreach($activeUserRoles ?? [] as $rSlug)
                            <span class="badge bg-primary me-1">{{ $rSlug }}</span>
                        @endforeach
                    </dd>

                    <dt class="col-sm-5 text-muted">HR Handover Safety Gate</dt>
                    <dd class="col-sm-7"><span class="badge bg-success">Enforced Server-Side</span></dd>

                    <dt class="col-sm-5 text-muted">AI Kill Switch</dt>
                    <dd class="col-sm-7">
                        @if($tenant?->ai_kill_switch)
                            <span class="badge bg-danger">KILL SWITCH ACTIVE (AI Blocked)</span>
                        @else
                            <span class="badge bg-secondary">Normal Governed Mode</span>
                        @endif
                    </dd>

                    <dt class="col-sm-5 text-muted">Dynamics 365 Live Employee Creation</dt>
                    <dd class="col-sm-7">
                        <span class="badge bg-warning text-dark">Disabled (Dry-Run / Sandbox Only)</span>
                    </dd>
                </dl>
                <hr>
                <div class="small text-muted mb-1 fw-semibold">Granted Permissions in Active Tenant ({{ count($activeUserPermissions ?? []) }}):</div>
                <div class="d-flex flex-wrap gap-1">
                    @forelse($activeUserPermissions ?? [] as $permSlug)
                        <span class="badge bg-light text-dark border">{{ $permSlug }}</span>
                    @empty
                        <span class="text-muted small">Candidate-only / Restricted scope</span>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card stat-card h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold">Recent Tenant Audit Trail (Append-Only)</span>
                @if(in_array('audit.view', $activeUserPermissions ?? []))
                    <a href="{{ route('admin.audit.index') }}" class="btn btn-sm btn-outline-secondary">Full Audit Log</a>
                @endif
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Time (UTC)</th>
                                <th>Module / Action</th>
                                <th>Actor</th>
                                <th>Outcome</th>
                                <th>Correlation ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentAuditEvents as $ev)
                                <tr>
                                    <td class="small text-muted">{{ $ev->created_at?->format('Y-m-d H:i:s') }}</td>
                                    <td>
                                        <span class="badge bg-light text-dark border">{{ $ev->module }}</span>
                                        <span class="small fw-semibold">{{ $ev->action }}</span>
                                    </td>
                                    <td class="small">{{ $ev->actor_identifier }}</td>
                                    <td>
                                        <span class="badge {{ $ev->outcome === 'SUCCESS' ? 'bg-success' : 'bg-danger' }}">
                                            {{ $ev->outcome }}
                                        </span>
                                    </td>
                                    <td><code class="small">{{ $ev->correlation_id }}</code></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3">No audit events recorded yet for this tenant.</td>
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
