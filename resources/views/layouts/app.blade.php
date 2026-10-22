<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', $platformBranding['display_name'] ?? 'Unified Recruitment AI Platform')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <style>
        :root {
            --urap-sidebar-bg: #0f172a;
            --urap-sidebar-hover: #1e293b;
            --urap-accent: #2563eb;
        }
        body {
            background-color: #f8fafc;
            color: #0f172a;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .skip-link {
            position: absolute;
            top: -48px;
            left: 8px;
            background: #1d4ed8;
            color: #fff;
            padding: 8px 16px;
            z-index: 2000;
            border-radius: 4px;
            text-decoration: none;
        }
        .skip-link:focus {
            top: 8px;
        }
        .urap-sidebar {
            background-color: var(--urap-sidebar-bg);
            color: #e2e8f0;
            min-height: calc(100vh - 56px);
        }
        .urap-sidebar .nav-link {
            color: #cbd5e1;
            border-radius: 6px;
            padding: 0.5rem 0.75rem;
            margin-bottom: 0.2rem;
            font-size: 0.92rem;
        }
        .urap-sidebar .nav-link:hover,
        .urap-sidebar .nav-link.active {
            background-color: var(--urap-sidebar-hover);
            color: #ffffff;
            font-weight: 600;
        }
        .urap-sidebar .nav-section-label {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #94a3b8;
            margin-top: 1rem;
            margin-bottom: 0.35rem;
            padding-left: 0.75rem;
        }
        .stat-card {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #ffffff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }
        .badge-gate {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #f59e0b;
        }
    </style>
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <header class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom border-secondary sticky-top px-3">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="{{ auth()->check() ? (auth()->user()->isActivePlatformAdmin() ? route('platform-admin.index') : route('organization.home')) : (Route::has('careers.index') ? route('careers.index') : route('login')) }}">
                <span class="badge bg-primary">{{ $platformBranding['brand_code'] ?? 'URAP' }}</span>
                <span>{{ $platformBranding['display_name'] ?? 'Unified Recruitment AI Platform' }}</span>
                <span class="badge bg-warning text-dark ms-1">Alpha</span>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topNavbar" aria-controls="topNavbar" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="topNavbar">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    @if(Route::has('careers.index'))
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('careers.index') }}">Public Career Portal</a>
                        </li>
                    @endif
                </ul>

                <div class="d-flex align-items-center gap-3 flex-wrap">
                    @auth
                        @if(auth()->user()->isActivePlatformAdmin())
                            <span class="badge bg-danger text-white">GLOBAL PLATFORM ADMINISTRATION</span>
                        @endif
                        @if(isset($activeTenant) && $activeTenant)
                            <div class="d-flex align-items-center gap-2 text-light small">
                                <span class="badge bg-info text-dark">
                                    Tenant: {{ $activeTenant->name }}
                                    @if($activeTenant->is_demo)
                                        (DEMO)
                                    @endif
                                </span>
                                @if(!empty($activeUserRoles))
                                    <span class="badge bg-secondary">Role: {{ implode(', ', $activeUserRoles) }}</span>
                                @endif
                            </div>
                        @endif

                        <span class="text-light small">{{ auth()->user()->name }}</span>

                        <form method="POST" action="{{ route('logout') }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-light">Sign Out</button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="btn btn-sm btn-primary">Sign In</a>
                    @endauth
                </div>
            </div>
        </div>
    </header>

    <div class="container-fluid flex-grow-1">
        <div class="row">
            @auth
                <nav class="col-md-3 col-lg-2 d-md-block urap-sidebar py-3 collapse" id="sidebarMenu" aria-label="Recruitment Platform Navigation">
                    <div class="position-sticky">
                        @if(auth()->user()->isActivePlatformAdmin() && Route::has('platform-admin.index'))
                            <div class="nav-section-label text-warning">Platform Administration</div>
                            <ul class="nav flex-column mb-2">
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('platform-admin.index', 'platform-admin.section') ? 'active' : '' }}" href="{{ route('platform-admin.index') }}">
                                        Platform Configuration Centre
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('platform-admin.integrations.api') ? 'active' : '' }}" href="{{ route('platform-admin.integrations.api') }}">
                                        API Integrations
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('platform-admin.system.diagnostics') ? 'active' : '' }}" href="{{ route('platform-admin.system.diagnostics') }}">
                                        System Diagnostics
                                    </a>
                                </li>
                            </ul>
                        @endif

                        <div class="nav-section-label">Core Workspace</div>
                        <ul class="nav flex-column">
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs('dashboard', 'organization.home') ? 'active' : '' }}" href="{{ route('organization.home') }}">
                                    Dashboard &amp; Overview
                                </a>
                            </li>
                            @if(Route::has('organization.index') && in_array('org.manage', $activeUserPermissions ?? []))
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('organization.*') ? 'active' : '' }}" href="{{ route('organization.index') }}">
                                        Organization Setup
                                    </a>
                                </li>
                            @endif
                            @if(Route::has('requisitions.index') && in_array('requisitions.view', $activeUserPermissions ?? []))
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('requisitions.*') ? 'active' : '' }}" href="{{ route('requisitions.index') }}">
                                        Workforce Requisitions
                                    </a>
                                </li>
                            @endif
                            @if(Route::has('jobs.index') && in_array('jobs.manage', $activeUserPermissions ?? []))
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('jobs.*') ? 'active' : '' }}" href="{{ route('jobs.index') }}">
                                        Job Descriptions &amp; Publishing
                                    </a>
                                </li>
                            @endif
                        </ul>

                        <div class="nav-section-label">Candidate &amp; Pipeline</div>
                        <ul class="nav flex-column">
                            @if(Route::has('candidates.index') && in_array('candidates.view', $activeUserPermissions ?? []))
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('candidates.*') ? 'active' : '' }}" href="{{ route('candidates.index') }}">
                                        Candidate CRM &amp; Documents
                                    </a>
                                </li>
                            @endif
                            @if(Route::has('applications.index') && (in_array('applications.manage', $activeUserPermissions ?? []) || in_array('candidates.view', $activeUserPermissions ?? [])))
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('applications.*') ? 'active' : '' }}" href="{{ route('applications.index') }}">
                                        Applications &amp; Pipeline
                                    </a>
                                </li>
                            @endif
                            @if(Route::has('evaluations.index') && (in_array('screening.manage', $activeUserPermissions ?? []) || in_array('interviews.manage', $activeUserPermissions ?? []) || in_array('decisions.manage', $activeUserPermissions ?? [])))
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('evaluations.*') ? 'active' : '' }}" href="{{ route('evaluations.index') }}">
                                        Screening, Interviews &amp; Decisions
                                    </a>
                                </li>
                            @endif
                        </ul>

                        <div class="nav-section-label">Offers &amp; HR Handover Gate</div>
                        <ul class="nav flex-column">
                            @if(Route::has('offers.index') && (in_array('offers.manage', $activeUserPermissions ?? []) || in_array('offers.approve', $activeUserPermissions ?? []) || in_array('requisitions.view', $activeUserPermissions ?? [])))
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('offers.*') ? 'active' : '' }}" href="{{ route('offers.index') }}">
                                        Offer Management
                                    </a>
                                </li>
                            @endif
                            @if(Route::has('hr-handover.index') && (in_array('hr_validation.manage', $activeUserPermissions ?? []) || in_array('hr_handover.approve', $activeUserPermissions ?? []) || in_array('hr_handover.dispatch', $activeUserPermissions ?? []) || in_array('requisitions.view', $activeUserPermissions ?? [])))
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('hr-handover.*') ? 'active' : '' }}" href="{{ route('hr-handover.index') }}">
                                        HR Validation &amp; Handover Gate
                                    </a>
                                </li>
                            @endif
                        </ul>

                        <div class="nav-section-label">AI, Integrations &amp; Governance</div>
                        <ul class="nav flex-column">
                            @if(Route::has('ai.index') && (in_array('ai.use', $activeUserPermissions ?? []) || in_array('ai.admin', $activeUserPermissions ?? [])))
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('ai.*') ? 'active' : '' }}" href="{{ route('ai.index') }}">
                                        AI Gateway &amp; Governance
                                    </a>
                                </li>
                            @endif
                            @if(Route::has('integrations.api.index') && (in_array('api.admin', $activeUserPermissions ?? []) || in_array('mcp.use', $activeUserPermissions ?? [])))
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('integrations.api.*') ? 'active' : '' }}" href="{{ route('integrations.api.index') }}">
                                        API Platform, Webhooks &amp; MCP
                                    </a>
                                </li>
                            @endif
                            @if(Route::has('dynamics.index') && (in_array('dynamics.admin', $activeUserPermissions ?? []) || in_array('hr_handover.dispatch', $activeUserPermissions ?? [])))
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('dynamics.*') ? 'active' : '' }}" href="{{ route('dynamics.index') }}">
                                        Dynamics 365 HR Connector
                                    </a>
                                </li>
                            @endif
                            @if(Route::has('analytics.index') && in_array('reports.view', $activeUserPermissions ?? []))
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('analytics.*') ? 'active' : '' }}" href="{{ route('analytics.index') }}">
                                        Analytics &amp; Funnel Reports
                                    </a>
                                </li>
                            @endif
                            @if(in_array('iam.manage', $activeUserPermissions ?? []))
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('admin.iam.*') ? 'active' : '' }}" href="{{ route('admin.iam.index') }}">
                                        Tenants &amp; IAM Access (Tenant Scope)
                                    </a>
                                </li>
                            @endif
                            @if(in_array('audit.view', $activeUserPermissions ?? []))
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('admin.audit.*') ? 'active' : '' }}" href="{{ route('admin.audit.index') }}">
                                        Security &amp; Audit Trail (Tenant Scope)
                                    </a>
                                </li>
                            @endif
                        </ul>
                    </div>
                </nav>
            @endauth

            <main id="main-content" class="{{ auth()->check() ? 'col-md-9 ms-sm-auto col-lg-10 px-md-4' : 'col-12 px-4' }} py-4">
                @if(session('status'))
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        {{ session('status') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                @endif

                @if(session('error'))
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        {{ session('error') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                @endif

                @if($errors->any())
                    <div class="alert alert-danger" role="alert">
                        <div class="fw-bold mb-1">Validation Error (`VALIDATION_ERROR`)</div>
                        <ul class="mb-0">
                            @foreach($errors->all() as $err)
                                <li>{{ $err }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>

    <footer class="border-top bg-white py-2 px-4 mt-auto small text-muted">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <strong>{{ $platformBranding['display_name'] ?? 'Unified Recruitment AI Platform' }} (Alpha)</strong> &mdash;
                <span class="badge badge-gate">HR Safety Gate: Offer Acceptance Never Auto-Creates Dynamics Employee</span>
                &mdash; Support: <code>{{ $platformBranding['support_email'] ?? 'support@career.tringers.com' }}</code>
            </div>
            <div>
                Correlation ID: <code>{{ $correlationId ?? 'n/a' }}</code>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
