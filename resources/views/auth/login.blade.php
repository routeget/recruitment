@extends('layouts.app')

@section('title', 'Sign In — Unified Recruitment AI Platform')

@section('content')
<div class="row justify-content-center mt-3">
    <div class="col-md-5 col-lg-4">
        <div class="card stat-card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 fw-bold mb-1">Sign In</h1>
                <p class="text-muted small mb-3">Authenticate into your tenant-isolated recruitment workspace.</p>

                <div class="mb-3">
                    @if(!empty($entraSsoEnabled))
                        <a href="{{ route('auth.entra.redirect') }}" class="btn btn-outline-dark w-100 fw-semibold d-flex align-items-center justify-content-center gap-2 py-2">
                            <span>Sign in with Microsoft work or school account</span>
                        </a>
                        <div class="text-muted small text-center mt-1">
                            Microsoft Entra ID OIDC + PKCE (<code>{{ $entraTenantMode ?? 'organizations' }}</code> audience)
                        </div>
                    @else
                        <button type="button" class="btn btn-outline-secondary w-100 fw-semibold py-2" disabled aria-disabled="true">
                            Sign in with Microsoft work or school account
                        </button>
                        <div class="alert alert-secondary py-2 px-3 mt-2 mb-0 small">
                            <strong>Microsoft Entra ID SSO (Disabled / Unconfigured):</strong>
                            Organizational OIDC + PKCE S256 (`organizations` audience) is implemented and enforced, but external Entra app registration credentials (<code>MICROSOFT_ENTRA_CLIENT_ID</code>) are not enabled in this environment.
                        </div>
                    @endif
                </div>

                <div class="text-center text-muted small my-3">
                    <span>— or sign in with tenant workspace credentials —</span>
                </div>

                <form method="POST" action="{{ route('login.submit') }}" id="loginForm">
                    @csrf
                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold">Work Email Address</label>
                        <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email') }}" required autofocus autocomplete="username">
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label fw-semibold">Password</label>
                        <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror" required autocomplete="current-password">
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="remember" id="remember">
                        <label class="form-check-label small" for="remember">Keep session active</label>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 fw-semibold">Authenticate</button>
                </form>
            </div>
        </div>
    </div>

    @if(count($demoPersonas) > 0)
    <div class="col-md-7 col-lg-6 mt-4 mt-md-0">
        <div class="card stat-card border-info">
            <div class="card-header bg-info bg-opacity-10 border-info d-flex justify-content-between align-items-center">
                <span class="fw-bold">Development Demo Personas (Synthetic Non-Production Data)</span>
                <span class="badge bg-info text-dark">DEMO ONLY</span>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-3">
                    Select any synthetic persona below to populate credentials (<code>AlphaDemo#2026!</code>) and test RBAC, ABAC, and strict cross-tenant isolation between <strong>Acme Global Tech (Demo)</strong> and <strong>Globex Healthcare Systems (Demo)</strong>.
                </p>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Persona / User</th>
                                <th>Tenant</th>
                                <th>Role</th>
                                <th>Quick Fill</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($demoPersonas as $dp)
                                <tr>
                                    <td>
                                        <div class="fw-semibold small">{{ $dp->name }}</div>
                                        <code class="small">{{ $dp->email }}</code>
                                    </td>
                                    <td><span class="badge bg-light text-dark border">{{ $dp->currentTenant?->name }}</span></td>
                                    <td><span class="badge bg-secondary">{{ $dp->memberships->first()?->role?->slug }}</span></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                            onclick="document.getElementById('email').value='{{ $dp->email }}'; document.getElementById('password').value='AlphaDemo#2026!';">
                                            Fill
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
@endsection
