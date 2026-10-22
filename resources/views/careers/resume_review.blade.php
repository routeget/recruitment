@extends('layouts.app')

@php
    $scopeTenant = $tenant;
    $effective = is_array($effectiveData ?? null) ? $effectiveData : [];
@endphp

@section('title', 'Review Extracted Resume Profile — ' . $tenant->name)

@section('head_meta')
    <link href="{{ asset('css/career-portal.css') }}" rel="stylesheet">
    <meta name="robots" content="noindex, nofollow">
@endsection

@section('public_career_portal')
<div class="cp-portal-body">
    @include('careers.partials.header')

    <main id="main-content" class="cp-section py-5">
        <div class="cp-container">
            @if(session('status'))
                <div class="alert alert-success mb-4" role="alert">
                    {{ session('status') }}
                </div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger mb-4" role="alert">
                    <div class="fw-bold mb-1">Please review the highlighted fields</div>
                    <ul class="mb-0">
                        @foreach($errors->all() as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                <div>
                    <span class="cp-job-tag mb-2">Application {{ $application->application_number }}</span>
                    <h1 class="h3 fw-bold text-dark mb-1">Review &amp; Confirm Your Extracted Resume Profile</h1>
                    <p class="text-secondary small mb-0">
                        Role: <strong>{{ $job?->title }}</strong> at {{ $tenant->name }} |
                        AI Parse Status:
                        <span class="badge bg-{{ ($parseJob?->status === 'COMPLETED') ? 'success' : (($parseJob?->status === 'REVIEW_REQUIRED') ? 'warning text-dark' : 'danger') }}">
                            {{ $parseJob?->status ?? 'PENDING' }}
                        </span>
                    </p>
                </div>
                <div class="d-flex gap-2">
                    @if($parseJob)
                        <form method="POST" action="{{ route('careers.application.resume-reprocess', [$tenant->slug, $application->uuid]) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-secondary">Reprocess Resume</button>
                        </form>
                    @endif
                    @if($job)
                        <a href="{{ route('careers.show', [$tenant->slug, $job->slug]) }}" class="btn btn-sm btn-outline-dark">Back to Job Posting</a>
                    @endif
                </div>
            </div>

            @if($parseJob && $parseJob->error_message)
                <div class="alert alert-warning mb-4">
                    <strong>{{ $parseJob->error_code ?? 'REVIEW_REQUIRED' }}:</strong> {{ $parseJob->error_message }}
                    <div class="small mt-1">Please complete or verify your profile details below so the recruitment team has accurate information.</div>
                </div>
            @endif

            @if($parseJob && !empty($parseJob->missing_fields))
                <div class="alert alert-info small mb-4">
                    <strong>Fields Not Found in Resume (Left Blank for Your Completion):</strong>
                    @foreach((array)$parseJob->missing_fields as $mf)
                        <code class="me-1">{{ $mf }}</code>
                    @endforeach
                </div>
            @endif

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card border" style="border-radius: var(--cp-radius);">
                        <div class="card-header bg-white fw-bold py-3 px-4">
                            Verify &amp; Correct Extracted Candidate Fields
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" action="{{ route('careers.application.resume-review.submit', [$tenant->slug, $application->uuid]) }}" class="row g-3">
                                @csrf
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Full Name *</label>
                                    <input type="text" name="full_name" class="form-control form-control-sm" value="{{ old('full_name', $effective['full_name'] ?? $candidate->full_name) }}" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Email Address *</label>
                                    <input type="email" name="email" class="form-control form-control-sm" value="{{ old('email', $effective['emails'][0] ?? $candidate->email) }}" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Phone</label>
                                    <input type="text" name="phone" class="form-control form-control-sm" value="{{ old('phone', $effective['phones'][0] ?? $candidate->phone) }}">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">City / Location</label>
                                    <input type="text" name="location" class="form-control form-control-sm" value="{{ old('location', $effective['location'] ?? $candidate->location_city) }}">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small fw-semibold">Country</label>
                                    <input type="text" name="country_code" class="form-control form-control-sm" value="{{ old('country_code', $effective['country_code'] ?? $candidate->country_code) }}" maxlength="2">
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label small fw-semibold">Current / Most Recent Title</label>
                                    <input type="text" name="current_title" class="form-control form-control-sm" value="{{ old('current_title', $effective['current_title'] ?? $candidate->current_title) }}">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Total Years Exp.</label>
                                    <input type="number" name="total_years_experience" class="form-control form-control-sm" value="{{ old('total_years_experience', $effective['total_years_experience'] ?? $candidate->years_experience) }}" min="0">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small fw-semibold">Skills (Comma-Separated)</label>
                                    <input type="text" name="skills" class="form-control form-control-sm" value="{{ old('skills', implode(', ', (array)($effective['skills'] ?? $candidate->skills ?? []))) }}">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small fw-semibold">Professional Summary</label>
                                    <textarea name="professional_summary" class="form-control form-control-sm" rows="3">{{ old('professional_summary', $effective['professional_summary'] ?? $candidate->professional_summary) }}</textarea>
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="review_consent_given" name="consent_given" value="1" checked required>
                                        <label class="form-check-label small" for="review_consent_given">
                                            I confirm that the reviewed information is accurate and consent to storing this structured profile for my application under <code>PRIVACY-v2.0</code>. *
                                        </label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="cp-btn-primary w-100">Confirm &amp; Save Reviewed Resume Profile</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card border" style="border-radius: var(--cp-radius);">
                        <div class="card-header bg-white fw-bold py-3 px-4">
                            Extracted Work Experience &amp; Education
                        </div>
                        <div class="card-body p-4 small">
                            <div class="fw-bold mb-2">Work Experience ({{ count((array)($effective['work_experience'] ?? [])) }})</div>
                            <ul class="list-group list-group-flush mb-3">
                                @forelse((array)($effective['work_experience'] ?? []) as $exp)
                                    <li class="list-group-item px-0">
                                        <div class="fw-semibold">{{ $exp['job_title'] ?? 'Role' }} — {{ $exp['employer'] ?? 'Employer' }}</div>
                                        <div class="text-muted">{{ $exp['start_date'] ?? '?' }} &ndash; {{ ($exp['is_current'] ?? false) ? 'Present' : ($exp['end_date'] ?? '?') }}</div>
                                    </li>
                                @empty
                                    <li class="list-group-item px-0 text-muted">No structured work experience entries extracted.</li>
                                @endforelse
                            </ul>

                            <div class="fw-bold mb-2">Education &amp; Certifications</div>
                            <ul class="list-group list-group-flush">
                                @forelse((array)($effective['education'] ?? []) as $edu)
                                    <li class="list-group-item px-0">
                                        <strong>{{ $edu['degree'] ?? 'Qualification' }}</strong> — {{ $edu['institution'] ?? 'Institution' }} ({{ $edu['graduation_date'] ?? 'Year N/A' }})
                                    </li>
                                @empty
                                    <li class="list-group-item px-0 text-muted">No structured education entries extracted.</li>
                                @endforelse
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    @include('careers.partials.footer')
</div>
@endsection
