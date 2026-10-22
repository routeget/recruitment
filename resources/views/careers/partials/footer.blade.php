@php
    $footer = $portalConfig['footer'] ?? [];
    $quickLinks = collect($footer['quick_links'] ?? [])->filter(fn ($l) => !empty($l['visible']))->sortBy('order')->values();
    $usefulLinks = collect($footer['useful_links'] ?? [])->filter(fn ($l) => !empty($l['visible']))->sortBy('order')->values();
    $socialLinks = collect($footer['social_links'] ?? [])->filter(fn ($l) => !empty($l['visible']) && !empty($l['url']) && str_starts_with((string) $l['url'], 'https://'))->sortBy('order')->values();
    $legalLinks = collect($footer['legal_links'] ?? [])->filter(fn ($l) => !empty($l['visible']))->sortBy('order')->values();
    $consentConfig = $portalConfig['analytics_consent'] ?? [];
@endphp

<footer class="cp-footer" id="locations" aria-label="Career Portal Footer">
    <div class="cp-container">
        <div class="cp-footer-grid">
            <div>
                <a href="{{ route('careers.index') }}" class="cp-brand text-white mb-3">
                    <span class="cp-brand-emblem" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                            <polygon points="12 2 2 7 12 12 22 7 12 2"/>
                            <polyline points="2 17 12 22 22 17"/>
                            <polyline points="2 12 12 17 22 12"/>
                        </svg>
                    </span>
                    <span class="text-white">{{ $footer['brand_text'] ?? 'Tringers' }}</span>
                </a>
                <p class="fw-semibold text-white mb-2">{{ $footer['tagline'] ?? 'People. Ideas. Impact.' }}</p>
                <p class="small text-secondary mb-2">
                    {{ $platformBranding['display_name'] ?? 'Unified Recruitment AI Platform' }} &mdash; {{ $platformBranding['career_portal_banner'] ?? 'Explore open positions and submit your application.' }}
                </p>
                <p class="small text-secondary mb-0">
                    Support: <code>{{ $platformBranding['support_email'] ?? 'support@career.tringers.com' }}</code>
                </p>
            </div>

            <div>
                <h2 class="cp-footer-heading">Quick Links</h2>
                <ul class="cp-footer-links">
                    @foreach($quickLinks as $link)
                        <li>
                            <a href="{{ $link['url'] ?? '#' }}">{{ $link['label'] }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div>
                <h2 class="cp-footer-heading">Useful Links</h2>
                <ul class="cp-footer-links">
                    @foreach($usefulLinks as $link)
                        <li>
                            <a href="{{ $link['url'] ?? '#' }}">{{ $link['label'] }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div>
                <h2 class="cp-footer-heading">Connect With Us</h2>
                @if($socialLinks->isNotEmpty())
                    <ul class="cp-footer-links">
                        @foreach($socialLinks as $social)
                            <li>
                                <a href="{{ $social['url'] }}" target="_blank" rel="noopener noreferrer">
                                    {{ $social['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="small text-secondary mb-2">
                        Connect with our global talent acquisition teams across India, North America, and Europe.
                    </p>
                    <a href="#open-positions" class="small text-info text-decoration-none fw-medium">
                        Explore All Open Roles &rarr;
                    </a>
                @endif
            </div>
        </div>

        <div class="cp-footer-bottom">
            <div>
                {{ $footer['copyright_text'] ?? '© 2026 Tringers. All rights reserved.' }}
            </div>
            <div class="cp-footer-legal">
                @foreach($legalLinks as $legal)
                    <a href="{{ $legal['url'] ?? '#' }}">{{ $legal['label'] }}</a>
                @endforeach
            </div>
        </div>
    </div>
</footer>

@if(($consentConfig['cookie_consent_required'] ?? true) && !request()->cookie('tringers_cookie_consent'))
    <aside class="cp-cookie-banner" aria-label="Cookie and Privacy Consent">
        <div class="cp-container d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="small text-secondary" style="max-width: 780px;">
                <strong class="text-dark">Privacy &amp; Cookie Preferences:</strong>
                {{ $consentConfig['consent_banner_text'] ?? 'We use strictly necessary cookies to operate the career portal and optional privacy-safe analytics when you consent.' }}
                <a href="{{ route('careers.legal', ['page' => 'cookie-policy']) }}" class=" ms-1">Read Cookie Policy</a>
            </div>
            <div class="d-flex align-items-center gap-2">
                <form method="POST" action="{{ route('careers.cookie-consent') }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="consent_choice" value="essential_only">
                    <button type="submit" class="cp-btn-outline py-1 px-3 small">Essential Only</button>
                </form>
                <form method="POST" action="{{ route('careers.cookie-consent') }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="consent_choice" value="accepted">
                    <button type="submit" class="cp-btn-primary py-1 px-3 small">Accept All</button>
                </form>
            </div>
        </div>
    </aside>
@endif
