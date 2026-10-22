<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', $platformBranding['display_name'] ?? 'Unified Recruitment AI Platform')</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="{{ asset('css/platform-theme.css') }}" rel="stylesheet">
    @include('layouts.partials.branding-styles')
    @yield('head_meta')
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>

    @hasSection('public_career_portal')
        @yield('public_career_portal')
    @else
    <header class="navbar navbar-expand-lg navbar-dark urap-topbar sticky-top px-3">
        <div class="container-fluid px-0">
            <div class="d-flex align-items-center gap-2">
                @auth
                    <button class="urap-topbar-toggle d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu" aria-label="Toggle navigation sidebar">
                        <svg width="18" height="18" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                            <path fill-rule="evenodd" d="M2.5 12a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5zm0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5zm0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5z"/>
                        </svg>
                    </button>
                @endauth

                <a class="navbar-brand fw-bold d-flex align-items-center gap-2 me-2" href="{{ auth()->check() ? (auth()->user()->isActivePlatformAdmin() ? route('platform-admin.index') : route('organization.home')) : (Route::has('careers.index') ? route('careers.index') : route('login')) }}">
                    <span class="urap-brand-mark">{{ $platformBranding['brand_code'] ?? 'URAP' }}</span>
                    <span class="text-truncate">{{ $platformBranding['display_name'] ?? 'Unified Recruitment AI Platform' }}</span>
                </a>
            </div>

            <button class="navbar-toggler border-0 p-1" type="button" data-bs-toggle="collapse" data-bs-target="#topNavbar" aria-controls="topNavbar" aria-expanded="false" aria-label="Toggle top bar actions">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="topNavbar">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    @if(Route::has('careers.index'))
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('careers.*') ? 'active fw-semibold text-white' : '' }}" href="{{ route('careers.index') }}">Public Career Portal</a>
                        </li>
                    @endif
                </ul>

                <div class="d-flex align-items-center gap-2 gap-lg-3 flex-wrap">
                    @auth
                        @if(auth()->user()->isActivePlatformAdmin())
                            <span class="badge bg-danger text-white">GLOBAL PLATFORM ADMINISTRATION</span>
                        @endif
                        @if(isset($activeTenant) && $activeTenant)
                            <div class="d-flex align-items-center gap-2 text-light small">
                                <span class="badge bg-info text-dark">
                                    Tenant: {{ $activeTenant->name }}
                                </span>
                                @if(!empty($activeUserRoles))
                                    <span class="badge bg-secondary">Role: {{ implode(', ', $activeUserRoles) }}</span>
                                @endif
                            </div>
                        @endif

                        <span class="text-light small fw-medium">{{ auth()->user()->name }}</span>

                        <form method="POST" action="{{ route('logout') }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-light py-1 px-2">Sign Out</button>
                        </form>
                    @else
                        @if(!request()->routeIs('login'))
                            <a href="{{ route('login') }}" class="btn btn-sm btn-primary py-1 px-3">Sign In</a>
                        @endif
                    @endauth
                </div>
            </div>
        </div>
    </header>

    @hasSection('auth_split')
        <main id="main-content" class="urap-auth-split-wrapper">
            @yield('auth_split')
        </main>
    @else
        <div class="urap-app-shell">
            @auth
                <aside class="urap-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebarMenu" aria-label="Recruitment Platform Navigation">
                    <div class="offcanvas-header d-lg-none border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-primary">{{ $platformBranding['brand_code'] ?? 'URAP' }}</span>
                            <span class="fw-bold small">{{ $platformBranding['display_name'] ?? 'Recruitment AI' }}</span>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebarMenu" aria-label="Close navigation sidebar"></button>
                    </div>

                    <div class="offcanvas-body d-block p-0">
                        <div class="urap-sidebar-scroll">
                            @if(auth()->user()->isActivePlatformAdmin() && Route::has('platform-admin.index'))
                                @php
                                    $isPlatformGroupActive = request()->routeIs('platform-admin.*');
                                @endphp
                                <button class="urap-nav-group-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#navGroupPlatformAdmin" aria-expanded="true" aria-controls="navGroupPlatformAdmin">
                                    <span>Platform Administration</span>
                                    <svg class="urap-nav-chevron" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                                        <path fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/>
                                    </svg>
                                </button>
                                <div class="collapse show" id="navGroupPlatformAdmin">
                                    <ul class="nav flex-column mb-1">
                                        <li class="nav-item">
                                            <a class="nav-link {{ request()->routeIs('platform-admin.index', 'platform-admin.section') ? 'active' : '' }}" href="{{ route('platform-admin.index') }}">
                                                <svg class="urap-nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 4.754a3.246 3.246 0 1 0 0 6.492 3.246 3.246 0 0 0 0-6.492zM5.754 8a2.246 2.246 0 1 1 4.492 0 2.246 2.246 0 0 1-4.492 0z"/><path d="M9.796 1.343c-.527-1.79-3.065-1.79-3.592 0l-.094.319a.873.873 0 0 1-1.255.52l-.292-.16c-1.64-.892-3.433.902-2.54 2.541l.159.292a.873.873 0 0 1-.52 1.255l-.319.094c-1.79.527-1.79 3.065 0 3.592l.319.094a.873.873 0 0 1 .52 1.255l-.16.292c-.892 1.64.901 3.434 2.541 2.54l.292-.159a.873.873 0 0 1 1.255.52l.094.319c.527 1.79 3.065 1.79 3.592 0l.094-.319a.873.873 0 0 1 1.255-.52l.292.16c1.64.893 3.434-.902 2.54-2.541l-.159-.292a.873.873 0 0 1 .52-1.255l.319-.094c1.79-.527 1.79-3.065 0-3.592l-.319-.094a.873.873 0 0 1-.52-1.255l.16-.292c.893-1.64-.902-3.433-2.541-2.54l-.292.159a.873.873 0 0 1-1.255-.52l-.094-.319z"/></svg>
                                                <span>Platform Configuration Centre</span>
                                            </a>
                                        </li>
                                        @if(auth()->user()->canConfigureCareerPortal() && Route::has('platform-admin.career-portal.index'))
                                            <li class="nav-item">
                                                <a class="nav-link {{ request()->routeIs('platform-admin.career-portal.*') ? 'active' : '' }}" href="{{ route('platform-admin.career-portal.index') }}">
                                                    <svg class="urap-nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8zm7.5-6.923c-.67.204-1.335.82-1.887 1.855A7.97 7.97 0 0 0 5.145 4H7.5V1.077zM4.09 4a9.267 9.267 0 0 1 .64-1.539 6.7 6.7 0 0 1 .597-.933A7.025 7.025 0 0 0 2.255 4H4.09zm-.582 3.5c.03-.877.138-1.718.312-2.5H1.674a6.958 6.958 0 0 0-.656 2.5h2.49zM4.847 5a12.5 12.5 0 0 0-.338 2.5H7.5V5H4.847zM8.5 5v2.5h2.99a12.495 12.495 0 0 0-.337-2.5H8.5zM4.51 8.5a12.5 12.5 0 0 0 .337 2.5H7.5V8.5H4.51zm3.99 0V11h2.653c.187-.765.306-1.608.338-2.5H8.5zM5.145 12c.138.386.295.744.468 1.068.552 1.035 1.218 1.65 1.887 1.855V12H5.145zm.182 2.472a6.696 6.696 0 0 1-.597-.933A9.268 9.268 0 0 1 4.09 12H2.255a7.024 7.024 0 0 0 3.072 2.472zM3.82 11a13.652 13.652 0 0 1-.312-2.5h-2.49c.062.89.291 1.733.656 2.5H3.82zm6.853 3.472A7.024 7.024 0 0 0 13.745 12H11.91a9.27 9.27 0 0 1-.64 1.539 6.688 6.688 0 0 1-.597.933zM8.5 12v2.923c.67-.204 1.335-.82 1.887-1.855.173-.324.33-.682.468-1.068H8.5zm3.68-1h2.146c.365-.767.594-1.61.656-2.5h-2.49a13.65 13.65 0 0 1-.312 2.5zm2.802-3.5a6.959 6.959 0 0 0-.656-2.5H12.18c.174.782.282 1.623.312 2.5h2.49zM11.27 2.461c.247.464.462.98.64 1.539h1.835a7.024 7.024 0 0 0-3.072-2.472c.218.284.418.598.597.933zM10.855 4a7.966 7.966 0 0 0-.468-1.068C9.835 1.897 9.17 1.282 8.5 1.077V4h2.355z"/></svg>
                                                    <span>Career Portal Configuration</span>
                                                </a>
                                            </li>
                                        @endif
                                        <li class="nav-item">
                                            <a class="nav-link urap-nav-sublink {{ request()->route('section') === 'branding' || request('tab') === 'branding' ? 'active' : '' }}" href="{{ route('platform-admin.section', ['section' => 'branding']) }}">
                                                <span>Branding &amp; Theme Tokens</span>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a class="nav-link urap-nav-sublink {{ request()->route('section') === 'tenants' || request('tab') === 'tenants' ? 'active' : '' }}" href="{{ route('platform-admin.section', ['section' => 'tenants']) }}">
                                                <span>Tenant Lifecycle &amp; Quotas</span>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a class="nav-link {{ request()->routeIs('platform-admin.integrations.api') ? 'active' : '' }}" href="{{ route('platform-admin.integrations.api') }}">
                                                <svg class="urap-nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M6 9a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 0 1h-3A.5.5 0 0 1 6 9zM3.854 4.146a.5.5 0 1 0-.708.708L4.793 6.5 3.146 8.146a.5.5 0 1 0 .708.708l2-2a.5.5 0 0 0 0-.708l-2-2z"/><path d="M2 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2H2zm12 1a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1h12z"/></svg>
                                                <span>API Integrations</span>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a class="nav-link {{ request()->routeIs('platform-admin.system.diagnostics') ? 'active' : '' }}" href="{{ route('platform-admin.system.diagnostics') }}">
                                                <svg class="urap-nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/><path d="M5.255 5.786a.237.237 0 0 0 .241.247h.825c.138 0 .248-.113.266-.25.09-.656.54-1.134 1.342-1.134.686 0 1.314.343 1.314 1.168 0 .635-.374.927-.965 1.371-.673.489-1.206 1.06-1.168 1.987l.003.217a.25.25 0 0 0 .25.246h.811a.25.25 0 0 0 .25-.25v-.105c0-.718.273-.927 1.01-1.486.609-.463 1.244-.977 1.244-2.056 0-1.511-1.276-2.241-2.673-2.241-1.267 0-2.655.59-2.75 2.286zm1.557 5.763c0 .533.425.927 1.01.927.609 0 1.028-.394 1.028-.927 0-.552-.42-.94-1.029-.94-.584 0-1.009.388-1.009.94z"/></svg>
                                                <span>System Diagnostics</span>
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            @endif

                            <!-- Group 1: Core Workspace -->
                            <button class="urap-nav-group-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#navGroupCore" aria-expanded="true" aria-controls="navGroupCore">
                                <span>Core Workspace</span>
                                <svg class="urap-nav-chevron" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                                    <path fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/>
                                </svg>
                            </button>
                            <div class="collapse show" id="navGroupCore">
                                <ul class="nav flex-column mb-1">
                                    <li class="nav-item">
                                        <a class="nav-link {{ request()->routeIs('dashboard', 'organization.home') ? 'active' : '' }}" href="{{ route('organization.home') }}">
                                            <svg class="urap-nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8.354 1.146a.5.5 0 0 0-.708 0l-6 6A.5.5 0 0 0 1.5 7.5v7a.5.5 0 0 0 .5.5h4.5a.5.5 0 0 0 .5-.5v-4h2v4a.5.5 0 0 0 .5.5H14a.5.5 0 0 0 .5-.5v-7a.5.5 0 0 0-.146-.354L13 5.793V2.5a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5v1.293L8.354 1.146z"/></svg>
                                            <span>Dashboard &amp; Overview</span>
                                        </a>
                                    </li>
                                    @if(Route::has('organization.index') && in_array('org.manage', $activeUserPermissions ?? []))
                                        <li class="nav-item">
                                            <a class="nav-link {{ request()->routeIs('organization.index', 'organization.legal-entities.*', 'organization.departments.*', 'organization.locations.*') ? 'active' : '' }}" href="{{ route('organization.index') }}">
                                                <svg class="urap-nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M14.763.075A.5.5 0 0 1 15 .5v15a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5V14h-1v1.5a.5.5 0 0 1-.5.5h-9a.5.5 0 0 1-.5-.5V10a.5.5 0 0 1 .342-.474L6 7.64V4.5a.5.5 0 0 1 .276-.447l8-4a.5.5 0 0 1 .487.022z"/></svg>
                                                <span>Organization Setup</span>
                                            </a>
                                        </li>
                                    @endif
                                    @if(Route::has('requisitions.index') && in_array('requisitions.view', $activeUserPermissions ?? []))
                                        <li class="nav-item">
                                            <a class="nav-link {{ request()->routeIs('requisitions.*') ? 'active' : '' }}" href="{{ route('requisitions.index') }}">
                                                <svg class="urap-nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M5 10.5a.5.5 0 0 1 .5-.5h2a.5.5 0 0 1 0 1h-2a.5.5 0 0 1-.5-.5zm0-2a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5zm0-2a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5z"/><path d="M3 0h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2v-1h1v1a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v1H1V2a2 2 0 0 1 2-2z"/></svg>
                                                <span>Workforce Requisitions</span>
                                            </a>
                                        </li>
                                    @endif
                                    @if(Route::has('jobs.index') && in_array('jobs.manage', $activeUserPermissions ?? []))
                                        <li class="nav-item">
                                            <a class="nav-link {{ request()->routeIs('jobs.*') ? 'active' : '' }}" href="{{ route('jobs.index') }}">
                                                <svg class="urap-nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M6.5 1A1.5 1.5 0 0 0 5 2.5V3H1.5A1.5 1.5 0 0 0 0 4.5v8A1.5 1.5 0 0 0 1.5 14h13a1.5 1.5 0 0 0 1.5-1.5v-8A1.5 1.5 0 0 0 14.5 3H11v-.5A1.5 1.5 0 0 0 9.5 1h-3zm0 1h3a.5.5 0 0 1 .5.5V3H6v-.5a.5.5 0 0 1 .5-.5z"/></svg>
                                                <span>Job Descriptions &amp; Publishing</span>
                                            </a>
                                        </li>
                                    @endif
                                </ul>
                            </div>

                            <!-- Group 2: Candidate & Pipeline -->
                            @php
                                $hasPipelineNav = (Route::has('candidates.index') && in_array('candidates.view', $activeUserPermissions ?? []))
                                    || (Route::has('applications.index') && (in_array('applications.manage', $activeUserPermissions ?? []) || in_array('candidates.view', $activeUserPermissions ?? [])))
                                    || (Route::has('evaluations.index') && (in_array('screening.manage', $activeUserPermissions ?? []) || in_array('interviews.manage', $activeUserPermissions ?? []) || in_array('decisions.manage', $activeUserPermissions ?? []) || in_array('candidates.view', $activeUserPermissions ?? [])));
                            @endphp
                            @if($hasPipelineNav)
                                <button class="urap-nav-group-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#navGroupPipeline" aria-expanded="true" aria-controls="navGroupPipeline">
                                    <span>Candidate &amp; Pipeline</span>
                                    <svg class="urap-nav-chevron" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                                        <path fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/>
                                    </svg>
                                </button>
                                <div class="collapse show" id="navGroupPipeline">
                                    <ul class="nav flex-column mb-1">
                                        @if(Route::has('candidates.index') && in_array('candidates.view', $activeUserPermissions ?? []))
                                            <li class="nav-item">
                                                <a class="nav-link {{ request()->routeIs('candidates.*') ? 'active' : '' }}" href="{{ route('candidates.index') }}">
                                                    <svg class="urap-nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M15 14s1 0 1-1-1-4-5-4-5 3-5 4 1 1 1 1h8zm-7.978-1A.261.261 0 0 1 7 12.996c.001-.264.167-1.03.76-1.72C8.312 10.629 9.282 10 11 10c1.717 0 2.687.63 3.24 1.276.593.69.758 1.457.76 1.72l-.008.002a.274.274 0 0 1-.014.002H7.022zM11 7a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/></svg>
                                                    <span>Candidate CRM &amp; Documents</span>
                                                </a>
                                            </li>
                                        @endif
                                        @if(Route::has('applications.index') && (in_array('applications.manage', $activeUserPermissions ?? []) || in_array('candidates.view', $activeUserPermissions ?? [])))
                                            <li class="nav-item">
                                                <a class="nav-link {{ request()->routeIs('applications.*') ? 'active' : '' }}" href="{{ route('applications.index') }}">
                                                    <svg class="urap-nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M1.5 1.5A.5.5 0 0 1 2 1h12a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-.128.334L10 8.692V13.5a.5.5 0 0 1-.342.474l-3 1A.5.5 0 0 1 6 14.5V8.692L1.628 3.834A.5.5 0 0 1 1.5 3.5v-2z"/></svg>
                                                    <span>Applications &amp; Pipeline</span>
                                                </a>
                                            </li>
                                        @endif
                                        @if(Route::has('evaluations.index') && (in_array('screening.manage', $activeUserPermissions ?? []) || in_array('interviews.manage', $activeUserPermissions ?? []) || in_array('decisions.manage', $activeUserPermissions ?? []) || in_array('candidates.view', $activeUserPermissions ?? [])))
                                            <li class="nav-item">
                                                <a class="nav-link {{ request()->routeIs('evaluations.*') ? 'active' : '' }}" href="{{ route('evaluations.index') }}">
                                                    <svg class="urap-nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M10.854 7.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 1 1 .708-.708L7.5 9.793l2.646-2.647a.5.5 0 0 1 .708 0z"/><path d="M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1v-1z"/></svg>
                                                    <span>Screening, Interviews &amp; Decisions</span>
                                                </a>
                                            </li>
                                        @endif
                                    </ul>
                                </div>
                            @endif

                            <!-- Group 3: Offers & HR Handover Gate -->
                            @php
                                $hasOffersHandoverNav = (Route::has('offers.index') && (in_array('offers.manage', $activeUserPermissions ?? []) || in_array('offers.approve', $activeUserPermissions ?? [])))
                                    || (Route::has('hr-handover.index') && (in_array('hr_validation.manage', $activeUserPermissions ?? []) || in_array('hr_handover.approve', $activeUserPermissions ?? []) || in_array('hr_handover.dispatch', $activeUserPermissions ?? []) || in_array('dynamics.admin', $activeUserPermissions ?? [])));
                            @endphp
                            @if($hasOffersHandoverNav)
                                <button class="urap-nav-group-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#navGroupOffersHandover" aria-expanded="true" aria-controls="navGroupOffersHandover">
                                    <span>Offers &amp; HR Handover Gate</span>
                                    <svg class="urap-nav-chevron" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                                        <path fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/>
                                    </svg>
                                </button>
                                <div class="collapse show" id="navGroupOffersHandover">
                                    <ul class="nav flex-column mb-1">
                                        @if(Route::has('offers.index') && (in_array('offers.manage', $activeUserPermissions ?? []) || in_array('offers.approve', $activeUserPermissions ?? [])))
                                            <li class="nav-item">
                                                <a class="nav-link {{ request()->routeIs('offers.*') ? 'active' : '' }}" href="{{ route('offers.index') }}">
                                                    <svg class="urap-nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2h5.5L14 4.5zm-3 0A1.5 1.5 0 0 1 9.5 3V1H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V4.5h-2z"/></svg>
                                                    <span>Offer Management</span>
                                                </a>
                                            </li>
                                        @endif
                                        @if(Route::has('hr-handover.index') && (in_array('hr_validation.manage', $activeUserPermissions ?? []) || in_array('hr_handover.approve', $activeUserPermissions ?? []) || in_array('hr_handover.dispatch', $activeUserPermissions ?? []) || in_array('dynamics.admin', $activeUserPermissions ?? [])))
                                            <li class="nav-item">
                                                <a class="nav-link {{ request()->routeIs('hr-handover.*') ? 'active' : '' }}" href="{{ route('hr-handover.index') }}">
                                                    <svg class="urap-nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2zm3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/></svg>
                                                    <span>HR Validation &amp; Handover Gate</span>
                                                </a>
                                            </li>
                                        @endif
                                    </ul>
                                </div>
                            @endif

                            <!-- Group 4: AI, Integrations & Governance -->
                            @php
                                $hasGovernanceNav = (Route::has('ai.index') && (in_array('ai.use', $activeUserPermissions ?? []) || in_array('ai.admin', $activeUserPermissions ?? [])))
                                    || (Route::has('integrations.api.index') && in_array('api.admin', $activeUserPermissions ?? []))
                                    || (Route::has('dynamics.index') && in_array('dynamics.admin', $activeUserPermissions ?? []))
                                    || (Route::has('analytics.index') && in_array('reports.view', $activeUserPermissions ?? []))
                                    || in_array('iam.manage', $activeUserPermissions ?? [])
                                    || in_array('audit.view', $activeUserPermissions ?? []);
                            @endphp
                            @if($hasGovernanceNav)
                                <button class="urap-nav-group-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#navGroupGovernance" aria-expanded="true" aria-controls="navGroupGovernance">
                                    <span>AI, Integrations &amp; Governance</span>
                                    <svg class="urap-nav-chevron" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                                        <path fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/>
                                    </svg>
                                </button>
                                <div class="collapse show" id="navGroupGovernance">
                                    <ul class="nav flex-column mb-1">
                                        @if(Route::has('ai.index') && (in_array('ai.use', $activeUserPermissions ?? []) || in_array('ai.admin', $activeUserPermissions ?? [])))
                                            <li class="nav-item">
                                                <a class="nav-link {{ request()->routeIs('ai.*') ? 'active' : '' }}" href="{{ route('ai.index') }}">
                                                    <svg class="urap-nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M6 12.5a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 0 1h-3a.5.5 0 0 1-.5-.5ZM3 8.062C3 6.76 4.235 5.765 5.53 5.886a26.58 26.58 0 0 0 4.94 0C11.765 5.765 13 6.76 13 8.062v1.157a.933.933 0 0 1-.765.935c-.845.147-2.34.346-4.235.346-1.895 0-3.39-.2-4.235-.346A.933.933 0 0 1 3 9.219V8.062Z"/></svg>
                                                    <span>AI Gateway &amp; Governance</span>
                                                </a>
                                            </li>
                                        @endif
                                        @if(Route::has('integrations.api.index') && in_array('api.admin', $activeUserPermissions ?? []))
                                            <li class="nav-item">
                                                <a class="nav-link {{ request()->routeIs('integrations.api.*') ? 'active' : '' }}" href="{{ route('integrations.api.index') }}">
                                                    <svg class="urap-nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M10.478 1.647a.5.5 0 1 0-.956-.294l-4 13a.5.5 0 0 0 .956.294l4-13zM4.854 4.146a.5.5 0 0 1 0 .708L1.707 8l3.147 3.146a.5.5 0 0 1-.708.708l-3.5-3.5a.5.5 0 0 1 0-.708l3.5-3.5a.5.5 0 0 1 .708 0zm6.292 0a.5.5 0 0 0 0 .708L14.293 8l-3.147 3.146a.5.5 0 0 0 .708.708l3.5-3.5a.5.5 0 0 0 0-.708l-3.5-3.5a.5.5 0 0 0-.708 0z"/></svg>
                                                    <span>API Platform, Webhooks &amp; MCP</span>
                                                </a>
                                            </li>
                                        @endif
                                        @if(Route::has('dynamics.index') && in_array('dynamics.admin', $activeUserPermissions ?? []))
                                            <li class="nav-item">
                                                <a class="nav-link {{ request()->routeIs('dynamics.*') ? 'active' : '' }}" href="{{ route('dynamics.index') }}">
                                                    <svg class="urap-nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M9.405 1.05c-.413-1.4-2.397-1.4-2.81 0l-.1.34a1.464 1.464 0 0 1-2.105.872l-.31-.17c-1.283-.698-2.686.705-1.987 1.987l.169.311c.446.82.023 1.841-.872 2.105l-.34.1c-1.4.413-1.4 2.397 0 2.81l.34.1a1.464 1.464 0 0 1 .872 2.105l-.17.31c-.698 1.283.705 2.686 1.987 1.987l.311-.169a1.464 1.464 0 0 1 2.105.872l.1.34c.413 1.4 2.397 1.4 2.81 0l.1-.34a1.464 1.464 0 0 1 2.105-.872l.31.17c1.283.698 2.686-.705 1.987-1.987l-.169-.311a1.464 1.464 0 0 1 .872-2.105l.34-.1c1.4-.413 1.4-2.397 0-2.81l-.34-.1a1.464 1.464 0 0 1-.872-2.105l.17-.31c.698-1.283-.705-2.686-1.987-1.987l-.311.169a1.464 1.464 0 0 1-2.105-.872l-.1-.34z"/></svg>
                                                    <span>Dynamics 365 HR Connector</span>
                                                </a>
                                            </li>
                                        @endif
                                        @if(Route::has('analytics.index') && in_array('reports.view', $activeUserPermissions ?? []))
                                            <li class="nav-item">
                                                <a class="nav-link {{ request()->routeIs('analytics.*') ? 'active' : '' }}" href="{{ route('analytics.index') }}">
                                                    <svg class="urap-nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M4 11H2v3h2v-3zm5-4H7v7h2V7zm5-5v12h-2V2h2zm-2-1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h2a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1h-2z"/></svg>
                                                    <span>Analytics &amp; Funnel Reports</span>
                                                </a>
                                            </li>
                                        @endif
                                        @if(in_array('iam.manage', $activeUserPermissions ?? []))
                                            <li class="nav-item">
                                                <a class="nav-link {{ request()->routeIs('admin.iam.*') ? 'active' : '' }}" href="{{ route('admin.iam.index') }}">
                                                    <svg class="urap-nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M5.338 1.59a61.44 61.44 0 0 0-2.837.856.481.481 0 0 0-.328.39c-.554 4.157.726 7.19 2.253 9.188a10.725 10.725 0 0 0 2.287 2.233c.346.244.652.42.893.533.12.057.218.095.293.118a.55.55 0 0 0 .101.025.615.615 0 0 0 .1-.025c.076-.023.174-.061.294-.118.24-.113.547-.29.893-.533a10.726 10.726 0 0 0 2.287-2.233c1.527-1.997 2.807-5.031 2.253-9.188a.48.48 0 0 0-.328-.39c-.651-.213-1.75-.56-2.837-.855C9.552 1.29 8.531 1.067 8 1.067c-.53 0-1.552.223-2.662.524z"/></svg>
                                                    <span>Tenants &amp; IAM Access (Tenant Scope)</span>
                                                </a>
                                            </li>
                                        @endif
                                        @if(in_array('audit.view', $activeUserPermissions ?? []))
                                            <li class="nav-item">
                                                <a class="nav-link {{ request()->routeIs('admin.audit.*') ? 'active' : '' }}" href="{{ route('admin.audit.index') }}">
                                                    <svg class="urap-nav-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M14.5 3a.5.5 0 0 1 .5.5v9a.5.5 0 0 1-.5.5h-13a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h13zm-13-1A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h13a1.5 1.5 0 0 0 1.5-1.5v-9A1.5 1.5 0 0 0 14.5 2h-13z"/><path d="M5 8a.5.5 0 0 1 .5-.5h7a.5.5 0 0 1 0 1h-7A.5.5 0 0 1 5 8zm0-2.5a.5.5 0 0 1 .5-.5h7a.5.5 0 0 1 0 1h-7a.5.5 0 0 1-.5-.5zm0 5a.5.5 0 0 1 .5-.5h7a.5.5 0 0 1 0 1h-7a.5.5 0 0 1-.5-.5z"/></svg>
                                                    <span>Security &amp; Audit Trail (Tenant Scope)</span>
                                                </a>
                                            </li>
                                        @endif
                                    </ul>
                                </div>
                            @endif
                        </div>
                    </div>
                </aside>
            @endauth

            <main id="main-content" class="urap-main-content">
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
                        <div class="fw-bold mb-1">Please review and correct the highlighted fields</div>
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

        <footer class="border-top bg-white py-2 px-4 mt-auto small text-muted">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <strong>{{ $platformBranding['display_name'] ?? 'Unified Recruitment AI Platform' }}</strong> &mdash;
                    <span class="badge badge-gate">HR Safety Gate: Offer Acceptance Never Auto-Creates Dynamics Employee</span>
                    &mdash; Support: <code>{{ $platformBranding['support_email'] ?? 'support@career.tringers.com' }}</code>
                </div>
                <div>
                    Correlation ID: <code>{{ $correlationId ?? 'n/a' }}</code>
                </div>
            </div>
        </footer>
    @endif
    @endif

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
