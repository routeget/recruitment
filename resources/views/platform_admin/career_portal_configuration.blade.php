@extends('layouts.app')

@section('title', 'Career Portal Configuration — Platform Administration')

@php
    $sections = [
        'overview' => '1. Overview & Section Order',
        'brand_theme' => '2. Brand & Theme Tokens',
        'header_navigation' => '3. Header & Navigation',
        'hero_search' => '4. Hero & Job Search',
        'value_propositions' => '5. Value Propositions',
        'job_categories' => '6. Job Categories',
        'featured_jobs' => '7. Featured Jobs',
        'life_at_tringers' => '8. Life at Tringers & Metrics',
        'employee_stories' => '9. Employee Stories',
        'final_cta' => '10. Final CTA Banner',
        'footer' => '11. Footer & Social Links',
        'seo' => '12. SEO & Sharing',
        'accessibility' => '13. Accessibility & Responsive',
        'analytics_consent' => '14. Analytics & Consent',
        'preview_history' => '15. Preview, Publish & History',
    ];
    $theme = $draftPayload['brand_theme'] ?? [];
    $headerNav = $draftPayload['header_navigation'] ?? [];
    $hero = $draftPayload['hero_search'] ?? [];
    $vp = $draftPayload['value_propositions'] ?? [];
    $cats = $draftPayload['job_categories'] ?? [];
    $feat = $draftPayload['featured_jobs'] ?? [];
    $life = $draftPayload['life_at_tringers'] ?? [];
    $stories = $draftPayload['employee_stories'] ?? [];
    $cta = $draftPayload['final_cta'] ?? [];
    $footer = $draftPayload['footer'] ?? [];
    $seo = $draftPayload['seo'] ?? [];
    $a11y = $draftPayload['accessibility'] ?? [];
    $consent = $draftPayload['analytics_consent'] ?? [];
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-1">
                <li class="breadcrumb-item"><a href="{{ route('platform-admin.index') }}">Platform Administration</a></li>
                <li class="breadcrumb-item active" aria-current="page">Career Portal Configuration</li>
            </ol>
        </nav>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <h1 class="h3 fw-bold mb-0">Career Portal Configuration</h1>
            <span class="badge bg-primary">Draft v{{ $config->draft_version }}</span>
            <span class="badge {{ $config->published_version_number > 0 ? 'bg-success' : 'bg-secondary' }}">
                Published v{{ $config->published_version_number }}
            </span>
            <span class="badge {{ $validation['valid'] ? 'bg-success' : 'bg-danger' }}">
                Validation: {{ $validation['valid'] ? 'VALID (Ready to Publish)' : 'INVALID (Blocked)' }}
            </span>
            @if($scopeTenant)
                <span class="badge bg-info text-dark">
                    Tenant Override Scope: {{ $scopeTenant->name }} (Immutable Slug: <code>/{{ $scopeTenant->slug }}</code>)
                </span>
            @else
                <span class="badge bg-dark">Global Root Scope (<code>career.tringers.com/</code>)</span>
            @endif
        </div>
        <p class="text-muted small mb-0 mt-1">
            Manage the Tringers public career portal (`URAP-L2-CAREER-PORTAL-001`) with draft isolation, WCAG 2.2 AA contrast validation, preview tokens, and immutable version rollback.
        </p>
    </div>

    <div class="d-flex align-items-center gap-2 flex-wrap">
        <!-- Scope Switcher -->
        <form method="GET" action="{{ route('platform-admin.career-portal.index') }}" class="d-flex align-items-center gap-2">
            <input type="hidden" name="section" value="{{ $activeSection }}">
            <label for="scope-tenant-select" class="small fw-semibold text-nowrap mb-0">Scope:</label>
            <select id="scope-tenant-select" name="tenant_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">Global Aggregate Portal (/)</option>
                @foreach($tenants as $t)
                    <option value="{{ $t->id }}" @selected($scopeTenant && $scopeTenant->id === $t->id)>
                        Tenant: {{ $t->name }} (/{{ $t->slug }})
                    </option>
                @endforeach
            </select>
        </form>

        <!-- Preview Form -->
        <form method="POST" action="{{ route('platform-admin.career-portal.preview') }}" target="_blank" class="d-inline">
            @csrf
            @if($scopeTenant)
                <input type="hidden" name="tenant_id" value="{{ $scopeTenant->id }}">
            @endif
            <button type="submit" class="btn btn-sm btn-outline-primary fw-semibold">
                Preview Draft &nearr;
            </button>
        </form>

        <!-- Validate Form -->
        <form method="POST" action="{{ route('platform-admin.career-portal.draft.validate') }}" class="d-inline">
            @csrf
            @if($scopeTenant)
                <input type="hidden" name="tenant_id" value="{{ $scopeTenant->id }}">
            @endif
            <button type="submit" class="btn btn-sm btn-outline-secondary fw-semibold">
                Validate Draft
            </button>
        </form>

        <a href="{{ $scopeTenant ? route('tenant.careers.index', ['tenantSlug' => $scopeTenant->slug]) : route('careers.index') }}" target="_blank" class="btn btn-sm btn-outline-dark">
            View Live Portal &nearr;
        </a>
    </div>
</div>

@if(!$validation['valid'])
    <div class="alert alert-warning border-warning mb-4" role="alert">
        <div class="fw-bold mb-1">Draft Validation Findings (Publication Blocked Until Resolved):</div>
        <ul class="small mb-0">
            @foreach($validation['errors'] as $fieldKey => $errMsg)
                <li><code>{{ $fieldKey }}</code>: {{ $errMsg }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row g-4">
    <!-- Left Navigation for 15 Configuration Groups -->
    <div class="col-lg-3">
        <div class="card border shadow-xs">
            <div class="card-header bg-light fw-bold small text-uppercase">Configuration Sections</div>
            <div class="list-group list-group-flush">
                @foreach($sections as $secKey => $secLabel)
                    <a href="{{ route('platform-admin.career-portal.index', array_filter(['section' => $secKey, 'tenant_id' => $scopeTenant?->id])) }}"
                       class="list-group-item list-group-item-action small d-flex justify-content-between align-items-center {{ $activeSection === $secKey ? 'active fw-semibold' : '' }}">
                        <span>{{ $secLabel }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        @if($canPublish)
            <div class="card border-success mt-3 shadow-xs">
                <div class="card-header bg-success text-white fw-bold small">Publish Validated Draft</div>
                <div class="card-body p-3">
                    <form method="POST" action="{{ route('platform-admin.career-portal.publish') }}" onsubmit="return confirm('Publish Draft v{{ $config->draft_version }} to the live public career portal?');">
                        @csrf
                        @if($scopeTenant)
                            <input type="hidden" name="tenant_id" value="{{ $scopeTenant->id }}">
                        @endif
                        <input type="hidden" name="expected_draft_version" value="{{ $config->draft_version }}">
                        <div class="mb-2">
                            <label for="quick-publish-summary" class="form-label small fw-semibold mb-1">Publication Summary *</label>
                            <input type="text" id="quick-publish-summary" name="change_summary" class="form-control form-control-sm" required minlength="5" maxlength="450" placeholder="e.g. Updated hero headline and featured roles">
                        </div>
                        <button type="submit" class="btn btn-sm btn-success w-100 fw-semibold" @disabled(!$validation['valid'])>
                            Publish Draft v{{ $config->draft_version }} Now
                        </button>
                    </form>
                </div>
            </div>
        @endif
    </div>

    <!-- Central Configuration Editor Workspace -->
    <div class="col-lg-9">
        @if($activeSection === 'overview')
            <div class="card border shadow-xs mb-4">
                <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                    <span>1. Overview, Scope &amp; Homepage Section Ordering</span>
                    <span class="badge bg-light text-dark border">Optimistic Lock: Draft v{{ $config->draft_version }}</span>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="p-3 border rounded bg-light">
                                <div class="small text-muted">Active Scope</div>
                                <div class="fw-bold">{{ $scopeTenant ? $scopeTenant->name : 'Global Aggregate' }}</div>
                                <div class="small text-secondary">Slug: <code>{{ $scopeTenant ? '/'.$scopeTenant->slug : '/' }}</code> (Immutable)</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="p-3 border rounded bg-light">
                                <div class="small text-muted">Draft Version</div>
                                <div class="fw-bold">v{{ $config->draft_version }} ({{ $config->status }})</div>
                                <div class="small text-secondary">{{ $config->updated_at?->diffForHumans() ?? 'Just now' }}</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="p-3 border rounded bg-light">
                                <div class="small text-muted">Published Version</div>
                                <div class="fw-bold text-success">v{{ $config->published_version_number }}</div>
                                <div class="small text-secondary">{{ $config->last_published_at?->diffForHumans() ?? 'Default active' }}</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="p-3 border rounded bg-light">
                                <div class="small text-muted">WCAG Contrast Ratio</div>
                                <div class="fw-bold text-primary">{{ $validation['contrast']['body_on_surface'] ?? 10.5 }}:1</div>
                                <div class="small text-secondary">Target: &ge; 4.5:1 (AA)</div>
                            </div>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('platform-admin.career-portal.draft.save') }}">
                        @csrf
                        <input type="hidden" name="active_section" value="overview">
                        <input type="hidden" name="expected_draft_version" value="{{ $config->draft_version }}">
                        @if($scopeTenant)
                            <input type="hidden" name="tenant_id" value="{{ $scopeTenant->id }}">
                        @endif

                        <h2 class="h6 fw-bold mb-2">Section Visibility &amp; Order</h2>
                        <p class="small text-muted mb-3">Enable, disable, or reorder public homepage sections. Disabled sections are cleanly omitted without leaving empty layout gaps.</p>

                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Order Position</th>
                                        <th>Section Identifier</th>
                                        <th>Enabled on Portal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($draftPayload['section_order'] ?? [] as $idx => $secKey)
                                        <tr>
                                            <td style="width: 160px;">
                                                <select name="payload[section_order][{{ $idx }}]" class="form-select form-select-sm">
                                                    @foreach(['value_propositions', 'job_categories', 'featured_jobs', 'life_at_tringers', 'employee_stories', 'final_cta'] as $opt)
                                                        <option value="{{ $opt }}" @selected($secKey === $opt)>{{ $opt }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td><code>{{ $secKey }}</code></td>
                                            <td>
                                                <input type="hidden" name="payload[{{ $secKey }}][enabled]" value="0">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" name="payload[{{ $secKey }}][enabled]" value="1" id="toggle-{{ $secKey }}" @checked($draftPayload[$secKey]['enabled'] ?? true)>
                                                    <label class="form-check-label small" for="toggle-{{ $secKey }}">Enabled</label>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-between align-items-center">
                            <input type="text" name="change_summary" class="form-control form-control-sm w-50" placeholder="Draft change summary (optional)" value="Updated homepage section visibility and ordering">
                            <button type="submit" class="btn btn-primary btn-sm fw-semibold px-4">Save Draft Changes</button>
                        </div>
                    </form>
                </div>
            </div>

        @elseif($activeSection === 'brand_theme')
            <div class="card border shadow-xs mb-4">
                <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                    <span>2. Brand &amp; Theme Tokens (Validated Design Tokens)</span>
                    <form method="POST" action="{{ route('platform-admin.career-portal.draft.reset') }}" onsubmit="return confirm('Reset draft theme tokens and content to the approved Tringers Default Theme? This affects only the draft until published.');">
                        @csrf
                        @if($scopeTenant)
                            <input type="hidden" name="tenant_id" value="{{ $scopeTenant->id }}">
                        @endif
                        <input type="hidden" name="expected_draft_version" value="{{ $config->draft_version }}">
                        <button type="submit" class="btn btn-sm btn-outline-danger fw-semibold">
                            Reset to Tringers Default Theme
                        </button>
                    </form>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('platform-admin.career-portal.draft.save') }}" class="row g-3">
                        @csrf
                        <input type="hidden" name="active_section" value="brand_theme">
                        <input type="hidden" name="expected_draft_version" value="{{ $config->draft_version }}">
                        @if($scopeTenant)
                            <input type="hidden" name="tenant_id" value="{{ $scopeTenant->id }}">
                        @endif

                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Primary Brand Color</label>
                            <input type="text" name="payload[brand_theme][primary_color]" value="{{ $theme['primary_color'] ?? '#1D63ED' }}" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Secondary / Navy Color</label>
                            <input type="text" name="payload[brand_theme][secondary_color]" value="{{ $theme['secondary_color'] ?? '#0F172A' }}" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Heading Text Color</label>
                            <input type="text" name="payload[brand_theme][heading_color]" value="{{ $theme['heading_color'] ?? '#0F172A' }}" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Body Text Color</label>
                            <input type="text" name="payload[brand_theme][body_color]" value="{{ $theme['body_color'] ?? '#334155' }}" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Muted Text Color</label>
                            <input type="text" name="payload[brand_theme][muted_color]" value="{{ $theme['muted_color'] ?? '#475569' }}" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Surface Color</label>
                            <input type="text" name="payload[brand_theme][surface_color]" value="{{ $theme['surface_color'] ?? '#FFFFFF' }}" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Surface Alt Color</label>
                            <input type="text" name="payload[brand_theme][surface_alt_color]" value="{{ $theme['surface_alt_color'] ?? '#F8FAFC' }}" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Border Color</label>
                            <input type="text" name="payload[brand_theme][border_color]" value="{{ $theme['border_color'] ?? '#E2E8F0' }}" class="form-control form-control-sm" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Approved Font Family</label>
                            <select name="payload[brand_theme][font_family]" class="form-select form-select-sm">
                                @foreach($approvedFonts as $font)
                                    <option value="{{ $font }}" @selected(($theme['font_family'] ?? 'Inter') === $font)>{{ $font }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Content Max Width (px)</label>
                            <input type="number" name="payload[brand_theme][content_max_width]" value="{{ $theme['content_max_width'] ?? 1280 }}" min="960" max="1600" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Card Border Radius (px)</label>
                            <input type="number" name="payload[brand_theme][border_radius]" value="{{ $theme['border_radius'] ?? 12 }}" min="0" max="32" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Header Height (px)</label>
                            <input type="number" name="payload[brand_theme][header_height]" value="{{ $theme['header_height'] ?? 76 }}" min="56" max="120" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Hero Height (px)</label>
                            <input type="number" name="payload[brand_theme][hero_height]" value="{{ $theme['hero_height'] ?? 560 }}" min="360" max="840" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Hero Overlay Opacity</label>
                            <input type="number" step="0.05" min="0.2" max="0.9" name="payload[brand_theme][hero_overlay_opacity]" value="{{ $theme['hero_overlay_opacity'] ?? 0.58 }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Section Spacing (px)</label>
                            <input type="number" name="payload[brand_theme][section_spacing]" value="{{ $theme['section_spacing'] ?? 80 }}" min="32" max="140" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <input type="hidden" name="payload[brand_theme][sticky_header]" value="0">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="payload[brand_theme][sticky_header]" value="1" id="sticky-header-toggle" @checked($theme['sticky_header'] ?? true)>
                                <label class="form-check-label small fw-semibold" for="sticky-header-toggle">Enable Sticky Corporate Header</label>
                            </div>
                        </div>
                        <div class="col-12 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary btn-sm fw-semibold px-4">Save Brand &amp; Theme Draft</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Managed Public Media Library & Asset Uploader -->
            <div class="card border shadow-xs">
                <div class="card-header bg-white fw-bold">Approved Public Media Library</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('platform-admin.career-portal.assets.upload') }}" enctype="multipart/form-data" class="row g-3 mb-4 pb-3 border-bottom">
                        @csrf
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Asset Title *</label>
                            <input type="text" name="title" class="form-control form-control-sm" required placeholder="Hero Workplace Photography">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Accessible Alt Text (Required) *</label>
                            <input type="text" name="alt_text" class="form-control form-control-sm" required placeholder="Colleagues collaborating at desk">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Image File (JPG/PNG/WEBP, max 5MB) *</label>
                            <input type="file" name="asset_file" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp" required>
                        </div>
                        <div class="col-12 d-flex justify-content-end">
                            <button type="submit" class="btn btn-outline-primary btn-sm fw-semibold">Upload &amp; Approve Media Asset</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Asset Key</th>
                                    <th>Title &amp; Alt Text</th>
                                    <th>Public Path</th>
                                    <th>Dimensions</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($assets as $asset)
                                    <tr>
                                        <td><code>{{ $asset->asset_key }}</code></td>
                                        <td>
                                            <div class="fw-semibold small">{{ $asset->title }}</div>
                                            <div class="text-muted small">Alt: {{ $asset->alt_text }}</div>
                                        </td>
                                        <td><code>{{ $asset->public_path }}</code></td>
                                        <td class="small">{{ $asset->width }} &times; {{ $asset->height }}</td>
                                        <td><span class="badge bg-success">{{ $asset->approval_status }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        @elseif($activeSection === 'header_navigation')
            <div class="card border shadow-xs">
                <div class="card-header bg-white fw-bold">3. Header &amp; Navigation Configuration</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('platform-admin.career-portal.draft.save') }}">
                        @csrf
                        <input type="hidden" name="active_section" value="header_navigation">
                        <input type="hidden" name="expected_draft_version" value="{{ $config->draft_version }}">
                        @if($scopeTenant)
                            <input type="hidden" name="tenant_id" value="{{ $scopeTenant->id }}">
                        @endif

                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Brand Wordmark</label>
                                <input type="text" name="payload[header_navigation][brand_text]" value="{{ $headerNav['brand_text'] ?? 'Tringers' }}" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Primary CTA Button Label</label>
                                <input type="text" name="payload[header_navigation][cta_button][label]" value="{{ $headerNav['cta_button']['label'] ?? 'Join Our Talent Network' }}" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Primary CTA Destination URL</label>
                                <input type="text" name="payload[header_navigation][cta_button][url]" value="{{ $headerNav['cta_button']['url'] ?? '#talent-network' }}" class="form-control form-control-sm" required>
                                <input type="hidden" name="payload[header_navigation][cta_button][visible]" value="1">
                            </div>
                        </div>

                        <h2 class="h6 fw-bold mb-2">Top Header Navigation Items</h2>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Label</th>
                                        <th>Destination URL</th>
                                        <th style="width: 90px;">Order</th>
                                        <th style="width: 90px;">Visible</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($headerNav['nav_items'] ?? [] as $idx => $item)
                                        <tr>
                                            <td>
                                                <code>{{ $item['id'] }}</code>
                                                <input type="hidden" name="payload[header_navigation][nav_items][{{ $idx }}][id]" value="{{ $item['id'] }}">
                                            </td>
                                            <td>
                                                <input type="text" name="payload[header_navigation][nav_items][{{ $idx }}][label]" value="{{ $item['label'] }}" class="form-control form-control-sm" required>
                                            </td>
                                            <td>
                                                <input type="text" name="payload[header_navigation][nav_items][{{ $idx }}][url]" value="{{ $item['url'] }}" class="form-control form-control-sm" required>
                                            </td>
                                            <td>
                                                <input type="number" name="payload[header_navigation][nav_items][{{ $idx }}][order]" value="{{ $item['order'] ?? ($idx + 1) }}" class="form-control form-control-sm">
                                            </td>
                                            <td class="text-center">
                                                <input type="hidden" name="payload[header_navigation][nav_items][{{ $idx }}][visible]" value="0">
                                                <input type="checkbox" class="form-check-input" name="payload[header_navigation][nav_items][{{ $idx }}][visible]" value="1" @checked($item['visible'] ?? true)>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary btn-sm fw-semibold px-4">Save Header &amp; Navigation Draft</button>
                        </div>
                    </form>
                </div>
            </div>

        @elseif($activeSection === 'hero_search')
            <div class="card border shadow-xs">
                <div class="card-header bg-white fw-bold">4. Hero Banner &amp; Job Search Configuration</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('platform-admin.career-portal.draft.save') }}">
                        @csrf
                        <input type="hidden" name="active_section" value="hero_search">
                        <input type="hidden" name="expected_draft_version" value="{{ $config->draft_version }}">
                        @if($scopeTenant)
                            <input type="hidden" name="tenant_id" value="{{ $scopeTenant->id }}">
                        @endif
                        <input type="hidden" name="payload[hero_search][enabled]" value="1">

                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Hero Eyebrow</label>
                                <input type="text" name="payload[hero_search][eyebrow]" value="{{ $hero['eyebrow'] ?? 'BUILD A BRIGHTER TOMORROW' }}" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label small fw-semibold">Hero Headline *</label>
                                <input type="text" name="payload[hero_search][headline]" value="{{ $hero['headline'] ?? 'Grow Your Career With Tringers' }}" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Supporting Paragraph</label>
                                <textarea name="payload[hero_search][supporting_text]" class="form-control form-control-sm" rows="2">{{ $hero['supporting_text'] ?? '' }}</textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Hero Background Image Path *</label>
                                <input type="text" name="payload[hero_search][background_image]" value="{{ $hero['background_image'] ?? '/images/career-portal/hero-workplace.jpg' }}" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Hero Image Alt Text *</label>
                                <input type="text" name="payload[hero_search][background_image_alt]" value="{{ $hero['background_image_alt'] ?? 'Tringers professionals collaborating in a bright modern workplace' }}" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small fw-semibold">Search Input Placeholder</label>
                                <input type="text" name="payload[hero_search][search_placeholder]" value="{{ $hero['search_placeholder'] ?? 'Search jobs by keyword, role or location' }}" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Search Button Label</label>
                                <input type="text" name="payload[hero_search][search_button_label]" value="{{ $hero['search_button_label'] ?? 'Search Jobs' }}" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Popular Chip Behavior</label>
                                <select name="payload[hero_search][chip_behavior]" class="form-select form-select-sm">
                                    <option value="execute" @selected(($hero['chip_behavior'] ?? 'execute') === 'execute')>Execute Search Immediately</option>
                                    <option value="prefill" @selected(($hero['chip_behavior'] ?? 'execute') === 'prefill')>Prefill Search Input</option>
                                </select>
                            </div>
                        </div>

                        <h2 class="h6 fw-bold mb-2">Popular Search Chips</h2>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Chip Label</th>
                                        <th>Search Query</th>
                                        <th>Filter Type</th>
                                        <th style="width: 90px;">Order</th>
                                        <th style="width: 90px;">Visible</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($hero['popular_chips'] ?? [] as $idx => $chip)
                                        <tr>
                                            <td>
                                                <input type="hidden" name="payload[hero_search][popular_chips][{{ $idx }}][id]" value="{{ $chip['id'] }}">
                                                <input type="text" name="payload[hero_search][popular_chips][{{ $idx }}][label]" value="{{ $chip['label'] }}" class="form-control form-control-sm" required>
                                            </td>
                                            <td>
                                                <input type="text" name="payload[hero_search][popular_chips][{{ $idx }}][query]" value="{{ $chip['query'] }}" class="form-control form-control-sm" required>
                                            </td>
                                            <td>
                                                <select name="payload[hero_search][popular_chips][{{ $idx }}][filter_type]" class="form-select form-select-sm">
                                                    @foreach(['keyword', 'work_mode', 'location', 'category'] as $ft)
                                                        <option value="{{ $ft }}" @selected(($chip['filter_type'] ?? 'keyword') === $ft)>{{ $ft }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td>
                                                <input type="number" name="payload[hero_search][popular_chips][{{ $idx }}][order]" value="{{ $chip['order'] ?? ($idx + 1) }}" class="form-control form-control-sm">
                                            </td>
                                            <td class="text-center">
                                                <input type="hidden" name="payload[hero_search][popular_chips][{{ $idx }}][visible]" value="0">
                                                <input type="checkbox" class="form-check-input" name="payload[hero_search][popular_chips][{{ $idx }}][visible]" value="1" @checked($chip['visible'] ?? true)>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary btn-sm fw-semibold px-4">Save Hero &amp; Search Draft</button>
                        </div>
                    </form>
                </div>
            </div>

        @elseif($activeSection === 'value_propositions')
            <div class="card border shadow-xs">
                <div class="card-header bg-white fw-bold">5. Value Propositions Configuration</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('platform-admin.career-portal.draft.save') }}">
                        @csrf
                        <input type="hidden" name="active_section" value="value_propositions">
                        <input type="hidden" name="expected_draft_version" value="{{ $config->draft_version }}">
                        @if($scopeTenant)
                            <input type="hidden" name="tenant_id" value="{{ $scopeTenant->id }}">
                        @endif

                        <div class="mb-3 form-check form-switch">
                            <input type="hidden" name="payload[value_propositions][enabled]" value="0">
                            <input class="form-check-input" type="checkbox" name="payload[value_propositions][enabled]" value="1" id="vp-enabled" @checked($vp['enabled'] ?? true)>
                            <label class="form-check-label fw-semibold small" for="vp-enabled">Display Value Proposition Strip on Career Portal</label>
                        </div>

                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Icon</th>
                                        <th>Card Title</th>
                                        <th>Description</th>
                                        <th style="width: 85px;">Order</th>
                                        <th style="width: 85px;">Visible</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($vp['items'] ?? [] as $idx => $item)
                                        <tr>
                                            <td style="width: 140px;">
                                                <input type="hidden" name="payload[value_propositions][items][{{ $idx }}][id]" value="{{ $item['id'] }}">
                                                <select name="payload[value_propositions][items][{{ $idx }}][icon]" class="form-select form-select-sm">
                                                    @foreach($approvedIcons as $ic)
                                                        <option value="{{ $ic }}" @selected(($item['icon'] ?? 'sparkle') === $ic)>{{ $ic }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td style="width: 220px;">
                                                <input type="text" name="payload[value_propositions][items][{{ $idx }}][title]" value="{{ $item['title'] }}" class="form-control form-control-sm" required>
                                            </td>
                                            <td>
                                                <input type="text" name="payload[value_propositions][items][{{ $idx }}][description]" value="{{ $item['description'] }}" class="form-control form-control-sm" required>
                                            </td>
                                            <td>
                                                <input type="number" name="payload[value_propositions][items][{{ $idx }}][order]" value="{{ $item['order'] ?? ($idx + 1) }}" class="form-control form-control-sm">
                                            </td>
                                            <td class="text-center">
                                                <input type="hidden" name="payload[value_propositions][items][{{ $idx }}][visible]" value="0">
                                                <input type="checkbox" class="form-check-input" name="payload[value_propositions][items][{{ $idx }}][visible]" value="1" @checked($item['visible'] ?? true)>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary btn-sm fw-semibold px-4">Save Value Propositions Draft</button>
                        </div>
                    </form>
                </div>
            </div>

        @elseif($activeSection === 'job_categories')
            <div class="card border shadow-xs">
                <div class="card-header bg-white fw-bold">6. Explore Opportunities (Job Categories)</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('platform-admin.career-portal.draft.save') }}">
                        @csrf
                        <input type="hidden" name="active_section" value="job_categories">
                        <input type="hidden" name="expected_draft_version" value="{{ $config->draft_version }}">
                        @if($scopeTenant)
                            <input type="hidden" name="tenant_id" value="{{ $scopeTenant->id }}">
                        @endif
                        <input type="hidden" name="payload[job_categories][enabled]" value="1">

                        <div class="row g-3 mb-3">
                            <div class="col-md-5">
                                <label class="form-label small fw-semibold">Section Heading</label>
                                <input type="text" name="payload[job_categories][heading]" value="{{ $cats['heading'] ?? 'Explore Opportunities' }}" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label small fw-semibold">Section Subtitle</label>
                                <input type="text" name="payload[job_categories][subtitle]" value="{{ $cats['subtitle'] ?? '' }}" class="form-control form-control-sm">
                            </div>
                        </div>

                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Category Slug</th>
                                        <th>Display Name</th>
                                        <th>Icon</th>
                                        <th>Tile Tint</th>
                                        <th>Icon Color</th>
                                        <th style="width: 80px;">Order</th>
                                        <th style="width: 80px;">Visible</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($cats['items'] ?? [] as $idx => $cat)
                                        <tr>
                                            <td>
                                                <input type="hidden" name="payload[job_categories][items][{{ $idx }}][id]" value="{{ $cat['id'] }}">
                                                <input type="text" name="payload[job_categories][items][{{ $idx }}][slug]" value="{{ $cat['slug'] }}" class="form-control form-control-sm" readonly>
                                            </td>
                                            <td>
                                                <input type="text" name="payload[job_categories][items][{{ $idx }}][name]" value="{{ $cat['name'] }}" class="form-control form-control-sm" required>
                                            </td>
                                            <td>
                                                <select name="payload[job_categories][items][{{ $idx }}][icon]" class="form-select form-select-sm">
                                                    @foreach($approvedIcons as $ic)
                                                        <option value="{{ $ic }}" @selected(($cat['icon'] ?? 'briefcase') === $ic)>{{ $ic }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" name="payload[job_categories][items][{{ $idx }}][bg_tint]" value="{{ $cat['bg_tint'] ?? '#EFF6FF' }}" class="form-control form-control-sm">
                                            </td>
                                            <td>
                                                <input type="text" name="payload[job_categories][items][{{ $idx }}][icon_color]" value="{{ $cat['icon_color'] ?? '#1D63ED' }}" class="form-control form-control-sm">
                                            </td>
                                            <td>
                                                <input type="number" name="payload[job_categories][items][{{ $idx }}][order]" value="{{ $cat['order'] ?? ($idx + 1) }}" class="form-control form-control-sm">
                                            </td>
                                            <td class="text-center">
                                                <input type="hidden" name="payload[job_categories][items][{{ $idx }}][visible]" value="0">
                                                <input type="checkbox" class="form-check-input" name="payload[job_categories][items][{{ $idx }}][visible]" value="1" @checked($cat['visible'] ?? true)>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary btn-sm fw-semibold px-4">Save Job Categories Draft</button>
                        </div>
                    </form>
                </div>
            </div>

        @elseif($activeSection === 'featured_jobs')
            <div class="card border shadow-xs">
                <div class="card-header bg-white fw-bold">7. Featured Jobs Configuration &amp; Curation</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('platform-admin.career-portal.draft.save') }}">
                        @csrf
                        <input type="hidden" name="active_section" value="featured_jobs">
                        <input type="hidden" name="expected_draft_version" value="{{ $config->draft_version }}">
                        @if($scopeTenant)
                            <input type="hidden" name="tenant_id" value="{{ $scopeTenant->id }}">
                        @endif
                        <input type="hidden" name="payload[featured_jobs][enabled]" value="1">

                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Section Heading</label>
                                <input type="text" name="payload[featured_jobs][heading]" value="{{ $feat['heading'] ?? 'Featured Jobs' }}" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Selection Mode</label>
                                <select name="payload[featured_jobs][selection_mode]" class="form-select form-select-sm">
                                    <option value="hybrid" @selected(($feat['selection_mode'] ?? 'hybrid') === 'hybrid')>Hybrid (Manual + Featured Flag + Recent Backfill)</option>
                                    <option value="manual" @selected(($feat['selection_mode'] ?? 'hybrid') === 'manual')>Manual Curated Jobs Only</option>
                                    <option value="featured_flag" @selected(($feat['selection_mode'] ?? 'hybrid') === 'featured_flag')>Featured Flag Jobs Only</option>
                                    <option value="recent" @selected(($feat['selection_mode'] ?? 'hybrid') === 'recent')>Most Recent Published Jobs</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Maximum Featured Jobs</label>
                                <input type="number" name="payload[featured_jobs][max_items]" value="{{ $feat['max_items'] ?? 5 }}" min="1" max="12" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label small fw-semibold">Section Subtitle</label>
                                <input type="text" name="payload[featured_jobs][subtitle]" value="{{ $feat['subtitle'] ?? 'Explore Approved Open Positions' }}" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">View All CTA Label</label>
                                <input type="text" name="payload[featured_jobs][view_all_label]" value="{{ $feat['view_all_label'] ?? 'View All Jobs' }}" class="form-control form-control-sm">
                            </div>
                        </div>

                        <h2 class="h6 fw-bold mb-2">Select Manual Curated Jobs (Only Eligible `PUBLISHED` + `PUBLIC`/`BOTH` Jobs Listed)</h2>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 60px;">Pin</th>
                                        <th>Job Title &amp; Code</th>
                                        <th>Organization</th>
                                        <th>Category</th>
                                        <th>Location &amp; Mode</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($eligibleJobs as $job)
                                        <tr>
                                            <td class="text-center">
                                                <input type="checkbox" class="form-check-input" name="payload[featured_jobs][manual_job_ids][]" value="{{ $job->id }}" @checked(in_array($job->id, $feat['manual_job_ids'] ?? []))>
                                            </td>
                                            <td>
                                                <div class="fw-semibold small">{{ $job->title }}</div>
                                                <code class="small">{{ $job->code }}</code>
                                            </td>
                                            <td class="small">{{ $job->tenant->name }}</td>
                                            <td><span class="badge bg-light text-dark border">{{ $job->category_slug ?? 'general' }}</span></td>
                                            <td class="small">{{ $job->location?->city ?? 'Remote' }} ({{ $job->work_mode }})</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary btn-sm fw-semibold px-4">Save Featured Jobs Draft</button>
                        </div>
                    </form>
                </div>
            </div>

        @elseif($activeSection === 'life_at_tringers')
            <div class="card border shadow-xs">
                <div class="card-header bg-white fw-bold">8. Life at Tringers Culture Panel &amp; Factual Metrics Governance</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('platform-admin.career-portal.draft.save') }}">
                        @csrf
                        <input type="hidden" name="active_section" value="life_at_tringers">
                        <input type="hidden" name="expected_draft_version" value="{{ $config->draft_version }}">
                        @if($scopeTenant)
                            <input type="hidden" name="tenant_id" value="{{ $scopeTenant->id }}">
                        @endif
                        <input type="hidden" name="payload[life_at_tringers][enabled]" value="1">

                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Eyebrow</label>
                                <input type="text" name="payload[life_at_tringers][eyebrow]" value="{{ $life['eyebrow'] ?? 'LIFE AT TRINGERS' }}" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label small fw-semibold">Section Heading</label>
                                <input type="text" name="payload[life_at_tringers][heading]" value="{{ $life['heading'] ?? 'A Place to Belong, Grow and Make an Impact' }}" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Culture Narrative (Safe HTML Allowlist: <code>&lt;p&gt; &lt;strong&gt; &lt;em&gt; &lt;ul&gt; &lt;li&gt;</code>)</label>
                                <textarea name="payload[life_at_tringers][body_html]" class="form-control form-control-sm" rows="3">{{ $life['body_html'] ?? '' }}</textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Culture Image Path</label>
                                <input type="text" name="payload[life_at_tringers][image]" value="{{ $life['image'] ?? '/images/career-portal/culture-team.jpg' }}" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Culture Image Alt Text *</label>
                                <input type="text" name="payload[life_at_tringers][image_alt]" value="{{ $life['image_alt'] ?? 'Diverse Tringers colleagues collaborating in a modern workspace' }}" class="form-control form-control-sm" required>
                            </div>
                        </div>

                        <h2 class="h6 fw-bold mb-2">Metrics Governance (Unverified Illustrative Mockup Claims Cannot Be Published)</h2>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Value</th>
                                        <th>Label</th>
                                        <th>Source Note</th>
                                        <th>Approved</th>
                                        <th>Factually Verified</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($life['metrics'] ?? [] as $idx => $m)
                                        <tr>
                                            <td>
                                                <input type="hidden" name="payload[life_at_tringers][metrics][{{ $idx }}][id]" value="{{ $m['id'] }}">
                                                <input type="hidden" name="payload[life_at_tringers][metrics][{{ $idx }}][order]" value="{{ $m['order'] ?? ($idx + 1) }}">
                                                <input type="text" name="payload[life_at_tringers][metrics][{{ $idx }}][value]" value="{{ $m['value'] }}" class="form-control form-control-sm" required>
                                            </td>
                                            <td>
                                                <input type="text" name="payload[life_at_tringers][metrics][{{ $idx }}][label]" value="{{ $m['label'] }}" class="form-control form-control-sm" required>
                                            </td>
                                            <td>
                                                <input type="text" name="payload[life_at_tringers][metrics][{{ $idx }}][source_note]" value="{{ $m['source_note'] ?? '' }}" class="form-control form-control-sm">
                                            </td>
                                            <td class="text-center">
                                                <input type="hidden" name="payload[life_at_tringers][metrics][{{ $idx }}][approved]" value="0">
                                                <input type="checkbox" class="form-check-input" name="payload[life_at_tringers][metrics][{{ $idx }}][approved]" value="1" @checked($m['approved'] ?? false)>
                                            </td>
                                            <td class="text-center">
                                                <input type="hidden" name="payload[life_at_tringers][metrics][{{ $idx }}][verified]" value="0">
                                                <input type="checkbox" class="form-check-input" name="payload[life_at_tringers][metrics][{{ $idx }}][verified]" value="1" @checked($m['verified'] ?? false)>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary btn-sm fw-semibold px-4">Save Life at Tringers Draft</button>
                        </div>
                    </form>
                </div>
            </div>

        @elseif($activeSection === 'employee_stories')
            <div class="card border shadow-xs">
                <div class="card-header bg-white fw-bold">9. Employee Stories &amp; Testimonial Consent Governance</div>
                <div class="card-body">
                    <div class="alert alert-info small">
                        <strong>Content Governance Policy:</strong> Never publish fabricated employee identities, quotes, or photos. A testimonial is rendered publicly only when <code>approved</code>, <code>consent_confirmed</code>, and <code>published</code> are all checked. When no testimonials are published, the portal displays the configured Talent Community fallback banner.
                    </div>
                    <form method="POST" action="{{ route('platform-admin.career-portal.draft.save') }}">
                        @csrf
                        <input type="hidden" name="active_section" value="employee_stories">
                        <input type="hidden" name="expected_draft_version" value="{{ $config->draft_version }}">
                        @if($scopeTenant)
                            <input type="hidden" name="tenant_id" value="{{ $scopeTenant->id }}">
                        @endif
                        <input type="hidden" name="payload[employee_stories][enabled]" value="1">

                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Section Heading</label>
                                <input type="text" name="payload[employee_stories][heading]" value="{{ $stories['heading'] ?? 'Our People, Their Stories' }}" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Behavior When No Approved Stories Exist</label>
                                <select name="payload[employee_stories][empty_behavior]" class="form-select form-select-sm">
                                    <option value="fallback_banner" @selected(($stories['empty_behavior'] ?? 'fallback_banner') === 'fallback_banner')>Show Approved Talent Community Fallback Banner</option>
                                    <option value="hide_section" @selected(($stories['empty_behavior'] ?? 'fallback_banner') === 'hide_section')>Hide Employee Stories Section Cleanly</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Fallback Heading</label>
                                <input type="text" name="payload[employee_stories][fallback_heading]" value="{{ $stories['fallback_heading'] ?? 'Build Your Story With Tringers' }}" class="form-control form-control-sm">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Fallback Description</label>
                                <input type="text" name="payload[employee_stories][fallback_text]" value="{{ $stories['fallback_text'] ?? '' }}" class="form-control form-control-sm">
                            </div>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary btn-sm fw-semibold px-4">Save Employee Stories Draft</button>
                        </div>
                    </form>
                </div>
            </div>

        @elseif($activeSection === 'final_cta')
            <div class="card border shadow-xs">
                <div class="card-header bg-white fw-bold">10. Final Call-to-Action Banner</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('platform-admin.career-portal.draft.save') }}" class="row g-3">
                        @csrf
                        <input type="hidden" name="active_section" value="final_cta">
                        <input type="hidden" name="expected_draft_version" value="{{ $config->draft_version }}">
                        @if($scopeTenant)
                            <input type="hidden" name="tenant_id" value="{{ $scopeTenant->id }}">
                        @endif
                        <input type="hidden" name="payload[final_cta][enabled]" value="1">

                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Eyebrow</label>
                            <input type="text" name="payload[final_cta][eyebrow]" value="{{ $cta['eyebrow'] ?? 'YOUR NEXT OPPORTUNITY AWAITS' }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold">Heading</label>
                            <input type="text" name="payload[final_cta][heading]" value="{{ $cta['heading'] ?? 'Let’s Build What’s Next' }}" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Supporting Copy</label>
                            <input type="text" name="payload[final_cta][supporting_text]" value="{{ $cta['supporting_text'] ?? '' }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Background Image Path</label>
                            <input type="text" name="payload[final_cta][background_image]" value="{{ $cta['background_image'] ?? '/images/career-portal/cta-mountain.jpg' }}" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">CTA Button Label</label>
                            <input type="text" name="payload[final_cta][cta_label]" value="{{ $cta['cta_label'] ?? 'View All Jobs' }}" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">CTA Destination URL</label>
                            <input type="text" name="payload[final_cta][cta_url]" value="{{ $cta['cta_url'] ?? '#open-positions' }}" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-12 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary btn-sm fw-semibold px-4">Save Final CTA Draft</button>
                        </div>
                    </form>
                </div>
            </div>

        @elseif($activeSection === 'footer')
            <div class="card border shadow-xs">
                <div class="card-header bg-white fw-bold">11. Corporate Footer, Social Links &amp; Legal Destinations</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('platform-admin.career-portal.draft.save') }}" class="row g-3">
                        @csrf
                        <input type="hidden" name="active_section" value="footer">
                        <input type="hidden" name="expected_draft_version" value="{{ $config->draft_version }}">
                        @if($scopeTenant)
                            <input type="hidden" name="tenant_id" value="{{ $scopeTenant->id }}">
                        @endif

                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Footer Brand Name</label>
                            <input type="text" name="payload[footer][brand_text]" value="{{ $footer['brand_text'] ?? 'Tringers' }}" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Brand Tagline</label>
                            <input type="text" name="payload[footer][tagline]" value="{{ $footer['tagline'] ?? 'People. Ideas. Impact.' }}" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Copyright Notice</label>
                            <input type="text" name="payload[footer][copyright_text]" value="{{ $footer['copyright_text'] ?? '© 2026 Tringers. All rights reserved.' }}" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-12 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary btn-sm fw-semibold px-4">Save Footer Draft</button>
                        </div>
                    </form>
                </div>
            </div>

        @elseif($activeSection === 'seo')
            <div class="card border shadow-xs">
                <div class="card-header bg-white fw-bold">12. SEO, Open Graph &amp; Structured Data</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('platform-admin.career-portal.draft.save') }}" class="row g-3">
                        @csrf
                        <input type="hidden" name="active_section" value="seo">
                        <input type="hidden" name="expected_draft_version" value="{{ $config->draft_version }}">
                        @if($scopeTenant)
                            <input type="hidden" name="tenant_id" value="{{ $scopeTenant->id }}">
                        @endif

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Page Title *</label>
                            <input type="text" name="payload[seo][page_title]" value="{{ $seo['page_title'] ?? '' }}" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Tenant Title Template</label>
                            <input type="text" name="payload[seo][tenant_title_template]" value="{{ $seo['tenant_title_template'] ?? '{tenant_name} Careers | Tringers' }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Meta Description *</label>
                            <textarea name="payload[seo][meta_description]" class="form-control form-control-sm" rows="2" required>{{ $seo['meta_description'] ?? '' }}</textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Robots Policy</label>
                            <select name="payload[seo][robots_policy]" class="form-select form-select-sm">
                                <option value="index, follow" @selected(($seo['robots_policy'] ?? 'index, follow') === 'index, follow')>index, follow</option>
                                <option value="noindex, nofollow" @selected(($seo['robots_policy'] ?? '') === 'noindex, nofollow')>noindex, nofollow</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Social Preview Image</label>
                            <input type="text" name="payload[seo][og_image]" value="{{ $seo['og_image'] ?? '/images/career-portal/hero-workplace.jpg' }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-12 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary btn-sm fw-semibold px-4">Save SEO Draft</button>
                        </div>
                    </form>
                </div>
            </div>

        @elseif($activeSection === 'accessibility')
            <div class="card border shadow-xs">
                <div class="card-header bg-white fw-bold">13. Accessibility &amp; Responsive Defaults (WCAG 2.2 AA)</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('platform-admin.career-portal.draft.save') }}" class="row g-3">
                        @csrf
                        <input type="hidden" name="active_section" value="accessibility">
                        <input type="hidden" name="expected_draft_version" value="{{ $config->draft_version }}">
                        @if($scopeTenant)
                            <input type="hidden" name="tenant_id" value="{{ $scopeTenant->id }}">
                        @endif

                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">WCAG Target Level</label>
                            <input type="text" class="form-control form-control-sm" value="2.2 AA (Mandatory)" readonly>
                        </div>
                        <div class="col-md-8 d-flex align-items-center gap-4 pt-3">
                            <div class="form-check form-switch">
                                <input type="hidden" name="payload[accessibility][enforce_contrast_validation]" value="1">
                                <input class="form-check-input" type="checkbox" checked disabled>
                                <label class="form-check-label small fw-semibold">Enforce Contrast Validation (&ge; 4.5:1)</label>
                            </div>
                            <div class="form-check form-switch">
                                <input type="hidden" name="payload[accessibility][respect_reduced_motion]" value="0">
                                <input class="form-check-input" type="checkbox" name="payload[accessibility][respect_reduced_motion]" value="1" @checked($a11y['respect_reduced_motion'] ?? true)>
                                <label class="form-check-label small fw-semibold">Respect Reduced Motion</label>
                            </div>
                        </div>
                        <div class="col-12 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary btn-sm fw-semibold px-4">Save Accessibility Draft</button>
                        </div>
                    </form>
                </div>
            </div>

        @elseif($activeSection === 'analytics_consent')
            <div class="card border shadow-xs">
                <div class="card-header bg-white fw-bold">14. Privacy-Preserving Analytics &amp; Cookie Consent</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('platform-admin.career-portal.draft.save') }}" class="row g-3">
                        @csrf
                        <input type="hidden" name="active_section" value="analytics_consent">
                        <input type="hidden" name="expected_draft_version" value="{{ $config->draft_version }}">
                        @if($scopeTenant)
                            <input type="hidden" name="tenant_id" value="{{ $scopeTenant->id }}">
                        @endif

                        <div class="col-md-6">
                            <input type="hidden" name="payload[analytics_consent][cookie_consent_required]" value="0">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="payload[analytics_consent][cookie_consent_required]" value="1" id="cookie-consent-req" @checked($consent['cookie_consent_required'] ?? true)>
                                <label class="form-check-label small fw-semibold" for="cookie-consent-req">Require Cookie Consent Banner on Public Portal</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Cookie Consent Banner Text</label>
                            <textarea name="payload[analytics_consent][consent_banner_text]" class="form-control form-control-sm" rows="2">{{ $consent['consent_banner_text'] ?? '' }}</textarea>
                        </div>
                        <div class="col-12 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary btn-sm fw-semibold px-4">Save Analytics &amp; Consent Draft</button>
                        </div>
                    </form>
                </div>
            </div>

        @else
            <!-- 15. Preview, Validation, Publish & Version History -->
            <div class="card border shadow-xs mb-4">
                <div class="card-header bg-white fw-bold">15. Preview Launcher, Validation Summary &amp; Atomic Publication</div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <h2 class="h6 fw-bold">Authorized Draft Preview Launcher</h2>
                            <p class="small text-muted">Generate a signed, short-lived, non-indexed preview URL (`noindex, nofollow`, `Cache-Control: no-store`) to inspect draft v{{ $config->draft_version }} before publishing.</p>
                            <form method="POST" action="{{ route('platform-admin.career-portal.preview') }}" target="_blank" class="d-flex gap-2 align-items-center">
                                @csrf
                                @if($scopeTenant)
                                    <input type="hidden" name="tenant_id" value="{{ $scopeTenant->id }}">
                                @endif
                                <select name="preview_tenant_slug" class="form-select form-select-sm">
                                    <option value="">Aggregate Root Portal (/)</option>
                                    @foreach($tenants as $t)
                                        <option value="{{ $t->slug }}">Tenant Portal: {{ $t->name }} (/{{ $t->slug }})</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-sm btn-primary text-nowrap fw-semibold">Launch Preview &nearr;</button>
                            </form>
                        </div>

                        <div class="col-md-6">
                            <h2 class="h6 fw-bold">WCAG 2.2 AA Contrast &amp; Schema Verification</h2>
                            <ul class="small mb-0">
                                <li>Body on Surface Contrast: <strong>{{ $validation['contrast']['body_on_surface'] ?? 'N/A' }}:1</strong> (Required &ge; 4.5:1)</li>
                                <li>Heading on Surface Contrast: <strong>{{ $validation['contrast']['heading_on_surface'] ?? 'N/A' }}:1</strong> (Required &ge; 4.5:1)</li>
                                <li>Muted on Surface Contrast: <strong>{{ $validation['contrast']['muted_on_surface'] ?? 'N/A' }}:1</strong> (Required &ge; 4.5:1)</li>
                                <li>Primary CTA Button Contrast: <strong>{{ $validation['contrast']['white_on_primary'] ?? 'N/A' }}:1</strong> (Required &ge; 4.5:1)</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Immutable Version History & Rollback -->
            <div class="card border shadow-xs mb-4">
                <div class="card-header bg-white fw-bold">Immutable Published Version History &amp; Rollback</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Version</th>
                                    <th>Status</th>
                                    <th>Change Summary</th>
                                    <th>Published By</th>
                                    <th>Timestamp</th>
                                    <th>Rollback Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($versions as $ver)
                                    <tr>
                                        <td class="fw-bold">v{{ $ver->version_number }}</td>
                                        <td>
                                            <span class="badge {{ $config->active_published_version_id === $ver->id ? 'bg-success' : 'bg-secondary' }}">
                                                {{ $config->active_published_version_id === $ver->id ? 'ACTIVE PUBLISHED' : $ver->status }}
                                            </span>
                                        </td>
                                        <td class="small">{{ $ver->change_summary }}</td>
                                        <td class="small">{{ $ver->creator?->email ?? 'System' }}</td>
                                        <td class="small">{{ $ver->created_at }}</td>
                                        <td>
                                            @if($canPublish && $config->active_published_version_id !== $ver->id)
                                                <form method="POST" action="{{ route('platform-admin.career-portal.rollback', ['versionNumber' => $ver->version_number]) }}" class="d-flex gap-1">
                                                    @csrf
                                                    @if($scopeTenant)
                                                        <input type="hidden" name="tenant_id" value="{{ $scopeTenant->id }}">
                                                    @endif
                                                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="Rollback reason *" required minlength="5">
                                                    <button type="submit" class="btn btn-sm btn-outline-warning text-dark fw-semibold text-nowrap">
                                                        Restore v{{ $ver->version_number }}
                                                    </button>
                                                </form>
                                            @else
                                                <span class="text-muted small">&mdash;</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted small py-3">No published versions recorded yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Append-Only Publication Events -->
            <div class="card border shadow-xs">
                <div class="card-header bg-white fw-bold">Append-Only Publication &amp; Configuration Audit Events</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Action</th>
                                    <th>Version #</th>
                                    <th>Correlation ID</th>
                                    <th>Summary</th>
                                    <th>Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($publicationEvents as $ev)
                                    <tr>
                                        <td><code>{{ $ev->action }}</code></td>
                                        <td>{{ $ev->version_number ? 'v'.$ev->version_number : 'Draft' }}</td>
                                        <td><code>{{ $ev->correlation_id }}</code></td>
                                        <td class="small">{{ $ev->change_summary }}</td>
                                        <td class="small">{{ $ev->created_at }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted small py-3">No publication events recorded yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
