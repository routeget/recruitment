@extends('layouts.app')

@section('title', 'Operational Diagnostics')

@section('content')
<div class="mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-1 small">
            <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item active" aria-current="page">Operational Diagnostics</li>
        </ol>
    </nav>
    <h1 class="h3 fw-bold mb-0">Platform &amp; Tenant Operational Health Diagnostics</h1>
    <p class="text-muted small mb-0">Secret-safe operational readiness checks (never exposes credentials or internal secrets).</p>
</div>

<div class="row g-3 mb-4">
    @foreach($checks as $name => $check)
        <div class="col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="text-uppercase small text-muted fw-semibold">{{ str_replace('_', ' ', $name) }}</div>
                    <div class="mt-2 d-flex align-items-center justify-content-between">
                        <span class="fw-bold">{{ $check['status'] }}</span>
                        <span class="badge {{ $check['healthy'] ? 'bg-success' : 'bg-danger' }}">
                            {{ $check['healthy'] ? 'HEALTHY' : 'ATTENTION' }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="card stat-card">
    <div class="card-header bg-white fw-bold">Runtime &amp; Safety Gate Status</div>
    <div class="card-body">
        <dl class="row mb-0 small">
            <dt class="col-sm-4 text-muted">PHP Runtime</dt>
            <dd class="col-sm-8"><code>PHP {{ $phpVersion }}</code></dd>

            <dt class="col-sm-4 text-muted">Framework Version</dt>
            <dd class="col-sm-8"><code>Laravel {{ $laravelVersion }}</code></dd>

            <dt class="col-sm-4 text-muted">Active Tenant Context</dt>
            <dd class="col-sm-8">{{ $tenant?->name }} (<code>{{ $tenant?->slug }}</code>)</dd>

            <dt class="col-sm-4 text-muted">Tenant Audit Events Recorded</dt>
            <dd class="col-sm-8">{{ $auditEventCount }}</dd>

            <dt class="col-sm-4 text-muted">Mandatory HR Handover Safety Gate</dt>
            <dd class="col-sm-8"><span class="badge bg-success">ENFORCED (Accepted Offer + Completed Checklist + Explicit Approval Required)</span></dd>

            <dt class="col-sm-4 text-muted">JSON Health Endpoint</dt>
            <dd class="col-sm-8"><a href="{{ route('health') }}" target="_blank"><code>GET /health</code></a></dd>
        </dl>
    </div>
</div>
@endsection
