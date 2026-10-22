@extends('layouts.app')

@section('title', 'Dynamics 365 Finance HR Connector')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Dynamics 365 HR Connector</li>
            </ol>
        </nav>
        <h1 class="h3 fw-bold mb-1">Microsoft Dynamics 365 Finance HR Connector &amp; Field Ownership</h1>
        <p class="text-muted small mb-0">
            Safe-by-default enterprise connector with explicit field ownership matrix, legal entity mapping, dry-run validation, and mandatory dual-control HR handover gate enforcement.
        </p>
    </div>
    <span class="badge bg-{{ $config->real_employee_creation_enabled ? 'warning text-dark' : 'success' }}">
        {{ $config->last_health_status }}
    </span>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold py-3">Connector Environment &amp; Safety Mode</div>
            <div class="card-body">
                <form method="POST" action="{{ route('dynamics.config.update') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Dynamics 365 Environment URL</label>
                        <input type="url" name="environment_url" class="form-control form-control-sm" value="{{ $config->environment_url }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Execution Mode</label>
                        <select name="connection_mode" class="form-select form-select-sm" required>
                            <option value="alpha_sandbox" {{ $config->connection_mode === 'alpha_sandbox' ? 'selected' : '' }}>Controlled Validation Mode (Staged Worker Provisioning)</option>
                            <option value="dry_run" {{ $config->connection_mode === 'dry_run' ? 'selected' : '' }}>Dry-Run Schema Validation Only</option>
                            <option value="live_configured" {{ $config->connection_mode === 'live_configured' ? 'selected' : '' }}>Live Configured (Fails Closed Without OAuth Secret)</option>
                        </select>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="real_employee_creation_enabled" value="1" id="realEmpSwitch" {{ $config->real_employee_creation_enabled ? 'checked' : '' }}>
                        <label class="form-check-label small" for="realEmpSwitch">Enable Live Dynamics 365 Employee Creation (Requires Verified HR Handover Gate)</label>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Update Dynamics 365 Connector Settings</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold py-3">Canonical Field-Ownership Matrix (URAP vs Dynamics 365 Finance HR)</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Field / Domain Attribute</th>
                            <th>Authoritative Master</th>
                            <th>Sync Direction</th>
                            <th>Mutability &amp; Gate Rule</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($config->field_ownership_matrix ?? [] as $row)
                            <tr>
                                <td><code class="small">{{ $row['field'] }}</code></td>
                                <td class="small fw-semibold">{{ $row['master_system'] }}</td>
                                <td class="small">{{ $row['sync_direction'] }}</td>
                                <td class="small text-muted">{{ $row['mutability'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card stat-card">
    <div class="card-header bg-white fw-semibold py-3">Dry-Run Payload Validation &amp; Synced Worker References</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>HR Validation Case</th>
                    <th>Candidate &amp; Offer</th>
                    <th>Current Gate Status</th>
                    <th>Dry-Run Connector Check</th>
                </tr>
            </thead>
            <tbody>
                @forelse($validationCases as $case)
                    <tr>
                        <td><code>#{{ $case->id }}</code> (Checklist v{{ $case->checklist_version }})</td>
                        <td>
                            <div class="fw-semibold">{{ $case->application?->candidate?->first_name }} {{ $case->application?->candidate?->last_name }}</div>
                            <div class="small text-muted">{{ $case->offerVersion?->job_title }} (v{{ $case->offerVersion?->version_number }})</div>
                        </td>
                        <td>
                            <span class="badge bg-{{ $case->status === 'handed_over' ? 'success' : ($case->status === 'approved_for_handover' ? 'primary' : 'warning text-dark') }}">
                                {{ $case->status }}
                            </span>
                        </td>
                        <td>
                            <form method="POST" action="{{ route('dynamics.dry-run', $case->id) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-dark">Run Dry-Run Payload Validation</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">No HR validation cases available for dry-run inspection yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
