@php
    $theme = $portalConfig['brand_theme'] ?? [];
    $headerNav = $portalConfig['header_navigation'] ?? [];
    $navItems = collect($headerNav['nav_items'] ?? [])
        ->filter(fn ($item) => !empty($item['visible']))
        ->sortBy('order')
        ->values();
    $ctaButton = $headerNav['cta_button'] ?? [
        'label' => 'Join Our Talent Network',
        'url' => '#talent-network',
        'visible' => true,
        'target_blank' => false,
    ];
    $portalHomeUrl = isset($scopeTenant) && $scopeTenant
        ? route('tenant.careers.index', ['tenantSlug' => $scopeTenant->slug])
        : route('careers.index');
@endphp
<style>
    :root {
        --cp-primary: {{ $theme['primary_color'] ?? '#1D63ED' }};
        --cp-secondary: {{ $theme['secondary_color'] ?? '#0F172A' }};
        --cp-heading: {{ $theme['heading_color'] ?? '#0F172A' }};
        --cp-body: {{ $theme['body_color'] ?? '#334155' }};
        --cp-muted: {{ $theme['muted_color'] ?? '#475569' }};
        --cp-surface: {{ $theme['surface_color'] ?? '#FFFFFF' }};
        --cp-surface-alt: {{ $theme['surface_alt_color'] ?? '#F8FAFC' }};
        --cp-border: {{ $theme['border_color'] ?? '#E2E8F0' }};
        --cp-success: {{ $theme['success_color'] ?? '#15803D' }};
        --cp-warning: {{ $theme['warning_color'] ?? '#B45309' }};
        --cp-error: {{ $theme['error_color'] ?? '#B91C1C' }};
        --cp-font-family: '{{ $theme['font_family'] ?? 'Inter' }}', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        --cp-max-width: {{ (int) ($theme['content_max_width'] ?? 1280) }}px;
        --cp-header-height: {{ (int) ($theme['header_height'] ?? 76) }}px;
        --cp-hero-height: {{ (int) ($theme['hero_height'] ?? 560) }}px;
        --cp-hero-overlay: {{ (float) ($theme['hero_overlay_opacity'] ?? 0.58) }};
        --cp-radius: {{ (int) ($theme['border_radius'] ?? 12) }}px;
        --cp-section-spacing: {{ (int) ($theme['section_spacing'] ?? 80) }}px;
    }
</style>

@if(!empty($isPreview))
    <div class="cp-preview-banner" role="region" aria-label="Draft Preview Banner">
        <div class="cp-container d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <span class="badge bg-warning text-dark me-2">DRAFT PREVIEW MODE (NON-PUBLIC)</span>
                <span>You are viewing an unpublished draft preview (`noindex, nofollow`, `Cache-Control: no-store`). Public visitors continue to see the active published configuration.</span>
                @if(isset($scopeTenant) && $scopeTenant)
                    <span class="badge bg-info text-dark ms-2">Tenant Preview: {{ $scopeTenant->name }} ({{ $scopeTenant->slug }})</span>
                @endif
            </div>
            @auth
                @if(auth()->user()->canConfigureCareerPortal())
                    <a href="{{ route('platform-admin.career-portal.index', array_filter(['tenant_id' => $scopeTenant->id ?? null])) }}" class="btn btn-sm btn-warning fw-semibold">
                        Return to Configuration Editor
                    </a>
                @endif
            @endauth
        </div>
    </div>
@endif

<header class="cp-header {{ ($theme['sticky_header'] ?? true) ? 'cp-header-sticky' : '' }} navbar navbar-expand-lg">
    <div class="cp-container d-flex align-items-center justify-content-between w-100">
        <div class="d-flex align-items-center gap-3">
            <a href="{{ $portalHomeUrl }}" class="cp-brand" aria-label="{{ $headerNav['brand_text'] ?? 'Tringers' }} Careers Home">
                <span class="cp-brand-emblem" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="12 2 2 7 12 12 22 7 12 2"/>
                        <polyline points="2 17 12 22 22 17"/>
                        <polyline points="2 12 12 17 22 12"/>
                    </svg>
                </span>
                <span>{{ $headerNav['brand_text'] ?? 'Tringers' }}</span>
            </a>
            @if(isset($scopeTenant) && $scopeTenant)
                <span class="badge rounded-pill bg-light text-dark border px-3 py-1 fw-medium">
                    {{ $scopeTenant->name }}
                </span>
            @endif
        </div>

        <button class="navbar-toggler border-0 p-2 d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#cpNavbarMenu" aria-controls="cpNavbarMenu" aria-expanded="false" aria-label="Toggle career portal navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <nav class="collapse navbar-collapse justify-content-end" id="cpNavbarMenu" aria-label="Primary Career Portal Navigation">
            <ul class="cp-nav-list me-lg-4 my-3 my-lg-0 flex-column flex-lg-row align-items-start align-items-lg-center">
                @foreach($navItems as $item)
                    <li>
                        <a href="{{ $item['url'] ?? '#' }}"
                           class="cp-nav-link {{ ($loop->first && request()->routeIs('careers.index', 'careers.legacy-index', 'tenant.careers.index')) ? 'active' : '' }}"
                           @if(!empty($item['target_blank'])) target="_blank" rel="noopener noreferrer" @endif>
                            {{ $item['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="d-flex align-items-center gap-2 flex-wrap">
                @if(!empty($ctaButton['visible']))
                    <a href="{{ $ctaButton['url'] ?? '#talent-network' }}"
                       class="cp-btn-primary"
                       @if(!empty($ctaButton['target_blank'])) target="_blank" rel="noopener noreferrer" @endif>
                        {{ $ctaButton['label'] ?? 'Join Our Talent Network' }}
                    </a>
                @endif

                @auth
                    <a href="{{ auth()->user()->isActivePlatformAdmin() ? route('platform-admin.index') : route('organization.home') }}" class="cp-btn-outline">
                        Workspace
                    </a>
                @else
                    <a href="{{ route('login') }}" class="cp-btn-outline">
                        Sign In
                    </a>
                @endauth
            </div>
        </nav>
    </div>
</header>

@if(session('status'))
    <div class="cp-container mt-3">
        <div class="alert alert-success alert-dismissible fade show mb-0" role="alert">
            {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    </div>
@endif

@if(session('error'))
    <div class="cp-container mt-3">
        <div class="alert alert-danger alert-dismissible fade show mb-0" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    </div>
@endif
