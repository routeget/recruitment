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
        <h1 class="h3 fw-bold mb-0">Tenant Identity, RBAC &amp; ABAC Governance ({{ $tenant->name }})</h1>
        <p class="text-muted small mb-0">Tenant-scoped membership and directory management. Global platform administration lives in the <a href="{{ route('platform-admin.index') }}">Platform Configuration Centre</a> (Platform Super Admin only).</p>
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
                                @if($role->slug !== 'platform_superadmin')
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

        @if(auth()->user()->isActivePlatformSuperAdmin())
        <div class="card stat-card border-warning">
            <div class="card-header bg-warning bg-opacity-10 fw-bold">
                Platform Superadmin — Audited Support Context
            </div>
            <div class="card-body">
                <p class="small text-muted">
                    Per enterprise security governance, cross-tenant support access requires a ticket reference and justification, logs an immutable security event, and prohibits routine candidate-PII browsing.
                </p>
                <form method="POST" action="{{ route('platform-admin.support-context') }}">
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
                                    <td class="small">
                                        @php
                                            $scopeMode = is_array($m->abac_scope) ? ($m->abac_scope['scope_mode'] ?? 'tenant_scoped') : 'tenant_scoped';
                                        @endphp
                                        <span class="badge bg-light text-dark border">{{ ucwords(str_replace('_', ' ', (string) $scopeMode)) }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card stat-card mb-4">
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

<div class="row g-4 mt-1">
    <div class="col-lg-5">
        <div class="card stat-card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold">Microsoft Entra ID Enterprise SSO (OIDC + PKCE)</span>
                @if(!empty($entraSsoEnabled))
                    <span class="badge bg-success">OIDC Runtime Enabled</span>
                @else
                    <span class="badge bg-secondary">Pending Client Secret</span>
                @endif
            </div>
            <div class="card-body">
                <p class="small text-muted">
                    Bind this recruitment tenant (<strong>{{ $tenant->name }}</strong>) to an organizational Microsoft Entra directory (<code>tid</code>). Durable identity linking enforces <code>tid + oid</code> and blocks silent email takeover.
                </p>
                <form method="POST" action="{{ route('admin.iam.sso.connections.store') }}">
                    @csrf
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Connection Display Name</label>
                        <input type="text" name="display_name" class="form-control form-control-sm" required placeholder="{{ $tenant->name }} Entra Directory">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Microsoft Entra Directory Tenant ID (<code>tid</code> GUID)</label>
                        <input type="text" name="entra_tenant_id" class="form-control form-control-sm" required placeholder="11111111-2222-3333-4444-555555555555">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Allowed Verified Domains (comma-separated)</label>
                        <input type="text" name="allowed_domains" class="form-control form-control-sm" placeholder="acme-demo.test, acme.example.org">
                    </div>
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" name="is_enabled" id="sso_is_enabled" value="1" checked>
                        <label class="form-check-label small" for="sso_is_enabled">Enable Entra SSO Connection</label>
                    </div>
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" name="require_sso_for_tenant" id="require_sso_for_tenant" value="1" {{ $tenant->require_sso ? 'checked' : '' }}>
                        <label class="form-check-label small" for="require_sso_for_tenant">Require Microsoft Entra SSO for Tenant Users</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="allow_emergency_local_login" id="allow_emergency_local_login" value="1" {{ $tenant->allow_local_fallback ? 'checked' : '' }}>
                        <label class="form-check-label small" for="allow_emergency_local_login">Allow Emergency Break-Glass Local Login Fallback</label>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary w-100 fw-semibold">Save Entra Directory Connection</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card stat-card mb-4">
            <div class="card-header bg-white fw-bold">Configured Entra Directory Connections ({{ ($ssoConnections ?? collect())->count() }})</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Display Name</th>
                                <th>Entra Tenant ID (<code>tid</code>)</th>
                                <th>Policy</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($ssoConnections ?? [] as $conn)
                                <tr>
                                    <td class="fw-semibold small">{{ $conn->display_name }}</td>
                                    <td><code class="small">{{ $conn->entra_tenant_id }}</code></td>
                                    <td class="small">
                                        @if($conn->require_sso_for_tenant && !$conn->allow_emergency_local_login)
                                            <span class="badge bg-warning text-dark">Strict SSO Required</span>
                                        @else
                                            <span class="badge bg-light text-dark border">Hybrid / Break-Glass Allowed</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge {{ $conn->is_enabled ? 'bg-success' : 'bg-secondary' }}">
                                            {{ $conn->is_enabled ? 'ENABLED' : 'DISABLED' }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-muted small text-center py-3">No Microsoft Entra ID directory connections configured for this tenant yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card stat-card">
            <div class="card-header bg-white fw-bold">Linked External Identities (<code>tid + oid</code>) ({{ ($externalIdentities ?? collect())->count() }})</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Local User</th>
                                <th>Entra Object ID (<code>oid</code>)</th>
                                <th>Status</th>
                                <th>Last SSO Login</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($externalIdentities ?? [] as $extId)
                                <tr>
                                    <td class="small">
                                        <div class="fw-semibold">{{ $extId->user?->name }}</div>
                                        <code>{{ $extId->user?->email }}</code>
                                    </td>
                                    <td><code class="small">{{ $extId->entra_object_id }}</code></td>
                                    <td>
                                        <span class="badge {{ $extId->status === 'ACTIVE' ? 'bg-success' : 'bg-danger' }}">{{ $extId->status }}</span>
                                    </td>
                                    <td class="small text-muted">{{ $extId->last_authenticated_at?->toIso8601String() ?? 'Never' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-muted small text-center py-3">No linked Microsoft Entra external identities recorded yet.</td>
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
