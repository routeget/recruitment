@extends('layouts.app')

@section('title', 'Sign In — ' . ($platformBranding['display_name'] ?? 'Unified Recruitment AI Platform'))

@section('auth_split')
<div class="container-fluid px-0 flex-grow-1 d-flex flex-column">
    <div class="row g-0 urap-auth-split-row flex-grow-1">
        <!-- Left Brand Panel: QuickBook ERP-inspired col-lg-7 (~58.3%) -->
        <div class="col-lg-7 d-none d-lg-flex urap-auth-brand-pane">
            <div class="urap-auth-brand-content">
                <div class="d-inline-flex align-items-center gap-2 mb-4 px-3 py-2 rounded" style="background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.2);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                    <span class="fw-bold small text-uppercase" style="letter-spacing: 0.06em;">
                        {{ $platformBranding['brand_code'] ?? 'URAP' }} &bull; Enterprise Talent Governance
                    </span>
                </div>

                <h1 class="display-6 fw-bold text-white mb-3">
                    {{ $platformBranding['display_name'] ?? 'Unified Recruitment AI Platform' }}
                </h1>

                <p class="urap-auth-brand-lead mb-4">
                    {{ $platformBranding['career_portal_banner'] ?? 'Governed multi-tenant recruitment, structured candidate evaluation, and auditable HR onboarding handovers.' }}
                </p>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="urap-auth-pillar h-100">
                            <div class="fw-semibold text-white small mb-1">Tenant-Isolated Workspaces</div>
                            <div class="small" style="color: rgba(255, 255, 255, 0.78);">
                                Strict row-level tenant isolation, least-privilege RBAC, and immutable append-only compliance audit trails.
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="urap-auth-pillar h-100">
                            <div class="fw-semibold text-white small mb-1">Mandatory HR Handover Gate</div>
                            <div class="small" style="color: rgba(255, 255, 255, 0.78);">
                                Accepted offers require verified HR checklists and explicit separation-of-duties approval before Dynamics 365 dispatch.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Decorative Bottom SVG Wave (aria-hidden, pointer-events disabled) -->
            <svg class="urap-auth-wave" viewBox="0 0 1440 200" fill="none" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                <path d="M0 96L60 106.7C120 117 240 139 360 138.7C480 139 600 117 720 101.3C840 85 960 75 1080 85.3C1200 96 1320 128 1380 144L1440 160V200H1380C1320 200 1200 200 1080 200C960 200 840 200 720 200C600 200 480 200 360 200C240 200 120 200 60 200H0V96Z" fill="#FFFFFF"/>
                <path d="M0 144L80 133.3C160 123 320 101 480 106.7C640 112 800 144 960 154.7C1120 165 1280 155 1360 149.3L1440 144V200H1360C1280 200 1120 200 960 200C800 200 640 200 480 200C320 200 160 200 80 200H0V144Z" fill="#FFFFFF" fill-opacity="0.55"/>
            </svg>
        </div>

        <!-- Right Authentication Panel: QuickBook ERP-inspired col-lg-5 (~41.7%) -->
        <div class="col-lg-5 urap-auth-form-pane">
            <div class="urap-auth-form-container">
                <!-- Compact Brand Header on Tablet / Mobile (< 992px) -->
                <div class="urap-auth-mobile-banner d-lg-none">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge bg-primary">{{ $platformBranding['brand_code'] ?? 'URAP' }}</span>
                        <span class="fw-bold small">{{ $platformBranding['display_name'] ?? 'Unified Recruitment AI Platform' }}</span>
                    </div>
                    <div class="small" style="color: rgba(255, 255, 255, 0.84);">
                        Tenant-isolated enterprise talent acquisition &amp; governance workspace.
                    </div>
                </div>

                <!-- Form Header -->
                <div class="mb-4">
                    <div class="d-none d-lg-flex align-items-center gap-2 mb-2">
                        <span class="badge bg-primary">{{ $platformBranding['brand_code'] ?? 'URAP' }}</span>
                        <span class="text-muted small fw-semibold">{{ $platformBranding['display_name'] ?? 'Unified Recruitment AI Platform' }}</span>
                    </div>
                    <h2 class="h4 fw-bold mb-1">Sign In</h2>
                    <p class="text-muted small mb-0">Authenticate into your tenant-isolated recruitment workspace.</p>
                </div>

                @if(session('status'))
                    <div class="alert alert-success alert-dismissible fade show py-2 px-3 small" role="alert">
                        {{ session('status') }}
                        <button type="button" class="btn-close p-2" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                @endif

                @if(session('error'))
                    <div class="alert alert-danger alert-dismissible fade show py-2 px-3 small" role="alert">
                        {{ session('error') }}
                        <button type="button" class="btn-close p-2" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                @endif

                @if($errors->any())
                    <div class="alert alert-danger py-2 px-3 small" role="alert">
                        <div class="fw-bold mb-1">Unable to complete sign-in</div>
                        <ul class="mb-0 ps-3">
                            @foreach($errors->all() as $err)
                                <li>{{ $err }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <!-- Microsoft Entra ID Enterprise SSO Action -->
                <div class="mb-3">
                    @if(!empty($entraSsoEnabled))
                        <a href="{{ route('auth.entra.redirect') }}" class="btn btn-outline-dark w-100 fw-semibold d-flex align-items-center justify-content-center gap-2 py-2">
                            <svg width="16" height="16" viewBox="0 0 21 21" aria-hidden="true" focusable="false">
                                <rect x="1" y="1" width="9" height="9" fill="#f25022"/>
                                <rect x="1" y="11" width="9" height="9" fill="#00a4ef"/>
                                <rect x="11" y="1" width="9" height="9" fill="#7fba00"/>
                                <rect x="11" y="11" width="9" height="9" fill="#ffb900"/>
                            </svg>
                            <span>Sign in with Microsoft work or school account</span>
                        </a>
                        <div class="text-muted small text-center mt-1">
                            Enterprise Single Sign-On via Microsoft Entra ID
                        </div>
                    @else
                        <button type="button" class="btn btn-outline-secondary w-100 fw-semibold py-2" disabled aria-disabled="true">
                            Sign in with Microsoft work or school account
                        </button>
                        <div class="alert alert-light border py-2 px-3 mt-2 mb-0 small text-secondary">
                            <strong>Enterprise Single Sign-On:</strong>
                            Microsoft Entra ID SSO is managed by your organization's identity administrator. Please sign in below with your workspace credentials.
                        </div>
                    @endif
                </div>

                <div class="text-center text-muted small my-3">
                    <span>&mdash; or sign in with tenant workspace credentials &mdash;</span>
                </div>

                <!-- Primary Password Authentication Form -->
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

                    <button type="submit" class="btn btn-primary w-100 fw-semibold py-2">Sign In</button>
                </form>

                <!-- Normal-Flow Legal & Operational Footer (Never Overlaps Form on Short Screens) -->
                <div class="mt-4 pt-3 border-top small text-muted">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
                        <span><strong>{{ $platformBranding['display_name'] ?? 'Unified Recruitment AI Platform' }}</strong></span>
                        @if(Route::has('careers.index'))
                            <a href="{{ route('careers.index') }}" class="text-decoration-none">Browse Open Positions</a>
                        @endif
                    </div>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span>Support: <code>{{ $platformBranding['support_email'] ?? 'support@career.tringers.com' }}</code></span>
                        <span>Correlation ID: <code>{{ $correlationId ?? 'n/a' }}</code></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
