<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Service Notification') — {{ config('app.name', 'Unified Recruitment AI Platform') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="{{ asset('css/enterprise-theme.css') }}" rel="stylesheet">
</head>
<body class="d-flex flex-column min-vh-100" style="background-color: var(--bg-canvas, #F5F5F5); color: var(--text-main, #323130);">
    <header class="py-3 px-4 border-bottom bg-white shadow-xs">
        <div class="container-fluid d-flex justify-content-between align-items-center" style="max-width: 1140px;">
            <a href="{{ url('/') }}" class="d-flex align-items-center gap-2 text-decoration-none fw-bold" style="color: var(--header-dark, #002050);">
                <span class="d-inline-flex align-items-center justify-content-center rounded text-white fw-bold px-2 py-1 small" style="background-color: var(--primary-actions, #0078D4);">
                    TR
                </span>
                <span>{{ config('app.name', 'Unified Recruitment AI Platform') }}</span>
            </a>
            <div class="d-flex align-items-center gap-2">
                <a href="{{ url('/') }}" class="btn btn-sm btn-outline-secondary">Career Portal</a>
                @auth
                    <a href="{{ auth()->user()->isActivePlatformAdmin() ? route('platform-admin.index') : route('dashboard') }}" class="btn btn-sm btn-primary">Workspace</a>
                @else
                    <a href="{{ route('login') }}" class="btn btn-sm btn-primary">Sign In</a>
                @endauth
            </div>
        </div>
    </header>

    <main class="flex-grow-1 d-flex align-items-center justify-content-center py-5 px-3">
        <div class="card stat-card border-0 shadow-sm w-100" style="max-width: 620px;">
            <div class="card-body p-4 p-md-5">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <span class="badge @yield('badge_class', 'bg-secondary') px-3 py-2">
                        HTTP @yield('code', 'Error')
                    </span>
                    <span class="small text-muted">Enterprise Governance &amp; Security</span>
                </div>
                <h1 class="h4 fw-bold mb-2" style="color: var(--text-high-emphasis, #201F1E);">
                    @yield('heading', 'Unable to Complete Request')
                </h1>
                <p class="text-secondary mb-4">
                    @yield('message', 'We encountered an issue processing your request. Please verify your permissions or return to the workspace.')
                </p>

                @hasSection('detail')
                    <div class="alert alert-light border small text-secondary mb-4">
                        @yield('detail')
                    </div>
                @endif

                <div class="d-flex flex-wrap gap-2 pt-2 border-top">
                    @auth
                        <a href="{{ auth()->user()->isActivePlatformAdmin() ? route('platform-admin.index') : route('dashboard') }}" class="btn btn-primary btn-sm px-3">
                            Return to Workspace
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="btn btn-primary btn-sm px-3">
                            Sign In
                        </a>
                    @endauth
                    <a href="{{ url('/') }}" class="btn btn-outline-secondary btn-sm px-3">
                        Explore Open Roles
                    </a>
                </div>
            </div>
        </div>
    </main>

    <footer class="py-3 px-4 text-center small text-muted border-top bg-white">
        &copy; {{ date('Y') }} Tringers &mdash; {{ config('app.name', 'Unified Recruitment AI Platform') }}
    </footer>
</body>
</html>
