@extends('layouts.app')

@section('title', $job->seo_title ?? ($job->title . ' — ' . $tenant->name))

@section('content')
<link rel="canonical" href="{{ route('careers.show', [$tenant->slug, $job->slug]) }}">
<script type="application/ld+json">
{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>

<div class="mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-1 small">
            <li class="breadcrumb-item"><a href="{{ route('tenant.careers.index', ['tenantSlug' => $tenant->slug]) }}">{{ $tenant->name }} Careers</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ $job->title }}</li>
        </ol>
    </nav>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <span class="badge bg-primary mb-1">{{ $tenant->name }}</span>
            <h1 class="h2 fw-bold mb-1">{{ $job->title }}</h1>
            <p class="text-muted small mb-0">
                Location: {{ $job->location?->name }} ({{ $job->location?->city }}, {{ $job->location?->country_code }}) |
                Work Mode: <code>{{ $job->work_mode }}</code> |
                Employment Type: <code>{{ $job->employment_type }}</code> |
                Job Code: <code>{{ $job->code }}</code>
            </p>
        </div>
        @if($job->show_compensation_publicly && $job->compensation_min)
            <div class="stat-card px-3 py-2 text-end">
                <div class="small text-muted">Compensation Range</div>
                <div class="fw-bold text-success">{{ $job->currency_code }} {{ number_format((float)$job->compensation_min) }} – {{ number_format((float)$job->compensation_max) }}</div>
            </div>
        @endif
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card stat-card mb-4">
            <div class="card-body">
                <h2 class="h5 fw-bold">Role Overview</h2>
                <p>{{ $job->summary }}</p>

                <h2 class="h5 fw-bold mt-4">Key Responsibilities</h2>
                <pre class="bg-light p-3 rounded border small" style="white-space: pre-wrap;">{{ $job->responsibilities }}</pre>

                <h2 class="h5 fw-bold mt-4">Qualifications &amp; Experience</h2>
                <pre class="bg-light p-3 rounded border small" style="white-space: pre-wrap;">{{ $job->qualifications }}</pre>

                <h2 class="h6 fw-bold mt-3">Skills</h2>
                <div class="d-flex flex-wrap gap-1">
                    @foreach($job->skills_required ?? [] as $skill)
                        <span class="badge bg-secondary">{{ $skill }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card stat-card border-primary">
            <div class="card-header bg-primary text-white fw-bold">Submit Your Application</div>
            <div class="card-body">
                <form method="POST" action="{{ route('careers.apply', [$tenant->slug, $job->slug]) }}" enctype="multipart/form-data" class="row g-3">
                    @csrf
                    <div class="col-md-6">
                        <label for="first_name" class="form-label small fw-semibold">First Name *</label>
                        <input type="text" id="first_name" name="first_name" class="form-control form-control-sm" value="{{ old('first_name') }}" required>
                    </div>
                    <div class="col-md-6">
                        <label for="last_name" class="form-label small fw-semibold">Last Name *</label>
                        <input type="text" id="last_name" name="last_name" class="form-control form-control-sm" value="{{ old('last_name') }}" required>
                    </div>
                    <div class="col-md-7">
                        <label for="email" class="form-label small fw-semibold">Email Address *</label>
                        <input type="email" id="email" name="email" class="form-control form-control-sm" value="{{ old('email') }}" required>
                    </div>
                    <div class="col-md-5">
                        <label for="phone" class="form-label small fw-semibold">Phone</label>
                        <input type="text" id="phone" name="phone" class="form-control form-control-sm" value="{{ old('phone') }}">
                    </div>
                    <div class="col-md-8">
                        <label for="location_city" class="form-label small fw-semibold">City</label>
                        <input type="text" id="location_city" name="location_city" class="form-control form-control-sm" value="{{ old('location_city') }}">
                    </div>
                    <div class="col-md-4">
                        <label for="country_code" class="form-label small fw-semibold">Country *</label>
                        <input type="text" id="country_code" name="country_code" class="form-control form-control-sm" value="{{ old('country_code', 'US') }}" maxlength="2" required>
                    </div>
                    <div class="col-md-8">
                        <label for="current_title" class="form-label small fw-semibold">Current / Most Recent Title</label>
                        <input type="text" id="current_title" name="current_title" class="form-control form-control-sm" value="{{ old('current_title') }}">
                    </div>
                    <div class="col-md-4">
                        <label for="years_experience" class="form-label small fw-semibold">Years Exp.</label>
                        <input type="number" id="years_experience" name="years_experience" class="form-control form-control-sm" value="{{ old('years_experience', 5) }}" min="0">
                    </div>
                    <div class="col-12">
                        <label for="skills" class="form-label small fw-semibold">Key Skills (Comma-Separated)</label>
                        <input type="text" id="skills" name="skills" class="form-control form-control-sm" value="{{ old('skills') }}" placeholder="PHP, Laravel, Distributed Systems">
                    </div>
                    <div class="col-12">
                        <label for="resume_file" class="form-label small fw-semibold">Resume / CV (PDF, DOC, DOCX, TXT — Stored in Private Storage)</label>
                        <input type="file" id="resume_file" name="resume_file" class="form-control form-control-sm" accept=".pdf,.doc,.docx,.txt">
                    </div>
                    <div class="col-12">
                        <label for="cover_note" class="form-label small fw-semibold">Cover Note</label>
                        <textarea id="cover_note" name="cover_note" class="form-control form-control-sm" rows="2">{{ old('cover_note') }}</textarea>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="consent_given" name="consent_given" value="1" required>
                            <label class="form-check-label small" for="consent_given">
                                I acknowledge the {{ $tenant->name }} Privacy Notice (<code>PRIVACY-v2.0</code>) and consent to the processing of my application data for recruitment evaluation ({{ $tenant->retention_days }} days retention). *
                            </label>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary w-100 fw-semibold">Submit Application</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
