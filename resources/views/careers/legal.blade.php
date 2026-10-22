@extends('layouts.app')

@section('title', $title . ' | Tringers Careers')

@section('head_meta')
    <link href="{{ asset('css/career-portal.css') }}" rel="stylesheet">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <meta name="robots" content="index, follow">
@endsection

@section('public_career_portal')
<div class="cp-portal-body">
    @include('careers.partials.header')

    <main id="main-content" class="cp-section py-5">
        <div class="cp-container" style="max-width: 860px;">
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb small mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('careers.index') }}" class="text-decoration-none">Tringers Careers</a></li>
                    <li class="breadcrumb-item active" aria-current="page">{{ $title }}</li>
                </ol>
            </nav>

            <div class="card border p-4 p-lg-5" style="border-radius: var(--cp-radius);">
                <span class="cp-eyebrow cp-eyebrow-light">LEGAL &amp; PRIVACY GOVERNANCE</span>
                <h1 class="h2 fw-bold text-dark mb-2">{{ $title }}</h1>
                <p class="small text-secondary mb-4">{{ $subtitle }} &bull; Policy Reference: <strong>Privacy &amp; Data Protection Policy v2.0</strong></p>

                @if($pageSlug === 'privacy-policy')
                    <h2 class="h5 fw-bold text-dark mt-3">1. Purpose &amp; Data Controller</h2>
                    <p class="text-secondary">
                        When you explore opportunities or submit an application through the Tringers Career Portal, your personal data is processed strictly for recruitment evaluation, interview coordination, and pre-onboarding compliance for the hiring organization associated with the position.
                    </p>
                    <h2 class="h5 fw-bold text-dark mt-4">2. Private Document Storage &amp; Access Controls</h2>
                    <p class="text-secondary">
                        Candidate resumes, CVs, and supporting documents are stored exclusively in encrypted private storage. They are never exposed on public URLs or shared across unrelated organizations.
                    </p>
                    <h2 class="h5 fw-bold text-dark mt-4">3. Retention &amp; Consent Rights</h2>
                    <p class="text-secondary">
                        Every application records explicit candidate consent and applies the hiring organization's configured retention period. You may request access, correction, or withdrawal of consent by contacting <a href="mailto:{{ $platformBranding['support_email'] ?? 'support@career.tringers.com' }}">{{ $platformBranding['support_email'] ?? 'support@career.tringers.com' }}</a>.
                    </p>
                @elseif($pageSlug === 'terms-of-use')
                    <h2 class="h5 fw-bold text-dark mt-3">1. Career Portal Usage</h2>
                    <p class="text-secondary">
                        The Tringers Career Portal provides verified, approved job postings across participating Tringers organizations. Candidates must provide accurate, truthful information in all job applications and uploaded documents.
                    </p>
                    <h2 class="h5 fw-bold text-dark mt-4">2. Prohibited Conduct</h2>
                    <p class="text-secondary">
                        Automated scraping, unauthorized vulnerability scanning, misrepresentation of identity, or uploading malicious files is strictly prohibited and monitored via correlation-logged security controls.
                    </p>
                    <h2 class="h5 fw-bold text-dark mt-4">3. Equal Opportunity Commitment</h2>
                    <p class="text-secondary">
                        Tringers is an equal opportunity employer. Hiring decisions are made by authorized human decision-makers using structured evaluation criteria, never by automated AI auto-rejection.
                    </p>
                @else
                    <h2 class="h5 fw-bold text-dark mt-3">1. Strictly Necessary Cookies</h2>
                    <p class="text-secondary">
                        We use essential session and CSRF security cookies required for secure form submission, authentication, and cookie consent preference storage.
                    </p>
                    <h2 class="h5 fw-bold text-dark mt-4">2. Privacy-Preserving Analytics</h2>
                    <p class="text-secondary">
                        Optional portal analytics remain disabled unless both platform governance approval and visitor consent are active. Candidate PII (names, emails, phones, CV text) is never transmitted in analytics events.
                    </p>
                @endif

                <div class="mt-4 pt-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <a href="{{ route('careers.index') }}" class="cp-btn-outline">&larr; Back to Career Portal</a>
                    <div class="d-flex gap-3 small">
                        <a href="{{ route('careers.legal', ['page' => 'privacy-policy']) }}">Privacy Policy</a>
                        <a href="{{ route('careers.legal', ['page' => 'terms-of-use']) }}">Terms of Use</a>
                        <a href="{{ route('careers.legal', ['page' => 'cookie-policy']) }}">Cookie Policy</a>
                    </div>
                </div>
            </div>
        </div>
    </main>

    @include('careers.partials.footer')
</div>
@endsection
