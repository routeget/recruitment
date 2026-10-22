@extends('layouts.app')

@php
    $seo = $portalConfig['seo'] ?? [];
    $hero = $portalConfig['hero_search'] ?? [];
    $valueProps = $portalConfig['value_propositions'] ?? [];
    $catSection = $portalConfig['job_categories'] ?? [];
    $featuredSection = $portalConfig['featured_jobs'] ?? [];
    $lifeSection = $portalConfig['life_at_tringers'] ?? [];
    $storiesSection = $portalConfig['employee_stories'] ?? [];
    $finalCta = $portalConfig['final_cta'] ?? [];
    $sectionOrder = $portalConfig['overview']['section_order'] ?? $portalConfig['section_order'] ?? [
        'value_propositions',
        'explore_opportunities',
        'featured_jobs',
        'life_at_tringers',
        'employee_stories',
        'final_cta',
    ];
    $sectionVisibility = $portalConfig['overview']['section_visibility'] ?? [];
    $portalActionUrl = isset($scopeTenant) && $scopeTenant
        ? route('tenant.careers.index', ['tenantSlug' => $scopeTenant->slug])
        : route('careers.index');
    $hasActiveFilters = collect($filters ?? [])->filter(fn ($v, $k) => !in_array($k, ['sort', 'tenant'], true) && !empty($v))->isNotEmpty();
    $pageTitle = isset($scopeTenant) && $scopeTenant
        ? str_replace('{tenant_name}', $scopeTenant->name, (string) ($seo['tenant_title_template'] ?? ($seo['default_title'] ?? '{tenant_name} Careers | Tringers')))
        : ($seo['default_title'] ?? $seo['page_title'] ?? 'Careers at Tringers | Grow Your Career With Tringers');
@endphp

@section('title', $pageTitle)

@section('head_meta')
    <link href="{{ asset('css/career-portal.css') }}" rel="stylesheet">
    <link rel="canonical" href="{{ $canonicalUrl ?? url()->current() }}">
    <meta name="description" content="{{ $seo['default_description'] ?? $seo['meta_description'] ?? 'Discover opportunities where innovation, purpose and people come together to create lasting impact at Tringers.' }}">
    <meta name="robots" content="{{ $robotsDirective ?? 'index, follow' }}">
    <meta property="og:title" content="{{ $seo['og_title'] ?? $pageTitle }}">
    <meta property="og:description" content="{{ $seo['og_description'] ?? ($seo['default_description'] ?? $seo['meta_description'] ?? '') }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $canonicalUrl ?? url()->current() }}">
    <meta property="og:image" content="{{ asset(ltrim((string) ($seo['og_image_url'] ?? $seo['og_image'] ?? '/images/career-portal/hero-workplace.jpg'), '/')) }}">
    @if(($seo['enable_organization_jsonld'] ?? $seo['structured_data_enabled'] ?? true) && empty($isPreview))
        <script type="application/ld+json">
        {!! json_encode([
            '@@context' => 'https://schema.org',
            '@@type' => 'Organization',
            'name' => isset($scopeTenant) && $scopeTenant ? $scopeTenant->name : 'Tringers',
            'url' => $canonicalUrl ?? url('/'),
            'logo' => asset('favicon.ico'),
            'description' => $seo['default_description'] ?? $seo['meta_description'] ?? 'Grow Your Career With Tringers.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
        </script>
    @endif
@endsection

@section('public_career_portal')
<div class="cp-portal-body">
    @include('careers.partials.header')

    <main id="main-content">
        <!-- 4.2 Hero & Job Search -->
        @if(($sectionVisibility['hero'] ?? true) && ($hero['enabled'] ?? true))
            @php
                $heroBg = asset(ltrim((string) ($hero['desktop_image_url'] ?? $hero['background_image'] ?? '/images/career-portal/hero-workplace.jpg'), '/'));
                $chips = collect($hero['renderable_popular_searches'] ?? $hero['popular_searches'] ?? $hero['popular_chips'] ?? [])->filter(fn ($c) => !empty($c['visible']))->sortBy('order')->values();
                $chipBehavior = $hero['popular_searches_mode'] ?? $hero['chip_behavior'] ?? 'execute';
            @endphp
            <section class="cp-hero" style="background-image: url('{{ $heroBg }}');" aria-label="Career Portal Hero and Job Search">
                <div class="cp-container">
                    <div class="cp-hero-inner {{ ($hero['text_alignment'] ?? $portalConfig['brand_theme']['hero_text_alignment'] ?? 'left') === 'center' ? 'cp-hero-center' : '' }}">
                        <span class="cp-eyebrow">{{ $hero['eyebrow'] ?? 'BUILD A BRIGHTER TOMORROW' }}</span>
                        <h1 class="cp-hero-title">
                            {{ $hero['headline'] ?? (isset($scopeTenant) && $scopeTenant ? 'Grow Your Career With ' . $scopeTenant->name : 'Grow Your Career With Tringers') }}
                        </h1>
                        <p class="cp-hero-subtitle">
                            {{ $hero['supporting_text'] ?? 'Be part of a global team solving real-world problems with technology, innovation and purpose.' }}
                        </p>

                        <form method="GET" action="{{ $portalActionUrl }}#open-positions" role="search" aria-label="Search open jobs">
                            @if(!empty($isPreview) && request()->filled('preview_token'))
                                <input type="hidden" name="preview_token" value="{{ request('preview_token') }}">
                                @if(request()->filled('preview_config_id'))
                                    <input type="hidden" name="preview_config_id" value="{{ request('preview_config_id') }}">
                                @endif
                            @endif
                            <div class="cp-search-bar">
                                <div class="cp-search-input-wrap">
                                    <svg class="cp-search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <circle cx="11" cy="11" r="8"/>
                                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                                    </svg>
                                    <label for="cp-hero-search-q" class="visually-hidden">
                                        {{ $hero['search_placeholder'] ?? 'Search jobs by keyword, role or location' }}
                                    </label>
                                    <input type="search"
                                           id="cp-hero-search-q"
                                           name="q"
                                           value="{{ $filters['q'] ?? '' }}"
                                           class="cp-search-input"
                                           maxlength="120"
                                           placeholder="{{ $hero['search_placeholder'] ?? 'Search jobs by keyword, role or location' }}">
                                </div>
                                <button type="submit" class="cp-btn-primary px-4">
                                    {{ $hero['search_button_label'] ?? 'Search Jobs' }}
                                </button>
                            </div>
                        </form>

                        @if($chips->isNotEmpty())
                            <div class="cp-popular-row" aria-label="Popular job searches">
                                <span class="cp-popular-label">{{ $hero['popular_searches_label'] ?? $hero['popular_label'] ?? 'Popular Searches:' }}</span>
                                @foreach($chips as $chip)
                                    @php
                                        $filterType = $chip['type'] ?? $chip['filter_type'] ?? 'q';
                                        $chipQuery = (string) ($chip['query'] ?? $chip['label'] ?? '');
                                        $chipParams = match ($filterType) {
                                            'category' => ['category' => $chipQuery],
                                            'work_mode' => ['work_mode' => strtoupper($chipQuery)],
                                            'location' => ['q' => $chipQuery],
                                            default => ['q' => $chipQuery],
                                        };
                                        if (!empty($isPreview) && request()->filled('preview_token')) {
                                            $chipParams['preview_token'] = request('preview_token');
                                            if (request()->filled('preview_config_id')) {
                                                $chipParams['preview_config_id'] = request('preview_config_id');
                                            }
                                        }
                                        $chipHref = $portalActionUrl . '?' . http_build_query($chipParams) . '#open-positions';
                                    @endphp
                                    <a href="{{ $chipHref }}"
                                       class="cp-chip"
                                       @if($chipBehavior === 'prefill')
                                           onclick="const inp=document.getElementById('cp-hero-search-q'); if(inp){inp.value={{ Js::from($chipQuery) }}; inp.focus(); return false;}"
                                       @endif>
                                        {{ $chip['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </section>
        @endif

        <!-- Ordered Configurable Sections -->
        @foreach($sectionOrder as $sectionKey)
            @if($sectionKey === 'value_propositions' && ($sectionVisibility['value_propositions'] ?? true) && ($valueProps['enabled'] ?? true))
                @php
                    $vpItems = collect($valueProps['renderable_items'] ?? $valueProps['items'] ?? [])->filter(fn ($i) => !empty($i['visible']))->sortBy('order')->values();
                @endphp
                @if($vpItems->isNotEmpty())
                    <section class="cp-section" id="about-tringers" aria-labelledby="cp-vp-heading">
                        <div class="cp-container">
                            <h2 id="cp-vp-heading" class="visually-hidden">{{ $valueProps['section_heading'] ?? $valueProps['section_title'] ?? 'Why Build Your Career With Tringers' }}</h2>
                            <div class="cp-value-grid">
                                @foreach($vpItems as $vp)
                                    <article class="cp-value-card">
                                        <div class="cp-value-icon" aria-hidden="true">
                                            @include('careers.partials.icon', ['icon' => $vp['icon'] ?? 'sparkle', 'size' => 24])
                                        </div>
                                        <h3 class="cp-value-title">{{ $vp['title'] }}</h3>
                                        <p class="cp-value-desc">{{ $vp['description'] }}</p>
                                    </article>
                                @endforeach
                            </div>
                        </div>
                    </section>
                @endif

            @elseif(in_array($sectionKey, ['job_categories', 'explore_opportunities'], true) && ($sectionVisibility['explore_opportunities'] ?? true) && ($catSection['enabled'] ?? true) && !empty($categoriesWithCounts))
                <section class="cp-section cp-section-alt" id="explore-categories" aria-labelledby="cp-cat-heading">
                    <div class="cp-container">
                        <div class="cp-section-header">
                            <span class="cp-eyebrow cp-eyebrow-light">CAREER PATHWAYS</span>
                            <h2 id="cp-cat-heading" class="cp-section-title">{{ $catSection['heading'] ?? 'Explore Opportunities' }}</h2>
                            <p class="cp-section-subtitle">{{ $catSection['subtitle'] ?? 'Find the right team where your skills and ambition can thrive.' }}</p>
                        </div>

                        <div class="cp-category-grid">
                            @foreach($categoriesWithCounts as $cat)
                                @php
                                    $catParams = ['category' => $cat['slug']];
                                    if (!empty($isPreview) && request()->filled('preview_token')) {
                                        $catParams['preview_token'] = request('preview_token');
                                        if (request()->filled('preview_config_id')) {
                                            $catParams['preview_config_id'] = request('preview_config_id');
                                        }
                                    }
                                    $catUrl = $portalActionUrl . '?' . http_build_query($catParams) . '#open-positions';
                                    $isActiveCat = ($filters['category'] ?? '') === $cat['slug'];
                                @endphp
                                <a href="{{ $catUrl }}" class="cp-category-card {{ $isActiveCat ? 'active' : '' }}">
                                    <div class="cp-category-left">
                                        <span class="cp-category-tile" style="background-color: {{ $cat['bg_tint'] ?? '#EFF6FF' }}; color: {{ $cat['icon_color'] ?? '#1D63ED' }};" aria-hidden="true">
                                            @include('careers.partials.icon', ['icon' => $cat['icon'] ?? 'briefcase', 'size' => 24])
                                        </span>
                                        <div>
                                            <h3 class="cp-category-name">{{ $cat['name'] }}</h3>
                                            <p class="cp-category-count">
                                                {{ (int) ($cat['open_count'] ?? 0) }} {{ ((int) ($cat['open_count'] ?? 0)) === 1 ? 'Opening' : 'Openings' }}
                                            </p>
                                        </div>
                                    </div>
                                    <span class="cp-category-arrow" aria-hidden="true">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="9 18 15 12 9 6"/>
                                        </svg>
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </section>

            @elseif($sectionKey === 'featured_jobs' && ($featuredSection['enabled'] ?? true))
                <section class="cp-section" id="open-positions" aria-labelledby="cp-jobs-heading">
                    <div class="cp-container">
                        <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
                            <div>
                                <span class="cp-eyebrow cp-eyebrow-light">OPEN ROLES</span>
                                <h2 id="cp-jobs-heading" class="cp-section-title">
                                    {{ $hasActiveFilters ? 'Filtered Job Opportunities' : ($featuredSection['heading'] ?? 'Featured Jobs') }}
                                </h2>
                                <p class="cp-section-subtitle">
                                    {{ $featuredSection['subtitle'] ?? (isset($scopeTenant) && $scopeTenant ? 'Explore Open Positions at ' . $scopeTenant->name : 'Explore Approved Open Positions') }}
                                </p>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                @if($hasActiveFilters)
                                    <a href="{{ $portalActionUrl }}#open-positions" class="cp-btn-outline">
                                        Clear All Filters
                                    </a>
                                @else
                                    <a href="{{ $portalActionUrl }}?sort=newest#all-open-roles" class="cp-btn-outline">
                                        {{ $featuredSection['view_all_label'] ?? 'View All Jobs' }} &rarr;
                                    </a>
                                @endif
                            </div>
                        </div>

                        <!-- Filter Controls Bar -->
                        <div class="card border shadow-xs mb-4" style="border-radius: var(--cp-radius); border-color: var(--cp-border) !important;">
                            <div class="card-body p-3">
                                <form method="GET" action="{{ $portalActionUrl }}#open-positions" class="row g-2 align-items-end" aria-label="Filter open positions">
                                    @if(!empty($isPreview) && request()->filled('preview_token'))
                                        <input type="hidden" name="preview_token" value="{{ request('preview_token') }}">
                                        @if(request()->filled('preview_config_id'))
                                            <input type="hidden" name="preview_config_id" value="{{ request('preview_config_id') }}">
                                        @endif
                                    @endif
                                    <div class="col-md-3">
                                        <label for="filter-q" class="form-label small fw-semibold mb-1">Keyword</label>
                                        <input type="text" id="filter-q" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm" placeholder="Title, code, skill, city...">
                                    </div>
                                    <div class="col-md-2">
                                        <label for="filter-category" class="form-label small fw-semibold mb-1">Category</label>
                                        <select id="filter-category" name="category" class="form-select form-select-sm">
                                            <option value="">All Categories</option>
                                            @foreach($categoriesWithCounts ?? [] as $catOption)
                                                <option value="{{ $catOption['slug'] }}" @selected(($filters['category'] ?? '') === $catOption['slug'])>
                                                    {{ $catOption['name'] }} ({{ $catOption['open_count'] }})
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    @if(!isset($scopeTenant) || !$scopeTenant)
                                        <div class="col-md-2">
                                            <label for="filter-tenant" class="form-label small fw-semibold mb-1">Organization</label>
                                            <select id="filter-tenant" name="tenant" class="form-select form-select-sm">
                                                <option value="">All Organizations</option>
                                                @foreach($tenants as $t)
                                                    <option value="{{ $t->slug }}" @selected(($filters['tenant'] ?? '') === $t->slug)>{{ $t->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @endif
                                    <div class="col-md-2">
                                        <label for="filter-work-mode" class="form-label small fw-semibold mb-1">Work Mode</label>
                                        <select id="filter-work-mode" name="work_mode" class="form-select form-select-sm">
                                            <option value="">All Modes</option>
                                            @foreach($workModes as $mode)
                                                <option value="{{ $mode }}" @selected(($filters['work_mode'] ?? '') === $mode)>{{ $mode }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label for="filter-employment-type" class="form-label small fw-semibold mb-1">Employment</label>
                                        <select id="filter-employment-type" name="employment_type" class="form-select form-select-sm">
                                            <option value="">All Types</option>
                                            @foreach($employmentTypes as $empType)
                                                <option value="{{ $empType }}" @selected(($filters['employment_type'] ?? '') === $empType)>{{ $empType }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-1 d-grid">
                                        <button type="submit" class="btn btn-sm btn-primary fw-semibold" style="background-color: var(--cp-primary); border-color: var(--cp-primary); min-height: 32px;">
                                            Filter
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        @php
                            $displayJobs = $hasActiveFilters ? $jobs->getCollection() : ($featuredJobs->isNotEmpty() ? $featuredJobs : $jobs->getCollection());
                            $catNameMap = collect($categoriesWithCounts ?? [])->pluck('name', 'slug')->all();
                            $catIconMap = collect($categoriesWithCounts ?? [])->pluck('icon', 'slug')->all();
                        @endphp

                        @if($displayJobs->isEmpty())
                            <div class="card border p-5 text-center" style="border-radius: var(--cp-radius); border-color: var(--cp-border) !important;" role="status">
                                <h3 class="h5 fw-bold mb-2">No Matching Open Positions Found</h3>
                                <p class="text-secondary mb-3">
                                    {{ $featuredSection['empty_state_text'] ?? 'No matching open positions are currently published. Try broadening your search filters or exploring all categories.' }}
                                </p>
                                @if($hasActiveFilters)
                                    <div>
                                        <a href="{{ $portalActionUrl }}#open-positions" class="cp-btn-primary">Reset Search Filters</a>
                                    </div>
                                @endif
                            </div>
                        @else
                            <div class="cp-job-list" id="all-open-roles">
                                @foreach($displayJobs as $job)
                                    @php
                                        $jobUrl = route('careers.show', ['tenantSlug' => $job->tenant->slug, 'jobSlug' => $job->slug]);
                                        $catLabel = $catNameMap[$job->category_slug ?? ''] ?? ($job->department?->name ?? 'General');
                                        $catIcon = $catIconMap[$job->category_slug ?? ''] ?? 'briefcase';
                                        $empLabel = ucwords(strtolower(str_replace('_', '-', (string) $job->employment_type)));
                                        $locLabel = $job->location ? ($job->location->city ? $job->location->city . ', ' . $job->location->country_code : $job->location->name) : 'Global / Flexible';
                                    @endphp
                                    <a href="{{ $jobUrl }}" class="cp-job-row">
                                        <div class="cp-job-row-left">
                                            <span class="cp-job-icon-tile" aria-hidden="true">
                                                @include('careers.partials.icon', ['icon' => $catIcon, 'size' => 22])
                                            </span>
                                            <div>
                                                <h3 class="cp-job-title">{{ $job->title }}</h3>
                                                <div class="cp-job-meta">
                                                    <span class="cp-job-tag">{{ $catLabel }}</span>
                                                    @if(!isset($scopeTenant) || !$scopeTenant)
                                                        <span class="fw-semibold text-dark">{{ $job->tenant->name }}</span>
                                                        <span aria-hidden="true">&bull;</span>
                                                    @endif
                                                    <span>{{ $empLabel }}</span>
                                                    <span aria-hidden="true">&bull;</span>
                                                    <span>{{ $locLabel }}</span>
                                                    <span class="cp-job-tag cp-job-tag-mode">{{ $job->work_mode }}</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="cp-job-row-right">
                                            <span class="cp-job-age">
                                                {{ $job->published_at ? $job->published_at->diffForHumans() : 'Recently posted' }}
                                            </span>
                                            <span class="cp-btn-outline py-1 px-3 small">
                                                View &amp; Apply &rarr;
                                            </span>
                                        </div>
                                    </a>
                                @endforeach
                            </div>

                            @if($jobs->hasPages())
                                <div class="mt-4 d-flex justify-content-center">
                                    {{ $jobs->links() }}
                                </div>
                            @endif
                        @endif
                    </div>
                </section>

            @elseif($sectionKey === 'life_at_tringers' && ($lifeSection['enabled'] ?? true))
                <section class="cp-section cp-section-alt" id="life-at-tringers" aria-labelledby="cp-life-heading">
                    <div class="cp-container">
                        <div class="cp-culture-grid">
                            <div class="cp-culture-media">
                                <img src="{{ asset(ltrim((string) ($lifeSection['image'] ?? '/images/career-portal/culture-team.jpg'), '/')) }}"
                                     alt="{{ $lifeSection['image_alt'] ?? 'Diverse Tringers colleagues collaborating in a modern workspace' }}"
                                     width="800"
                                     height="600"
                                     loading="lazy">
                            </div>
                            <div>
                                <span class="cp-eyebrow cp-eyebrow-light">{{ $lifeSection['eyebrow'] ?? 'LIFE AT TRINGERS' }}</span>
                                <h2 id="cp-life-heading" class="cp-section-title">{{ $lifeSection['heading'] ?? 'A Place to Belong, Grow and Make an Impact' }}</h2>
                                <div class="text-secondary mt-3">
                                    {!! $lifeSection['body_html'] ?? '<p>At Tringers, we believe the best technology and business outcomes are built by empowered, multidisciplinary teams.</p>' !!}
                                </div>

                                @if(($lifeSection['show_metrics'] ?? true) && $verifiedMetrics->isNotEmpty())
                                    <div class="cp-metrics-row" aria-label="Verified Career Portal Metrics">
                                        @foreach($verifiedMetrics->take(3) as $metric)
                                            <div>
                                                <div class="cp-metric-value">{{ $metric['value'] }}</div>
                                                <div class="cp-metric-label">{{ $metric['label'] }}</div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                <a href="{{ $lifeSection['cta_url'] ?? '#employee-stories' }}" class="cp-btn-primary mt-2">
                                    {{ $lifeSection['cta_label'] ?? 'Explore Life at Tringers' }}
                                    <span aria-hidden="true">&rarr;</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </section>

            @elseif($sectionKey === 'employee_stories' && ($storiesSection['enabled'] ?? true))
                @if($approvedStories->isNotEmpty())
                    <section class="cp-section" id="employee-stories" aria-labelledby="cp-stories-heading">
                        <div class="cp-container">
                            <div class="cp-section-header text-center">
                                <span class="cp-eyebrow cp-eyebrow-light">OUR PEOPLE</span>
                                <h2 id="cp-stories-heading" class="cp-section-title">{{ $storiesSection['heading'] ?? 'Our People, Their Stories' }}</h2>
                                <p class="cp-section-subtitle mx-auto">
                                    {{ $storiesSection['subtitle'] ?? 'Hear from team members building their careers and shaping the future with Tringers.' }}
                                </p>
                            </div>

                            <div class="cp-stories-grid">
                                @foreach($approvedStories as $story)
                                    <article class="cp-story-card">
                                        <blockquote class="cp-story-quote">
                                            &ldquo;{{ $story['quote'] }}&rdquo;
                                        </blockquote>
                                        <div class="cp-story-author">
                                            <div class="cp-story-avatar" aria-hidden="true">
                                                @if(!empty($story['avatar_url']))
                                                    <img src="{{ asset(ltrim((string) $story['avatar_url'], '/')) }}" alt="{{ $story['avatar_alt'] ?? $story['name'] }}" loading="lazy">
                                                @else
                                                    {{ strtoupper(substr((string) ($story['name'] ?? 'T'), 0, 1)) }}
                                                @endif
                                            </div>
                                            <div>
                                                <div class="fw-bold text-dark">{{ $story['name'] }}</div>
                                                <div class="small text-secondary">
                                                    {{ $story['role'] }}
                                                    @if(!empty($story['location']))
                                                        &bull; {{ $story['location'] }}
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </div>
                    </section>
                @elseif(($storiesSection['empty_behavior'] ?? 'fallback_banner') === 'fallback_banner')
                    <section class="cp-section" id="employee-stories" aria-labelledby="cp-stories-fallback-heading">
                        <div class="cp-container">
                            <div class="row g-4 align-items-stretch" id="students">
                                <div class="col-lg-7">
                                    <div class="card h-100 border p-4 p-lg-5" style="border-radius: var(--cp-radius); border-color: var(--cp-border) !important;">
                                        <span class="cp-eyebrow cp-eyebrow-light">OUR PEOPLE &amp; CULTURE</span>
                                        <h2 id="cp-stories-fallback-heading" class="h3 fw-bold text-dark mb-2">
                                            {{ $storiesSection['fallback_heading'] ?? 'Build Your Story With Tringers' }}
                                        </h2>
                                        <p class="text-secondary mb-4">
                                            {{ $storiesSection['fallback_text'] ?? 'Connect with our recruitment team and explore open roles across engineering, product, analytics, consulting, and corporate operations.' }}
                                        </p>
                                        <div class="d-flex flex-wrap gap-2 mt-auto" id="diversity">
                                            <a href="#open-positions" class="cp-btn-primary">Browse Open Roles</a>
                                            <a href="#explore-categories" class="cp-btn-outline">Explore Practice Areas</a>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-5" id="talent-network">
                                    <div class="card h-100 border p-4 p-lg-5 bg-light" style="border-radius: var(--cp-radius); border-color: var(--cp-border) !important;">
                                        <span class="cp-eyebrow cp-eyebrow-light">STUDENTS, DIVERSITY &amp; TALENT NETWORK</span>
                                        <h3 class="h5 fw-bold text-dark mb-2">Inclusive Hiring &amp; Early Careers</h3>
                                        <p class="small text-secondary mb-3">
                                            We welcome experienced professionals, university graduates, and returning talent across all backgrounds. Every application is evaluated on merit with structured, bias-mitigated scorecards.
                                        </p>
                                        <a href="#open-positions" class="cp-btn-outline w-100 justify-content-center mt-auto">
                                            Apply to an Open Position &rarr;
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                @endif

            @elseif($sectionKey === 'final_cta' && ($finalCta['enabled'] ?? true))
                @php
                    $ctaBg = asset(ltrim((string) ($finalCta['background_image'] ?? '/images/career-portal/cta-mountain.jpg'), '/'));
                @endphp
                <section class="cp-section pt-2" aria-labelledby="cp-final-cta-heading">
                    <div class="cp-container">
                        <div class="cp-final-cta" style="background-image: url('{{ $ctaBg }}');">
                            <div class="cp-final-cta-inner">
                                <span class="cp-eyebrow">{{ $finalCta['eyebrow'] ?? 'YOUR NEXT OPPORTUNITY AWAITS' }}</span>
                                <h2 id="cp-final-cta-heading" class="cp-hero-title mb-3" style="font-size: clamp(1.85rem, 3vw, 2.65rem);">
                                    {{ $finalCta['heading'] ?? 'Let’s Build What’s Next' }}
                                </h2>
                                <p class="cp-hero-subtitle mb-4">
                                    {{ $finalCta['supporting_text'] ?? 'Join a global team solving complex challenges with clarity, craftsmanship, and care.' }}
                                </p>
                                <a href="{{ $finalCta['cta_url'] ?? '#open-positions' }}" class="cp-btn-primary px-4 py-3">
                                    {{ $finalCta['cta_label'] ?? 'View All Jobs' }} &rarr;
                                </a>
                            </div>
                        </div>
                    </div>
                </section>
            @endif
        @endforeach
    </main>

    @include('careers.partials.footer')
</div>
@endsection
