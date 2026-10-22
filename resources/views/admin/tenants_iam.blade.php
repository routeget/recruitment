@extends('layouts.app')

@section('title', 'Tenants & IAM Access — ' . $tenant->name)

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Tenants &amp; IAM Access</li>
            </ol>
        </nav>
        <h1 class="h3 fw-bold mb-0">Tenant Identity, RBAC &amp; ABAC Governance</h1>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card stat-card mb-4">
            <div class="card-header bg-white fw-bold">Assign User &amp; Persona in {{ $tenant->name }}</div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.iam.members.store') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="name" class="form-label fw-semibold">Full Name</label>
                        <input type="text" id="name" name="name" class="form-control" required maxlength="150">
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold">Work Email</label>
                        <input type="email" id="email" name="email" class="form-control" required maxlength="190">
                    </div>
                    <div class="mb-3">
                        <label for="role_slug" class="form-label fw-semibold">Role / Persona</label>
                        <select id="role_slug" name="role_slug" class="form-select" required>
                            @foreach($roles as $role)
                                @if($role->slug !== 'platform_superadmin' || auth()->user()->is_platform_superadmin)
                                    <option value="{{ $role->slug }}">{{ $role->name }} ({{ $role->slug }})</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label fw-semibold">Initial Password (Optional — defaults if existing)</label>
                        <input type="password" id="password" name="password" class="form-control" minlength="10" placeholder="Minimum 10 characters">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Provision Membership</button>
                </form>
            </div>
        </div>

        @if(auth()->user()->is_platform_superadmin)
        <div class="card stat-card border-warning">
            <div class="card-header bg-warning bg-opacity-10 fw-bold">
                Platform Superadmin — Audited Support Context
            </div>
            <div class="card-body">
                <p class="small text-muted">
                    Per Specification §§4–5, cross-tenant support access requires a ticket reference and justification, logs an immutable security event, and prohibits routine candidate-PII browsing.
                </p>
                <form method="POST" action="{{ route('admin.iam.support-context') }}">
                    @csrf
                    <div class="mb-2">
                        <label for="target_tenant_id" class="form-label small fw-semibold">Target Tenant</label>
                        <select id="target_tenant_id" name="target_tenant_id" class="form-select form-select-sm" required>
                            @foreach($allTenants as $t)
                                <option value="{{ $t->id }}">{{ $t->name }} ({{ $t->slug }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label for="support_ticket_reference" class="form-label small fw-semibold">Support Ticket Reference</label>
                        <input type="text" id="support_ticket_reference" name="support_ticket_reference" class="form-control form-control-sm" required placeholder="SUP-2026-1042">
                    </div>
                    <div class="mb-3">
                        <label for="justification" class="form-label small fw-semibold">Audited Reason / Justification</label>
                        <textarea id="justification" name="justification" class="form-control form-control-sm" rows="2" required placeholder="Investigating Dynamics connector configuration health..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-sm btn-warning w-100 fw-semibold">Activate Audited Support Access</button>
                </form>
            </div>
        </div>
        @endif
    </div>

    <div class="col-lg-7">
        <div class="card stat-card mb-4">
            <div class="card-header bg-white fw-bold">Active Tenant Memberships ({{ $memberships->count() }})</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>ABAC Scope</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($memberships as $m)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $m->user?->name }}</div>
                                        <code class="small">{{ $m->user?->email }}</code>
                                    </td>
                                    <td><span class="badge bg-primary">{{ $m->role?->slug }}</span></td>
                                    <td><span class="badge bg-success">{{ $m->status }}</span></td>
                                    <td class="small text-muted">
                                        <code>{{ json_encode($m->abac_scope) }}</code>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card stat-card">
            <div class="card-header bg-white fw-bold">Persona &amp; Permission Matrix</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Role</th>
                                <th>Description</th>
                                <th>Permissions Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($roles as $role)
                                <tr>
                                    <td><code>{{ $role->slug }}</code></td>
                                    <td class="small">{{ $role->description }}</td>
                                    <td><span class="badge bg-secondary">{{ $role->permissions->count() }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
