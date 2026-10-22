@extends('layouts.app')

@section('title', 'Platform Administration & Configuration Centre')

@section('content')
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4 border-bottom pb-3">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge bg-danger">GLOBAL CONTROL PLANE</span>
            <span class="badge bg-dark">Server-Authorized Platform Super Admin Only</span>
        </div>
        <h1 class="h3 fw-bold mb-1">Platform Administration &amp; Configuration Centre</h1>
        <p class="text-muted small mb-0">
            Centralized governance for platform identity, tenant lifecycle, privileged administrators, Microsoft Entra ID SSO readiness, AI gateway caps, API/MCP/webhook policies, Dynamics 365 safety gates, and append-only configuration audit trails.
            <strong>Distinct from tenant-scoped workspaces; zero candidate PII or private documents are exposed here.</strong>
        </p>
    </div>
    <div class="text-end small">
        <div>Signed-in Super Admin: <strong>{{ auth()->user()->maskedEmail() }}</strong></div>
        <div>Account Type:
            @if(auth()->user()->isSyntheticDemoIdentity())
                <span class="badge bg-warning text-dark">SYNTHETIC DEMO (.test)</span>
            @else
                <span class="badge bg-success">REAL PRODUCTION SUPER ADMIN</span>
            @endif
        </div>
    </div>
</div>

@if(!$superAdminReport['real_superadmin_provisioned'])
    <div class="alert alert-warning border-warning shadow-sm mb-4" role="alert">
        <div class="fw-bold mb-1">Action Required: No Real Production Platform Super Admin Provisioned Yet</div>
        <div class="small">
            Currently, only synthetic development/testing demo accounts (<code>*.test</code>) hold Super Admin flags (`active_real_superadmin_count = 0`).
            To provision a genuine production Platform Super Admin via the audited, interactive CLI flow on the authorized server, run:
            <code class="d-block mt-1 p-2 bg-dark text-light rounded">php artisan platform:admin-provision --email="admin@your-organization.com" --name="Full Name" --operator="ssh-operator" --reason="Initial production Platform Super Admin bootstrap"</code>
        </div>
    </div>
@endif

@if(auth()->user()->must_rotate_password)
    <div class="alert alert-danger shadow-sm mb-4" role="alert">
        <div class="fw-bold">Mandatory Password Rotation Required (`PASSWORD_ROTATION_REQUIRED`)</div>
        <div class="small">
            Your Platform Super Admin account requires a password rotation before executing administrative mutations. Please complete the password rotation form under <a href="{{ route('platform-admin.index', ['tab' => 'security']) }}" class="alert-link">9. Security Policies</a>.
        </div>
    </div>
@endif

<!-- Section Navigation Tabs -->
<nav aria-label="Platform Configuration Sections" class="mb-4">
    <ul class="nav nav-pills flex-wrap gap-1 bg-white p-2 rounded border shadow-sm">
        @php
            $tabs = [
                'overview' => '1. Overview',
                'branding' => '2. Identity & Branding',
                'tenants' => '3. Tenant Lifecycle',
                'admins' => '4. Platform Users & Roles',
                'auth-sso' => '5. Auth & Entra SSO',
                'ai-governance' => '6. AI Governance',
                'api-mcp' => '7. API, MCP & Webhooks',
                'integrations' => '8. External Integrations',
                'security' => '9. Security Policies',
                'audit' => '10. Audit & Change History',
                'readiness' => '11. Operational Readiness',
            ];
        @endphp
        @foreach($tabs as $tabKey => $tabLabel)
            <li class="nav-item">
                <a class="nav-link btn-sm {{ $activeTab === $tabKey ? 'active fw-bold' : 'text-dark' }}"
                   href="{{ route('platform-admin.index', ['tab' => $tabKey]) }}">
                    {{ $tabLabel }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>

<!-- 1. PLATFORM OVERVIEW -->
<section id="section-overview" class="mb-5 {{ $activeTab === 'overview' ? '' : 'd-none' }}" aria-labelledby="heading-overview">
    <h2 id="heading-overview" class="h5 fw-bold mb-3">1. Platform Overview &amp; Governance Posture</h2>
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="p-3 stat-card h-100">
                <div class="text-muted small text-uppercase fw-semibold">Platform Runtime</div>
                <div class="fs-5 fw-bold mt-1">PHP {{ $phpVersion }} / Laravel {{ $laravelVersion }}</div>
                <div class="small text-muted mt-1">Environment: <code>{{ app()->environment() }}</code></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="p-3 stat-card h-100">
                <div class="text-muted small text-uppercase fw-semibold">Real Super Admins</div>
                <div class="fs-4 fw-bold mt-1 {{ $superAdminReport['real_superadmin_provisioned'] ? 'text-success' : 'text-warning' }}">
                    {{ $superAdminReport['active_real_superadmin_count'] }} Real / {{ $superAdminReport['active_demo_superadmin_count'] }} Demo
                </div>
                <div class="small text-muted mt-1">Last-Admin Lockout Protection: <span class="badge bg-success">ACTIVE</span></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="p-3 stat-card h-100">
                <div class="text-muted small text-uppercase fw-semibold">Registered Tenants</div>
                <div class="fs-4 fw-bold mt-1">{{ $tenants->count() }} Total ({{ $tenants->where('status', 'ACTIVE')->count() }} Active)</div>
                <div class="small text-muted mt-1">Isolated via <code>BelongsToTenant</code></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="p-3 stat-card h-100">
                <div class="text-muted small text-uppercase fw-semibold">Safety &amp; Identity Gates</div>
                <div class="mt-1">
                    <span class="badge bg-secondary d-block mb-1">Entra SSO: {{ $entraSsoEnabled ? 'ENABLED' : 'FAIL-CLOSED (503)' }}</span>
                    <span class="badge bg-warning text-dark d-block">Dynamics Live HR: {{ ($allSettings['dynamics.global_live_writes_blocked']['value'] ?? true) ? 'BLOCKED (SAFE)' : 'ALLOWED' }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card stat-card mb-4">
        <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
            <span>Allowlisted Platform Configuration Summary ({{ count($allSettings) }} Typed Settings)</span>
            <span class="badge bg-primary">Arbitrary Key Editing Disabled</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Setting Key</th>
                        <th>Section</th>
                        <th>Classification / Sensitivity</th>
                        <th>Current Value</th>
                        <th>Version</th>
                        <th>Runtime Consumer</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($allSettings as $key => $meta)
                        <tr>
                            <td><code>{{ $key }}</code></td>
                            <td><span class="badge bg-light text-dark border">{{ $meta['section'] }}</span></td>
                            <td>
                                <span class="badge bg-info text-dark">{{ $meta['classification'] }}</span>
                                <span class="badge {{ $meta['sensitivity'] === 'SENSITIVE_GOVERNANCE' ? 'bg-danger' : 'bg-secondary' }}">{{ $meta['sensitivity'] }}</span>
                            </td>
                            <td>
                                @if($meta['type'] === 'boolean')
                                    <span class="badge {{ $meta['value'] ? 'bg-success' : 'bg-secondary' }}">{{ $meta['value'] ? 'TRUE' : 'FALSE' }}</span>
                                @else
                                    <code>{{ (string) $meta['value'] }}</code>
                                @endif
                            </td>
                            <td><code>v{{ $meta['version'] }}</code></td>
                            <td class="small text-muted">{{ $meta['consumer'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- 2. PLATFORM IDENTITY & BRANDING -->
<section id="section-branding" class="mb-5 {{ $activeTab === 'branding' ? '' : 'd-none' }}" aria-labelledby="heading-branding">
    <h2 id="heading-branding" class="h5 fw-bold mb-3">2. Platform Identity, Branding &amp; Global Defaults</h2>
    <div class="card stat-card">
        <div class="card-header bg-white fw-bold">Global Branding &amp; Default Provisioning Parameters (`PLATFORM_DB_SETTING`)</div>
        <div class="card-body">
            <form method="POST" action="{{ route('platform-admin.settings.update', ['section' => 'branding']) }}">
                @csrf
                <div class="row g-3">
                    @foreach($brandingSettings as $key => $meta)
                        <div class="col-md-6">
                            <input type="hidden" name="expected_versions[{{ $key }}]" value="{{ $meta['version'] }}">
                            <label for="setting-{{ str_replace('.', '-', $key) }}" class="form-label fw-semibold small mb-1">
                                {{ $meta['label'] }}
                                <span class="badge bg-light text-dark border ms-1">v{{ $meta['version'] }}</span>
                            </label>
                            @if($meta['type'] === 'enum')
                                <select id="setting-{{ str_replace('.', '-', $key) }}" name="settings[{{ $key }}]" class="form-select form-select-sm">
                                    @foreach($meta['options'] ?? [] as $opt)
                                        <option value="{{ $opt }}" @selected($meta['value'] === $opt)>{{ $opt }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="text"
                                       id="setting-{{ str_replace('.', '-', $key) }}"
                                       name="settings[{{ $key }}]"
                                       value="{{ $meta['value'] }}"
                                       class="form-control form-control-sm"
                                       required>
                            @endif
                            <div class="form-text small">
                                {{ $meta['description'] }} <br>
                                <strong>Runtime Consumer:</strong> <code>{{ $meta['consumer'] }}</code>
                            </div>
                        </div>
                    @endforeach
                    <div class="col-12">
                        <label for="branding-reason" class="form-label fw-semibold small">Audit Change Reason</label>
                        <input type="text" id="branding-reason" name="reason" class="form-control form-control-sm" placeholder="e.g., Updated global support contact and portal headline banner" required minlength="10">
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary">Save Identity &amp; Branding Settings</button>
                        <a href="{{ route('platform-admin.index', ['tab' => 'branding']) }}" class="btn btn-sm btn-outline-secondary">Cancel / Reset</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</section>

<!-- 3. TENANT LIFECYCLE -->
<section id="section-tenants" class="mb-5 {{ $activeTab === 'tenants' ? '' : 'd-none' }}" aria-labelledby="heading-tenants">
    <h2 id="heading-tenants" class="h5 fw-bold mb-3">3. Tenant Lifecycle &amp; Platform-Wide Tenant Controls</h2>
    <div class="alert alert-info small py-2">
        <strong>Privacy Boundary:</strong> Platform Super Admins manage tenant lifecycle, retention floors, and emergency switches here. Candidate records, applications, and private documents are strictly excluded from this global console.
    </div>

    <div class="card stat-card mb-4">
        <div class="card-header bg-white fw-bold">Provision New Enterprise Tenant</div>
        <div class="card-body">
            <form method="POST" action="{{ route('platform-admin.tenants.store') }}" class="row g-3">
                @csrf
                <div class="col-md-3">
                    <label for="new-tenant-name" class="form-label small fw-semibold">Organization Name</label>
                    <input type="text" id="new-tenant-name" name="name" class="form-control form-control-sm" placeholder="Contoso Enterprise Corp" required>
                </div>
                <div class="col-md-2">
                    <label for="new-tenant-slug" class="form-label small fw-semibold">Tenant Slug</label>
                    <input type="text" id="new-tenant-slug" name="slug" class="form-control form-control-sm" placeholder="contoso-corp" required>
                </div>
                <div class="col-md-3">
                    <label for="new-tenant-domain" class="form-label small fw-semibold">Primary Domain (Optional)</label>
                    <input type="text" id="new-tenant-domain" name="primary_domain" class="form-control form-control-sm" placeholder="contoso.career.tringers.com">
                </div>
                <div class="col-md-2">
                    <label for="new-tenant-currency" class="form-label small fw-semibold">Currency</label>
                    <select id="new-tenant-currency" name="default_currency" class="form-select form-select-sm">
                        @foreach(['USD', 'GBP', 'EUR', 'AED', 'INR'] as $curr)
                            <option value="{{ $curr }}">{{ $curr }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="new-tenant-retention" class="form-label small fw-semibold">Retention (Days)</label>
                    <input type="number" id="new-tenant-retention" name="retention_days" class="form-control form-control-sm" value="365" min="{{ $securitySettings['security.min_retention_days']['value'] ?? 180 }}">
                </div>
                <div class="col-md-9">
                    <label for="new-tenant-reason" class="form-label small fw-semibold">Provisioning Justification / Ticket Ref (Min 10 chars)</label>
                    <input type="text" id="new-tenant-reason" name="reason" class="form-control form-control-sm" placeholder="e.g., TKT-2026-800 Enterprise tenant onboarding" required minlength="10">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-sm btn-primary w-100">Create Tenant</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card stat-card">
        <div class="card-header bg-white fw-bold">Registered Tenants &amp; Lifecycle Controls</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ID / Slug</th>
                        <th>Name &amp; Domain</th>
                        <th>Members</th>
                        <th>Lifecycle &amp; Governance Controls</th>
                        <th>Audited Support Context</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($tenants as $t)
                        <tr>
                            <td>
                                <code>#{{ $t->id }}</code> <strong>{{ $t->slug }}</strong>
                                @if($t->is_demo)
                                    <span class="badge bg-warning text-dark">DEMO</span>
                                @endif
                            </td>
                            <td>
                                <div class="fw-semibold">{{ $t->name }}</div>
                                <div class="small text-muted">{{ $t->primary_domain ?? 'No custom domain' }}</div>
                            </td>
                            <td><span class="badge bg-secondary">{{ $t->memberships_count }} members</span></td>
                            <td>
                                <form method="POST" action="{{ route('platform-admin.tenants.lifecycle', $t) }}" class="row g-2 align-items-center">
                                    @csrf
                                    <div class="col-auto">
                                        <select name="status" class="form-select form-select-sm" aria-label="Status for {{ $t->slug }}">
                                            <option value="ACTIVE" @selected($t->status === 'ACTIVE')>ACTIVE</option>
                                            <option value="SUSPENDED" @selected($t->status === 'SUSPENDED')>SUSPENDED</option>
                                        </select>
                                    </div>
                                    <div class="col-auto form-check form-check-inline mb-0">
                                        <input type="checkbox" class="form-check-input" id="ai-kill-{{ $t->id }}" name="ai_kill_switch" value="1" @checked($t->ai_kill_switch)>
                                        <label class="form-check-label small" for="ai-kill-{{ $t->id }}">AI Kill</label>
                                    </div>
                                    <div class="col-auto form-check form-check-inline mb-0">
                                        <input type="checkbox" class="form-check-input" id="mcp-{{ $t->id }}" name="mcp_enabled" value="1" @checked($t->mcp_enabled)>
                                        <label class="form-check-label small" for="mcp-{{ $t->id }}">MCP</label>
                                    </div>
                                    <div class="col-auto form-check form-check-inline mb-0">
                                        <input type="checkbox" class="form-check-input" id="sso-req-{{ $t->id }}" name="sso_required" value="1" @checked($t->sso_required)>
                                        <label class="form-check-label small" for="sso-req-{{ $t->id }}">Require SSO</label>
                                    </div>
                                    <div class="col-auto" style="width: 95px;">
                                        <input type="number" name="retention_days" class="form-control form-control-sm" value="{{ $t->retention_days }}" title="Retention Days">
                                    </div>
                                    <div class="col-auto">
                                        <input type="text" name="reason" class="form-control form-control-sm" placeholder="Reason (min 10 chars)" required minlength="10">
                                    </div>
                                    <div class="col-auto">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Apply</button>
                                    </div>
                                </form>
                            </td>
                            <td>
                                <form method="POST" action="{{ route('admin.iam.support-context') }}" class="d-flex gap-1">
                                    @csrf
                                    <input type="hidden" name="target_tenant_id" value="{{ $t->id }}">
                                    <input type="text" name="support_ticket_reference" class="form-control form-control-sm" placeholder="TKT-101" required minlength="5" style="width: 90px;">
                                    <input type="text" name="justification" class="form-control form-control-sm" placeholder="Support reason" required minlength="10" style="width: 130px;">
                                    <button type="submit" class="btn btn-sm btn-outline-dark">Support Switch</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- 4. PLATFORM USERS & ROLES -->
<section id="section-admins" class="mb-5 {{ $activeTab === 'admins' ? '' : 'd-none' }}" aria-labelledby="heading-admins">
    <h2 id="heading-admins" class="h5 fw-bold mb-3">4. Platform Users, Global Roles &amp; Privileged Access Management</h2>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card stat-card h-100">
                <div class="card-header bg-white fw-bold">Your Effective Platform Authority</div>
                <div class="card-body small">
                    <ul class="mb-0">
                        <li><strong>Account ID / UUID:</strong> <code>#{{ auth()->id() }}</code> (<code>{{ auth()->user()->uuid }}</code>)</li>
                        <li><strong>Global Platform Super Admin (`is_platform_superadmin`):</strong> <span class="badge bg-success">TRUE</span></li>
                        <li><strong>Platform Role (`platform_role`):</strong> <code>{{ auth()->user()->platform_role ?? 'platform_super_admin' }}</code></li>
                        <li><strong>MFA Enabled (`mfa_enabled`):</strong> <span class="badge {{ auth()->user()->mfa_enabled ? 'bg-success' : 'bg-danger' }}">{{ auth()->user()->mfa_enabled ? 'ENABLED' : 'DISABLED' }}</span></li>
                        <li><strong>Separation of Duties:</strong> Tenant-level role memberships (<a href="{{ route('admin.iam.index') }}">Tenants &amp; IAM Access</a>) never grant global Platform Super Admin authority.</li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card stat-card h-100">
                <div class="card-header bg-white fw-bold">Promote Existing Verified User to Platform Super Admin</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('platform-admin.admins.promote') }}" class="row g-2">
                        @csrf
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Organizational Email (Non-.test)</label>
                            <input type="email" name="email" class="form-control form-control-sm" placeholder="operator@company.com" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Full Name</label>
                            <input type="text" name="name" class="form-control form-control-sm" placeholder="Jane Doe" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Justification / Ticket Ref (Min 10 chars)</label>
                            <input type="text" name="reason" class="form-control form-control-sm" placeholder="SEC-2026-44 Authorized promotion" required minlength="10">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Step-Up Current Password Confirmation</label>
                            <input type="password" name="current_password" class="form-control form-control-sm" placeholder="Your current password" required>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-sm btn-primary">Promote to Platform Super Admin</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card stat-card">
        <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
            <span>Provisioned Platform Super Administrators</span>
            <span class="badge bg-info text-dark">Last-Super-Admin Lockout Protection Enforced</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Name &amp; Masked Email</th>
                        <th>Identity Class</th>
                        <th>Status / MFA</th>
                        <th>Granted By / Last Login</th>
                        <th>Revoke / Demote (Requires Step-Up Password)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($superAdminReport['accounts'] as $acct)
                        <tr>
                            <td><code>#{{ $acct['id'] }}</code></td>
                            <td>
                                <div class="fw-semibold">{{ $acct['name'] }}</div>
                                <code>{{ $acct['masked_email'] }}</code>
                            </td>
                            <td>
                                @if($acct['is_synthetic_demo'])
                                    <span class="badge bg-warning text-dark">SYNTHETIC DEMO (.test)</span>
                                @else
                                    <span class="badge bg-success">REAL PRODUCTION ADMIN</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $acct['status'] === 'ACTIVE' ? 'bg-success' : 'bg-danger' }}">{{ $acct['status'] }}</span>
                                <span class="badge {{ $acct['mfa_enabled'] ? 'bg-primary' : 'bg-secondary' }}">MFA: {{ $acct['mfa_enabled'] ? 'ON' : 'OFF' }}</span>
                            </td>
                            <td class="small text-muted">
                                Granted by: <code>{{ $acct['granted_by'] ?? 'seeder/bootstrap' }}</code><br>
                                Last login: <code>{{ $acct['last_login_at'] ?? 'never' }}</code>
                            </td>
                            <td>
                                @if((int) $acct['id'] === (int) auth()->id())
                                    <span class="badge bg-secondary">Current Session (Self-Revoke Prohibited)</span>
                                @else
                                    <form method="POST" action="{{ route('platform-admin.admins.revoke', $acct['id']) }}" class="d-flex gap-1 align-items-center">
                                        @csrf
                                        <input type="text" name="reason" class="form-control form-control-sm" placeholder="Reason (min 10 chars)" required minlength="10" style="width: 140px;">
                                        <input type="password" name="current_password" class="form-control form-control-sm" placeholder="Your password" required style="width: 120px;">
                                        <div class="form-check mb-0">
                                            <input type="checkbox" class="form-check-input" name="suspend_account" value="1" id="susp-{{ $acct['id'] }}">
                                            <label class="form-check-label small" for="susp-{{ $acct['id'] }}">Suspend</label>
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Revoke</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- 5. AUTHENTICATION & IDENTITY PROVIDERS -->
<section id="section-auth-sso" class="mb-5 {{ $activeTab === 'auth-sso' ? '' : 'd-none' }}" aria-labelledby="heading-auth-sso">
    <h2 id="heading-auth-sso" class="h5 fw-bold mb-3">5. Authentication &amp; Microsoft Entra ID Enterprise SSO (`SSO-01`)</h2>

    <div class="alert {{ $entraSsoEnabled ? 'alert-success' : 'alert-secondary' }} border">
        <div class="fw-bold">
            Microsoft Entra ID OIDC + PKCE Status:
            @if($entraSsoEnabled)
                <span class="badge bg-success">ENABLED</span>
            @else
                <span class="badge bg-secondary">DISABLED BY DEFAULT — FAIL-CLOSED (`503 SSO_NOT_CONFIGURED`)</span>
            @endif
        </div>
        <div class="small mt-1">
            Per security governance, <code>MICROSOFT_ENTRA_SSO_ENABLED</code> remains <code>false</code> until valid Microsoft Entra app registration credentials (<code>MICROSOFT_ENTRA_CLIENT_ID</code>, <code>MICROSOFT_ENTRA_CLIENT_SECRET</code>) and a dedicated test tenant are provisioned in the server environment. Client secrets are never stored in generic settings tables or rendered in HTML.
        </div>
    </div>

    <div class="card stat-card mb-4">
        <div class="card-header bg-white fw-bold">Global OIDC / PKCE Parameters (`ENVIRONMENT_MANAGED` / `SECRET_ENV_REFERENCE` — Read-Only)</div>
        <div class="card-body small">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="text-muted">Callback Redirect URI</div>
                    <code>{{ route('auth.entra.callback') }}</code>
                </div>
                <div class="col-md-4">
                    <div class="text-muted">Organizational Audience Mode</div>
                    <code>{{ config('entra_sso.tenant_mode', 'organizations') }}</code> (Consumer MSA <code>9188040d-6c67-4c5b-b112-36a304b66dad</code> Rejected)
                </div>
                <div class="col-md-4">
                    <div class="text-muted">Identity Mapping Invariant</div>
                    <code>tid + oid</code> (No Silent Email Linking; No Entra Group RBAC Elevation)
                </div>
            </div>
        </div>
    </div>

    <div class="card stat-card">
        <div class="card-header bg-white fw-bold">Tenant Microsoft Entra Directory Connections ({{ $ssoConnections->count() }})</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Tenant</th>
                        <th>Display Name</th>
                        <th>Entra Tenant ID (<code>tid</code>)</th>
                        <th>Provisioning Policy</th>
                        <th>Allowed Domains</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($ssoConnections as $conn)
                        <tr>
                            <td><strong>{{ $conn->tenant?->name ?? 'Tenant #'.$conn->tenant_id }}</strong></td>
                            <td>{{ $conn->display_name }}</td>
                            <td><code>{{ $conn->entra_tenant_id }}</code></td>
                            <td><code>{{ $conn->provisioning_policy }}</code></td>
                            <td><code>{{ implode(', ', $conn->allowed_domains ?? []) ?: '*' }}</code></td>
                            <td>
                                <span class="badge {{ $conn->is_enabled ? 'bg-success' : 'bg-secondary' }}">
                                    {{ $conn->is_enabled ? 'CONNECTION_ENABLED' : 'DISABLED' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-muted small py-3 text-center">No tenant Entra directory connections registered yet. Configure tenant connections in <a href="{{ route('admin.iam.index') }}">Tenants &amp; IAM Access</a>.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- 6. AI PROVIDER & USAGE GOVERNANCE -->
<section id="section-ai-governance" class="mb-5 {{ $activeTab === 'ai-governance' ? '' : 'd-none' }}" aria-labelledby="heading-ai-governance">
    <h2 id="heading-ai-governance" class="h5 fw-bold mb-3">6. AI Provider, Model Routing, Usage Limits &amp; Governance</h2>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="p-3 stat-card">
                <div class="text-muted small">Platform-Wide AI Task Runs</div>
                <div class="fs-4 fw-bold">{{ $aiUsageSummary['total_runs'] }}</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="p-3 stat-card">
                <div class="text-muted small">Human-Reviewed AI Runs</div>
                <div class="fs-4 fw-bold text-success">{{ $aiUsageSummary['human_reviewed_runs'] }}</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="p-3 stat-card">
                <div class="text-muted small">Cumulative Tenant Spend (Minor Units)</div>
                <div class="fs-4 fw-bold">{{ $aiUsageSummary['total_spend_minor'] }}</div>
            </div>
        </div>
    </div>

    <div class="card stat-card mb-4">
        <div class="card-header bg-white fw-bold">Global AI Gateway Policy (`PLATFORM_DB_SETTING` — Enforced by `AiGatewayService`)</div>
        <div class="card-body">
            <form method="POST" action="{{ route('platform-admin.settings.update', ['section' => 'ai_governance']) }}" class="row g-3">
                @csrf
                @foreach($aiGovernanceSettings as $key => $meta)
                    <div class="col-md-6">
                        <input type="hidden" name="expected_versions[{{ $key }}]" value="{{ $meta['version'] }}">
                        <label class="form-label fw-semibold small mb-1">
                            {{ $meta['label'] }}
                            <span class="badge bg-light text-dark border ms-1">v{{ $meta['version'] }}</span>
                        </label>
                        @if($meta['type'] === 'boolean')
                            <select name="settings[{{ $key }}]" class="form-select form-select-sm">
                                <option value="1" @selected((bool) $meta['value'] === true)>TRUE (Enabled / Enforced)</option>
                                <option value="0" @selected((bool) $meta['value'] === false)>FALSE (Disabled)</option>
                            </select>
                        @elseif($meta['type'] === 'enum')
                            <select name="settings[{{ $key }}]" class="form-select form-select-sm">
                                @foreach($meta['options'] ?? [] as $opt)
                                    <option value="{{ $opt }}" @selected($meta['value'] === $opt)>{{ $opt }}</option>
                                @endforeach
                            </select>
                        @else
                            <input type="number" name="settings[{{ $key }}]" value="{{ $meta['value'] }}" class="form-control form-control-sm" required>
                        @endif
                        <div class="form-text small">{{ $meta['description'] }}</div>
                    </div>
                @endforeach
                <div class="col-12">
                    <label class="form-label fw-semibold small">Governance Change Justification (Required, min 10 chars)</label>
                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="e.g., Adjusted global AI monthly budget ceiling and verified mandatory PII redaction" required minlength="10">
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">Save Global AI Governance Policy</button>
                    <a href="{{ route('platform-admin.index', ['tab' => 'ai-governance']) }}" class="btn btn-sm btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</section>

<!-- 7. API, MCP & WEBHOOK GOVERNANCE -->
<section id="section-api-mcp" class="mb-5 {{ $activeTab === 'api-mcp' ? '' : 'd-none' }}" aria-labelledby="heading-api-mcp">
    <h2 id="heading-api-mcp" class="h5 fw-bold mb-3">7. API, MCP &amp; Outbound Webhook Governance</h2>

    <div class="card stat-card mb-4">
        <div class="card-header bg-white fw-bold">Global API, MCP &amp; Webhook Controls (`PLATFORM_DB_SETTING`)</div>
        <div class="card-body">
            <form method="POST" action="{{ route('platform-admin.settings.update', ['section' => 'api_mcp']) }}" class="row g-3">
                @csrf
                @foreach($apiMcpSettings as $key => $meta)
                    <div class="col-md-4">
                        <input type="hidden" name="expected_versions[{{ $key }}]" value="{{ $meta['version'] }}">
                        <label class="form-label fw-semibold small mb-1">
                            {{ $meta['label'] }}
                            <span class="badge bg-light text-dark border ms-1">v{{ $meta['version'] }}</span>
                        </label>
                        @if($meta['type'] === 'boolean')
                            <select name="settings[{{ $key }}]" class="form-select form-select-sm">
                                <option value="1" @selected((bool) $meta['value'] === true)>TRUE (Enabled)</option>
                                <option value="0" @selected((bool) $meta['value'] === false)>FALSE (Disabled)</option>
                            </select>
                        @else
                            <input type="number" name="settings[{{ $key }}]" value="{{ $meta['value'] }}" class="form-control form-control-sm" required>
                        @endif
                        <div class="form-text small">{{ $meta['description'] }}</div>
                    </div>
                @endforeach
                <div class="col-12">
                    <label class="form-label fw-semibold small">Governance Change Justification (Required, min 10 chars)</label>
                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="e.g., Updated platform MCP and webhook governance policy" required minlength="10">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-sm btn-primary">Save API, MCP &amp; Webhook Settings</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card stat-card h-100">
                <div class="card-header bg-white fw-bold">Machine API Clients (`ApiClientPrincipal` — Secrets Masked)</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Tenant</th>
                                <th>Client ID &amp; Scopes</th>
                                <th>Status</th>
                                <th>Emergency Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($apiClients as $client)
                                <tr>
                                    <td><code>{{ $tenantMap[$client->tenant_id]->slug ?? '#'.$client->tenant_id }}</code></td>
                                    <td>
                                        <div><code>{{ $client->client_id }}</code> ({{ $client->name }})</div>
                                        <div class="small text-muted">Scopes: <code>{{ implode(', ', $client->scopes ?? []) }}</code></div>
                                    </td>
                                    <td>
                                        <span class="badge {{ $client->is_active ? 'bg-success' : 'bg-danger' }}">
                                            {{ $client->is_active ? 'ACTIVE' : 'REVOKED' }}
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('platform-admin.api-clients.status', $client->id) }}" class="d-flex gap-1">
                                            @csrf
                                            <input type="hidden" name="is_active" value="{{ $client->is_active ? '0' : '1' }}">
                                            <input type="text" name="reason" class="form-control form-control-sm" placeholder="Reason (min 10)" required minlength="10" style="width: 120px;">
                                            <button type="submit" class="btn btn-sm {{ $client->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}">
                                                {{ $client->is_active ? 'Revoke' : 'Enable' }}
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-muted small text-center py-3">No machine API clients registered.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card stat-card h-100">
                <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                    <span>Webhook Subscriptions (SSRF Guarded — Signing Secrets Encrypted)</span>
                    <span class="small text-muted">
                        Pending: {{ $webhookDeliverySummary['pending'] }} |
                        Delivered: {{ $webhookDeliverySummary['delivered'] }} |
                        Failed: {{ $webhookDeliverySummary['failed_retryable'] + $webhookDeliverySummary['failed_permanent'] }}
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Tenant</th>
                                <th>HTTPS Target URL</th>
                                <th>Status</th>
                                <th>Governance Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($webhookSubscriptions as $sub)
                                <tr>
                                    <td><code>{{ $tenantMap[$sub->tenant_id]->slug ?? '#'.$sub->tenant_id }}</code></td>
                                    <td>
                                        <div class="small fw-semibold">{{ $sub->name }}</div>
                                        <code>{{ $sub->target_url }}</code>
                                    </td>
                                    <td>
                                        <span class="badge {{ $sub->is_active ? 'bg-success' : 'bg-secondary' }}">
                                            {{ $sub->is_active ? 'ACTIVE' : 'DISABLED' }}
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('platform-admin.webhooks.status', $sub->id) }}" class="d-flex gap-1">
                                            @csrf
                                            <input type="hidden" name="is_active" value="{{ $sub->is_active ? '0' : '1' }}">
                                            <input type="text" name="reason" class="form-control form-control-sm" placeholder="Reason (min 10)" required minlength="10" style="width: 120px;">
                                            <button type="submit" class="btn btn-sm {{ $sub->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}">
                                                {{ $sub->is_active ? 'Disable' : 'Enable' }}
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-muted small text-center py-3">No webhook subscriptions registered.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 8. EXTERNAL INTEGRATIONS (DYNAMICS 365 HR SAFETY GATE) -->
<section id="section-integrations" class="mb-5 {{ $activeTab === 'integrations' ? '' : 'd-none' }}" aria-labelledby="heading-integrations">
    <h2 id="heading-integrations" class="h5 fw-bold mb-3">8. External Integrations &amp; Dynamics 365 Finance HR Safety Gate (`HRH-01`..`HRH-08`)</h2>

    <div class="card stat-card mb-4">
        <div class="card-header bg-white fw-bold">Platform-Wide Dynamics 365 HR Live-Write Safety Gate (`PLATFORM_DB_SETTING`)</div>
        <div class="card-body">
            <form method="POST" action="{{ route('platform-admin.settings.update', ['section' => 'integrations']) }}" class="row g-3">
                @csrf
                @foreach($integrationsSettings as $key => $meta)
                    <div class="col-md-8">
                        <input type="hidden" name="expected_versions[{{ $key }}]" value="{{ $meta['version'] }}">
                        <label class="form-label fw-semibold small mb-1">
                            {{ $meta['label'] }}
                            <span class="badge bg-light text-dark border ms-1">v{{ $meta['version'] }}</span>
                        </label>
                        <select name="settings[{{ $key }}]" class="form-select form-select-sm">
                            <option value="1" @selected((bool) $meta['value'] === true)>TRUE — Block Live Employee Creation Platform-Wide (Fail-Closed / Sandbox Validation Only)</option>
                            <option value="0" @selected((bool) $meta['value'] === false)>FALSE — Allow Tenant-Level Dynamics Configuration (Still Requires OAuth + HR Validator + HR Approver)</option>
                        </select>
                        <div class="form-text small">{{ $meta['description'] }}</div>
                    </div>
                @endforeach
                <div class="col-md-4">
                    <label class="form-label fw-semibold small">Safety Gate Justification (Min 10 chars)</label>
                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="Reason for Dynamics safety gate update" required minlength="10">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-sm btn-primary">Save Integration Safety Gate Policy</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card stat-card">
        <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
            <span>Tenant Dynamics 365 HR Connector Status</span>
            <span class="badge bg-secondary">Transactional Outbox Pending Events: {{ $outboxPendingCount }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Tenant</th>
                        <th>Environment URL</th>
                        <th>Connection Mode</th>
                        <th>Real Employee Creation</th>
                        <th>Health Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($dynamicsConfigs as $dc)
                        <tr>
                            <td><code>{{ $tenantMap[$dc->tenant_id]->slug ?? '#'.$dc->tenant_id }}</code></td>
                            <td><code>{{ $dc->environment_url }}</code></td>
                            <td><code>{{ $dc->connection_mode }}</code></td>
                            <td>
                                <span class="badge {{ $dc->real_employee_creation_enabled ? 'bg-warning text-dark' : 'bg-secondary' }}">
                                    {{ $dc->real_employee_creation_enabled ? 'ENABLED' : 'DISABLED (SAFE)' }}
                                </span>
                            </td>
                            <td><code>{{ $dc->last_health_status }}</code></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted small text-center py-3">No tenant Dynamics configurations initialized yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- 9. SECURITY POLICIES -->
<section id="section-security" class="mb-5 {{ $activeTab === 'security' ? '' : 'd-none' }}" aria-labelledby="heading-security">
    <h2 id="heading-security" class="h5 fw-bold mb-3">9. Security Policies, Session Controls &amp; Credential Rotation</h2>

    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="card stat-card h-100">
                <div class="card-header bg-white fw-bold">Platform Security &amp; Compliance Policies (`PLATFORM_DB_SETTING`)</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('platform-admin.settings.update', ['section' => 'security']) }}" class="row g-3">
                        @csrf
                        @foreach($securitySettings as $key => $meta)
                            <div class="col-md-6">
                                <input type="hidden" name="expected_versions[{{ $key }}]" value="{{ $meta['version'] }}">
                                <label class="form-label fw-semibold small mb-1">
                                    {{ $meta['label'] }}
                                    <span class="badge bg-light text-dark border ms-1">v{{ $meta['version'] }}</span>
                                </label>
                                @if($meta['type'] === 'boolean')
                                    <select name="settings[{{ $key }}]" class="form-select form-select-sm">
                                        <option value="1" @selected((bool) $meta['value'] === true)>TRUE (Enforced)</option>
                                        <option value="0" @selected((bool) $meta['value'] === false)>FALSE (Disabled)</option>
                                    </select>
                                @else
                                    <input type="number" name="settings[{{ $key }}]" value="{{ $meta['value'] }}" class="form-control form-control-sm" required>
                                @endif
                                <div class="form-text small">{{ $meta['description'] }}</div>
                            </div>
                        @endforeach
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Security Policy Change Reason (Required, min 10 chars)</label>
                            <input type="text" name="reason" class="form-control form-control-sm" placeholder="e.g., Tightened login rate limit and retention floor" required minlength="10">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-sm btn-primary">Save Security Policies</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card stat-card h-100">
                <div class="card-header bg-white fw-bold">Rotate Your Platform Super Admin Password</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('platform-admin.security.rotate-password') }}" class="row g-2">
                        @csrf
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Current Password</label>
                            <input type="password" name="current_password" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">New Strong Password (Min 14 chars, upper/lower/digit/symbol)</label>
                            <input type="password" name="new_password" class="form-control form-control-sm" required minlength="14">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Confirm New Password</label>
                            <input type="password" name="new_password_confirmation" class="form-control form-control-sm" required minlength="14">
                        </div>
                        <div class="col-12 mt-2">
                            <button type="submit" class="btn btn-sm btn-warning fw-semibold">Rotate Password &amp; Regenerate Session</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card stat-card">
        <div class="card-header bg-white fw-bold">Environment-Managed &amp; Secret-Reference Catalogue (Read-Only — Never Written to `.env` by Web UI)</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Configuration Key</th>
                        <th>Classification</th>
                        <th>Sensitivity</th>
                        <th>Effective / Masked State</th>
                        <th>UI Mutability</th>
                        <th>Runtime Consumer</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($envCatalogue as $item)
                        <tr>
                            <td><code>{{ $item['key'] }}</code></td>
                            <td><span class="badge bg-dark">{{ $item['classification'] }}</span></td>
                            <td><span class="badge {{ $item['sensitivity'] === 'CRITICAL_SECRET' ? 'bg-danger' : 'bg-secondary' }}">{{ $item['sensitivity'] }}</span></td>
                            <td><code>{{ $item['effective_state'] }}</code></td>
                            <td class="small text-muted">{{ $item['editable_in_ui'] }}</td>
                            <td class="small text-muted">{{ $item['consumer'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- 10. AUDIT & CHANGE HISTORY -->
<section id="section-audit" class="mb-5 {{ $activeTab === 'audit' ? '' : 'd-none' }}" aria-labelledby="heading-audit">
    <h2 id="heading-audit" class="h5 fw-bold mb-3">10. Append-Only Platform Audit &amp; Configuration Change History</h2>

    <div class="card stat-card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('platform-admin.index') }}" class="row g-2 align-items-end">
                <input type="hidden" name="tab" value="audit">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Module</label>
                    <select name="audit_module" class="form-select form-select-sm">
                        <option value="">All Modules</option>
                        @foreach(['platform_admin', 'security', 'auth', 'sso', 'iam', 'ai_gateway', 'api_platform', 'dynamics_connector'] as $mod)
                            <option value="{{ $mod }}" @selected(request('audit_module') === $mod)>{{ $mod }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Outcome</label>
                    <select name="audit_outcome" class="form-select form-select-sm">
                        <option value="">All Outcomes</option>
                        <option value="SUCCESS" @selected(request('audit_outcome') === 'SUCCESS')>SUCCESS</option>
                        <option value="DENIED" @selected(request('audit_outcome') === 'DENIED')>DENIED</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Search Action / Actor / Correlation ID</label>
                    <input type="text" name="audit_q" value="{{ request('audit_q') }}" class="form-control form-control-sm" placeholder="platform_config, provision, corr-...">
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
                    <a href="{{ route('platform-admin.index', ['tab' => 'audit']) }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card stat-card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Timestamp / Corr ID</th>
                        <th>Actor</th>
                        <th>Module / Action</th>
                        <th>Outcome</th>
                        <th>Target</th>
                        <th>Redacted Metadata (Before / After / Reason)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($auditEvents as $ev)
                        <tr>
                            <td class="small">
                                <div>{{ $ev->created_at?->toIso8601String() }}</div>
                                <code class="text-muted">{{ $ev->correlation_id }}</code>
                            </td>
                            <td class="small">
                                <span class="badge bg-light text-dark border">{{ $ev->actor_type }}</span>
                                <code>{{ $ev->actor_identifier }}</code>
                            </td>
                            <td>
                                <span class="badge bg-secondary">{{ $ev->module }}</span>
                                <code>{{ $ev->action }}</code>
                            </td>
                            <td>
                                <span class="badge {{ $ev->outcome === 'SUCCESS' ? 'bg-success' : 'bg-danger' }}">
                                    {{ $ev->outcome }}
                                </span>
                                @if($ev->error_code)
                                    <div class="small"><code>{{ $ev->error_code }}</code></div>
                                @endif
                            </td>
                            <td class="small">
                                @if($ev->entity_type)
                                    <code>{{ $ev->entity_type }}:{{ $ev->entity_id }}</code>
                                @else
                                    <span class="text-muted">n/a</span>
                                @endif
                            </td>
                            <td class="small">
                                <code class="d-block text-wrap" style="max-width: 420px;">{{ json_encode($ev->metadata, JSON_UNESCAPED_SLASHES) }}</code>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted small py-3">No matching audit events found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($auditEvents->hasPages())
            <div class="card-footer bg-white">
                {{ $auditEvents->links() }}
            </div>
        @endif
    </div>
</section>

<!-- 11. OPERATIONAL READINESS -->
<section id="section-readiness" class="mb-5 {{ $activeTab === 'readiness' ? '' : 'd-none' }}" aria-labelledby="heading-readiness">
    <h2 id="heading-readiness" class="h5 fw-bold mb-3">11. Operational Readiness, Dependencies &amp; Health Indicators</h2>

    <div class="card stat-card mb-4">
        <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
            <span>Live Infrastructure &amp; Security Gate Readiness</span>
            <div class="d-flex gap-2">
                <a href="{{ route('health') }}" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">JSON `/health`</a>
                <a href="{{ route('health.ready') }}" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">JSON `/health/ready`</a>
                <a href="{{ route('system.diagnostics') }}" class="btn btn-sm btn-outline-secondary">Detailed Diagnostics</a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Subsystem / Dependency</th>
                        <th>Health Indicator</th>
                        <th>Operational State</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($readinessChecks as $name => $check)
                        <tr>
                            <td><code>{{ $name }}</code></td>
                            <td>
                                <span class="badge {{ $check['healthy'] ? 'bg-success' : 'bg-warning text-dark' }}">
                                    {{ $check['healthy'] ? 'OPERATIONAL' : 'ACTION_OR_CREDENTIALS_PENDING' }}
                                </span>
                            </td>
                            <td><code>{{ $check['status'] }}</code></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</section>
@endsection
