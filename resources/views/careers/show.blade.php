@extends('layouts.app')

@php
    $scopeTenant = $tenant;
    $canonicalUrl = route('careers.show', [$tenant->slug, $job->slug]);
    $pf = is_array($prefilledResume ?? null) ? $prefilledResume : [];
@endphp

@section('title', $job->seo_title ?? ($job->title . ' — ' . $tenant->name))

@section('head_meta')
    <link href="{{ asset('css/career-portal.css') }}" rel="stylesheet">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <meta name="description" content="{{ $job->seo_description ?: $job->summary }}">
    <meta name="robots" content="index, follow">
    <script type="application/ld+json">
    {!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
    </script>
@endsection

@section('public_career_portal')
<div class="cp-portal-body">
    @include('careers.partials.header')

    <main id="main-content" class="cp-section py-5">
        <div class="cp-container">
            @if(session('status'))
                <div class="alert alert-success mb-4" role="alert">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span>{{ session('status') }}</span>
                        @if(isset($recentApplication) && $recentApplication && isset($recentParseJob) && $recentParseJob)
                            <a href="{{ route('careers.application.resume-review', [$tenant->slug, $recentApplication->uuid]) }}" class="btn btn-sm btn-dark">
                                Review &amp; Confirm Extracted Resume Details &rarr;
                            </a>
                        @endif
                    </div>
                </div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger mb-4" role="alert">
                    <div class="fw-bold mb-1">Please review your application details</div>
                    <ul class="mb-0">
                        @foreach($errors->all() as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mb-4">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-2 small">
                        <li class="breadcrumb-item"><a href="{{ route('careers.index') }}" class="text-decoration-none">Tringers Careers</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('tenant.careers.index', ['tenantSlug' => $tenant->slug]) }}" class="text-decoration-none">{{ $tenant->name }} Careers</a></li>
                        <li class="breadcrumb-item active" aria-current="page">{{ $job->title }}</li>
                    </ol>
                </nav>
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div>
                        <span class="cp-job-tag mb-2">{{ $tenant->name }}</span>
                        <h1 class="h2 fw-bold text-dark mb-2">{{ $job->title }}</h1>
                        <p class="text-secondary small mb-0">
                            Location: {{ $job->location?->name }} ({{ $job->location?->city }}, {{ $job->location?->country_code }}) |
                            Work Mode: <code>{{ $job->work_mode }}</code> |
                            Employment Type: <code>{{ $job->employment_type }}</code> |
                            Job Code: <code>{{ $job->code }}</code>
                        </p>
                    </div>
                    @if($job->show_compensation_publicly && $job->compensation_min)
                        <div class="card border px-3 py-2 text-end" style="border-radius: var(--cp-radius);">
                            <div class="small text-secondary">Compensation Range</div>
                            <div class="fw-bold text-success">{{ $job->currency_code }} {{ number_format((float)$job->compensation_min) }} – {{ number_format((float)$job->compensation_max) }}</div>
                        </div>
                    @endif
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card border mb-4" style="border-radius: var(--cp-radius);">
                        <div class="card-body p-4">
                            <h2 class="h5 fw-bold text-dark">Role Overview</h2>
                            <p class="text-secondary">{{ $job->summary }}</p>

                            <h2 class="h5 fw-bold text-dark mt-4">Key Responsibilities</h2>
                            <pre class="bg-light p-3 rounded border small" style="white-space: pre-wrap;">{{ $job->responsibilities }}</pre>

                            <h2 class="h5 fw-bold text-dark mt-4">Qualifications &amp; Experience</h2>
                            <pre class="bg-light p-3 rounded border small" style="white-space: pre-wrap;">{{ $job->qualifications }}</pre>

                            <h2 class="h6 fw-bold text-dark mt-3">Skills</h2>
                            <div class="d-flex flex-wrap gap-2">
                                @foreach($job->skills_required ?? [] as $skill)
                                    <span class="cp-job-tag">{{ $skill }}</span>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- Pre-Submission AI Resume Auto-Fill Card --}}
                    <div class="card border mb-4" style="border-radius: var(--cp-radius);">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h2 class="h6 fw-bold text-dark mb-0">AI-Assisted Resume Pre-Fill (Optional)</h2>
                                <span class="badge bg-light text-dark border">PDF / DOCX / DOC / TXT</span>
                            </div>
                            <p class="text-secondary small mb-3">
                                Upload your resume to automatically extract and pre-fill your application fields below. You can review and edit every extracted field before submitting.
                            </p>
                            <form method="POST" action="{{ route('careers.extract-resume', [$tenant->slug, $job->slug]) }}" enctype="multipart/form-data" class="row g-2 align-items-end">
                                @csrf
                                <div class="col-md-7">
                                    <label for="extract_resume_file" class="form-label small fw-semibold mb-1">Select Resume File</label>
                                    <input type="file" id="extract_resume_file" name="resume_file" class="form-control form-control-sm" accept=".pdf,.doc,.docx,.txt" required>
                                </div>
                                <div class="col-md-5">
                                    <button type="submit" class="btn btn-sm btn-outline-dark w-100">Extract &amp; Pre-Fill Form with AI</button>
                                </div>
                                <div class="col-12 mt-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="extract_consent_given" name="consent_given" value="1" required>
                                        <label class="form-check-label small text-secondary" for="extract_consent_given">
                                            I consent to automated resume parsing under the {{ $tenant->name }} Privacy Notice (<code>PRIVACY-v2.0</code>). *
                                        </label>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card border" style="border-radius: var(--cp-radius); border-color: var(--cp-primary) !important;">
                        <div class="card-header text-white fw-bold py-3 px-4" style="background-color: var(--cp-primary); border-top-left-radius: calc(var(--cp-radius) - 1px); border-top-right-radius: calc(var(--cp-radius) - 1px);">
                            Submit Your Application
                        </div>
                        <div class="card-body p-4">
                            @if(!empty($pf))
                                <div class="alert alert-info py-2 px-3 small mb-3">
                                    <strong>AI Pre-Fill Active:</strong> Please review and complete the extracted fields below before final submission.
                                    @if(!empty($pf['missing_fields']))
                                        <div class="mt-1">Unstated fields left blank: <code>{{ implode(', ', (array)$pf['missing_fields']) }}</code></div>
                                    @endif
                                </div>
                            @endif

                            <form method="POST" action="{{ route('careers.apply', [$tenant->slug, $job->slug]) }}" enctype="multipart/form-data" class="row g-3">
                                @csrf
                                <div class="col-md-6">
                                    <label for="first_name" class="form-label small fw-semibold">First Name *</label>
                                    <input type="text" id="first_name" name="first_name" class="form-control form-control-sm" value="{{ old('first_name', $pf['first_name'] ?? '') }}" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="last_name" class="form-label small fw-semibold">Last Name *</label>
                                    <input type="text" id="last_name" name="last_name" class="form-control form-control-sm" value="{{ old('last_name', $pf['last_name'] ?? '') }}" required>
                                </div>
                                <div class="col-md-7">
                                    <label for="email" class="form-label small fw-semibold">Email Address *</label>
                                    <input type="email" id="email" name="email" class="form-control form-control-sm" value="{{ old('email', $pf['email'] ?? '') }}" required>
                                </div>
                                <div class="col-md-5">
                                    <label for="phone" class="form-label small fw-semibold">Phone</label>
                                    <input type="text" id="phone" name="phone" class="form-control form-control-sm" value="{{ old('phone', $pf['phone'] ?? '') }}">
                                </div>
                                <div class="col-md-8">
                                    <label for="location_city" class="form-label small fw-semibold">City</label>
                                    <input type="text" id="location_city" name="location_city" class="form-control form-control-sm" value="{{ old('location_city', $pf['location_city'] ?? '') }}">
                                </div>
                                <div class="col-md-4">
                                    <label for="country_code" class="form-label small fw-semibold">Country *</label>
                                    <input type="text" id="country_code" name="country_code" class="form-control form-control-sm" value="{{ old('country_code', $pf['country_code'] ?? 'US') }}" maxlength="2" required>
                                </div>
                                <div class="col-md-8">
                                    <label for="current_title" class="form-label small fw-semibold">Current / Most Recent Title</label>
                                    <input type="text" id="current_title" name="current_title" class="form-control form-control-sm" value="{{ old('current_title', $pf['current_title'] ?? '') }}">
                                </div>
                                <div class="col-md-4">
                                    <label for="years_experience" class="form-label small fw-semibold">Years Exp.</label>
                                    <input type="number" id="years_experience" name="years_experience" class="form-control form-control-sm" value="{{ old('years_experience', $pf['years_experience'] ?? 5) }}" min="0">
                                </div>
                                <div class="col-12">
                                    <label for="skills" class="form-label small fw-semibold">Key Skills (Comma-Separated)</label>
                                    <input type="text" id="skills" name="skills" class="form-control form-control-sm" value="{{ old('skills', $pf['skills'] ?? '') }}" placeholder="PHP, Laravel, Distributed Systems">
                                </div>
                                <div class="col-12">
                                    <label for="professional_summary" class="form-label small fw-semibold">Professional Summary</label>
                                    <textarea id="professional_summary" name="professional_summary" class="form-control form-control-sm" rows="2">{{ old('professional_summary', $pf['professional_summary'] ?? '') }}</textarea>
                                </div>
                                <div class="col-12">
                                    <label for="resume_file" class="form-label small fw-semibold">Resume / CV (PDF, DOC, DOCX, TXT — Stored in Private Storage &amp; AI-Parsed)</label>
                                    <input type="file" id="resume_file" name="resume_file" class="form-control form-control-sm" accept=".pdf,.doc,.docx,.txt">
                                </div>
                                <div class="col-12">
                                    <label for="cover_note" class="form-label small fw-semibold">Cover Note</label>
                                    <textarea id="cover_note" name="cover_note" class="form-control form-control-sm" rows="2">{{ old('cover_note') }}</textarea>
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="consent_given" name="consent_given" value="1" @checked(old('consent_given', $pf['consent_given'] ?? false)) required>
                                        <label class="form-check-label small" for="consent_given">
                                            I acknowledge the {{ $tenant->name }} Privacy Notice (<code>PRIVACY-v2.0</code>) and consent to the processing of my application data for recruitment evaluation ({{ $tenant->retention_days }} days retention). *
                                        </label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="cp-btn-primary w-100">Submit Application</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    @include('careers.partials.footer')
</div>
@endsection
