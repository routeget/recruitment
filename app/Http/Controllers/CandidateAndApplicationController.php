<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\JobPosting;
use App\Models\ResumeParseJob;
use App\Services\AuditService;
use App\Services\CandidateAndApplicationService;
use App\Services\ResumeParsingService;
use App\Services\TenantContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CandidateAndApplicationController extends Controller
{
    public function candidatesIndex(Request $request): View
    {
        $query = Candidate::withCount(['applications', 'documents'])->orderByDesc('id');

        if ($request->filled('q')) {
            $q = trim($request->string('q')->toString());
            if ($q !== '') {
                $query->where(function ($sub) use ($q) {
                    $sub->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('candidate_code', 'like', "%{$q}%")
                        ->orWhere('current_title', 'like', "%{$q}%")
                        ->orWhere('skills', 'like', "%{$q}%");

                    if (Schema::hasColumn('candidates', 'professional_summary')) {
                        $sub->orWhere('professional_summary', 'like', "%{$q}%")
                            ->orWhere('education', 'like', "%{$q}%")
                            ->orWhere('certifications', 'like', "%{$q}%");
                    }
                });
            }
        }

        if ($request->filled('skill')) {
            $skill = trim($request->string('skill')->toString());
            if ($skill !== '') {
                $query->where('skills', 'like', "%{$skill}%");
            }
        }

        if ($request->filled('parse_status') && Schema::hasColumn('candidates', 'resume_parse_status')) {
            $query->where('resume_parse_status', strtoupper($request->string('parse_status')->toString()));
        }

        return view('candidates.index', [
            'candidates' => $query->paginate(15)->withQueryString(),
            'publishedJobs' => JobPosting::where('status', JobPosting::STATUS_PUBLISHED)->orderBy('title')->get(),
        ]);
    }

    public function candidateShow(int $candidateId, TenantContextService $context): View
    {
        $relations = ['documents.uploader', 'applications.jobPosting'];
        if (Schema::hasTable('resume_parse_jobs')) {
            $relations[] = 'resumeParseJobs.document';
            $relations[] = 'resumeParseJobs.reviewer';
            $relations[] = 'resumeParseJobs.aiTaskRun';
        }

        $candidate = Candidate::withoutTenantScope()
            ->with($relations)
            ->findOrFail($candidateId);

        $context->assertModelBelongsToActiveTenant($candidate, 'Candidate');

        $latestParseJob = Schema::hasTable('resume_parse_jobs')
            ? $candidate->resumeParseJobs->first()
            : null;

        return view('candidates.show', [
            'candidate' => $candidate,
            'latestParseJob' => $latestParseJob,
            'publishedJobs' => JobPosting::where('status', JobPosting::STATUS_PUBLISHED)->orderBy('title')->get(),
        ]);
    }

    public function storeCandidate(
        Request $request,
        CandidateAndApplicationService $service,
        ResumeParsingService $resumeParsing,
        TenantContextService $context
    ): RedirectResponse {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'location_city' => ['nullable', 'string', 'max:100'],
            'country_code' => ['required', 'string', 'size:2'],
            'current_title' => ['nullable', 'string', 'max:150'],
            'years_experience' => ['nullable', 'integer', 'min:0', 'max:60'],
            'skills' => ['nullable', 'string', 'max:400'],
            'source_channel' => ['required', 'in:CAREER_PORTAL,REFERRAL,SOURCED,AGENCY,API'],
            'consent_given' => ['accepted'],
            'resume_file' => ['nullable', 'file', 'max:5120'],
        ]);

        $candidate = $service->upsertCandidateWithConsent([
            ...$validated,
            'consent_given' => true,
        ], (int) $context->getActiveTenantId(), Auth::user());

        $parseNote = '';
        if ($request->hasFile('resume_file')) {
            $doc = $service->storePrivateDocument(
                $candidate,
                $request->file('resume_file'),
                'RESUME',
                null,
                Auth::user()
            );

            if (Schema::hasTable('resume_parse_jobs')) {
                $parseJob = $resumeParsing->parseDocument($candidate, $doc, null, Auth::user());
                $parseNote = " AI Resume Parse Status: {$parseJob->status}.";
            }
        }

        return redirect()->route('candidates.show', $candidate->id)
            ->with('status', "Candidate profile {$candidate->candidate_code} ({$candidate->full_name}) saved.{$parseNote}");
    }

    public function uploadDocument(
        Request $request,
        int $candidateId,
        CandidateAndApplicationService $service,
        ResumeParsingService $resumeParsing,
        TenantContextService $context
    ): RedirectResponse {
        $candidate = Candidate::withoutTenantScope()->findOrFail($candidateId);
        $context->assertModelBelongsToActiveTenant($candidate, 'Candidate');

        $validated = $request->validate([
            'document_type' => ['required', 'in:RESUME,COVER_LETTER,PORTFOLIO,ID_VERIFICATION,SIGNED_OFFER,OTHER'],
            'document' => ['required', 'file', 'max:5120'],
        ]);

        $doc = $service->storePrivateDocument(
            $candidate,
            $request->file('document'),
            $validated['document_type'],
            null,
            Auth::user()
        );

        $parseNote = '';
        if (strtoupper($validated['document_type']) === 'RESUME' && Schema::hasTable('resume_parse_jobs')) {
            $parseJob = $resumeParsing->parseDocument($candidate, $doc, null, Auth::user());
            $parseNote = " AI Resume Parse Status: {$parseJob->status}.";
        }

        return redirect()->route('candidates.show', $candidate->id)
            ->with('status', "Stored private document {$doc->original_filename} (SHA-256: ".substr($doc->sha256_checksum, 0, 12)."...).{$parseNote}");
    }

    public function parseCandidateDocument(
        int $candidateId,
        int $documentId,
        ResumeParsingService $resumeParsing,
        TenantContextService $context
    ): RedirectResponse {
        $candidate = Candidate::withoutTenantScope()->findOrFail($candidateId);
        $context->assertModelBelongsToActiveTenant($candidate, 'Candidate');

        $doc = CandidateDocument::withoutTenantScope()->findOrFail($documentId);
        $context->assertModelBelongsToActiveTenant($doc, 'CandidateDocument');

        if ((int) $doc->candidate_id !== (int) $candidate->id) {
            abort(403, 'DOCUMENT_CANDIDATE_MISMATCH: Document does not belong to the specified candidate.');
        }

        $parseJob = $resumeParsing->parseDocument(
            candidate: $candidate,
            document: $doc,
            applicationId: $doc->application_id,
            actor: Auth::user(),
            forceReprocess: true,
        );

        return back()->with('status', "Resume parsing completed for {$doc->original_filename} with status {$parseJob->status} (Provider: {$parseJob->provider_key} / {$parseJob->model_name}).");
    }

    public function reviewCandidateResumeParse(
        Request $request,
        int $candidateId,
        int $parseJobId,
        ResumeParsingService $resumeParsing,
        TenantContextService $context
    ): RedirectResponse {
        $candidate = Candidate::withoutTenantScope()->findOrFail($candidateId);
        $context->assertModelBelongsToActiveTenant($candidate, 'Candidate');

        $parseJob = ResumeParseJob::withoutTenantScope()->findOrFail($parseJobId);
        $context->assertModelBelongsToActiveTenant($parseJob, 'ResumeParseJob');

        if ((int) $parseJob->candidate_id !== (int) $candidate->id) {
            abort(403, 'PARSE_JOB_CANDIDATE_MISMATCH: Resume parse job does not belong to the specified candidate.');
        }

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'location' => ['nullable', 'string', 'max:120'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'current_title' => ['nullable', 'string', 'max:150'],
            'total_years_experience' => ['nullable', 'integer', 'min:0', 'max:60'],
            'professional_summary' => ['nullable', 'string', 'max:3000'],
            'skills' => ['nullable', 'string', 'max:1000'],
        ]);

        $resumeParsing->applyCorrections(
            parseJob: $parseJob,
            candidate: $candidate,
            correctedPayload: $validated,
            reviewer: Auth::user(),
            isCandidateReview: false,
        );

        return back()->with('status', "Human review & structured corrections saved for {$candidate->candidate_code}.");
    }

    public function downloadDocument(
        int $documentId,
        TenantContextService $context,
        AuditService $audit
    ): StreamedResponse {
        $doc = CandidateDocument::withoutTenantScope()->findOrFail($documentId);
        $context->assertModelBelongsToActiveTenant($doc, 'CandidateDocument');

        if (! Storage::disk($doc->storage_disk)->exists($doc->storage_path)) {
            abort(404, 'Private document file not found on storage disk.');
        }

        $audit->record(
            module: 'documents',
            action: 'candidate_document_downloaded',
            outcome: 'SUCCESS',
            entityType: 'CandidateDocument',
            entityId: (string) $doc->id,
            metadata: [
                'candidate_id' => $doc->candidate_id,
                'document_type' => $doc->document_type,
                'sha256_checksum' => $doc->sha256_checksum,
            ]
        );

        return Storage::disk($doc->storage_disk)->download(
            $doc->storage_path,
            $doc->original_filename,
            ['Content-Type' => $doc->mime_type]
        );
    }

    public function applicationsIndex(Request $request): View
    {
        $query = Application::with(['candidate', 'jobPosting', 'requisition'])->orderByDesc('id');

        if ($request->filled('stage')) {
            $query->where('current_stage', $request->string('stage')->toString());
        }

        return view('applications.index', [
            'applications' => $query->paginate(15)->withQueryString(),
            'candidates' => Candidate::orderBy('last_name')->get(),
            'publishedJobs' => JobPosting::where('status', JobPosting::STATUS_PUBLISHED)->orderBy('title')->get(),
        ]);
    }

    public function applicationShow(int $applicationId, TenantContextService $context): View
    {
        $relations = ['candidate.documents', 'jobPosting', 'requisition', 'stageEvents.actor'];
        if (Schema::hasTable('resume_parse_jobs')) {
            $relations[] = 'candidate.resumeParseJobs.reviewer';
            $relations[] = 'resumeParseJobs.reviewer';
            $relations[] = 'aiMatchReviewer';
        }

        $application = Application::withoutTenantScope()
            ->with($relations)
            ->findOrFail($applicationId);

        $context->assertModelBelongsToActiveTenant($application, 'Application');

        $latestParseJob = null;
        if (Schema::hasTable('resume_parse_jobs')) {
            $latestParseJob = $application->resumeParseJobs->first()
                ?? $application->candidate?->resumeParseJobs?->first();
        }

        return view('applications.show', [
            'application' => $application,
            'latestParseJob' => $latestParseJob,
        ]);
    }

    public function storeApplication(
        Request $request,
        CandidateAndApplicationService $service,
        ResumeParsingService $resumeParsing,
        TenantContextService $context
    ): RedirectResponse {
        $validated = $request->validate([
            'candidate_id' => ['required', 'integer', 'exists:candidates,id'],
            'job_posting_id' => ['required', 'integer', 'exists:job_postings,id'],
            'cover_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $candidate = Candidate::withoutTenantScope()->findOrFail((int) $validated['candidate_id']);
        $job = JobPosting::withoutTenantScope()->with('requisition')->findOrFail((int) $validated['job_posting_id']);

        $context->assertModelBelongsToActiveTenant($candidate, 'Candidate');
        $context->assertModelBelongsToActiveTenant($job, 'JobPosting');

        $app = $service->submitApplication($job, $candidate, [
            'cover_note' => $validated['cover_note'] ?? null,
        ], Auth::user());

        if (Schema::hasColumn('applications', 'ai_match_score')) {
            try {
                $resumeParsing->evaluateApplicationMatch($app->fresh(['jobPosting']), $candidate, Auth::user());
            } catch (Throwable) {
                // Non-blocking if AI provider is disabled or out of budget
            }
        }

        return redirect()->route('applications.show', $app->id)
            ->with('status', "Created application {$app->application_number} in stage {$app->current_stage}.");
    }

    public function evaluateApplicationAiMatch(
        int $applicationId,
        ResumeParsingService $resumeParsing,
        TenantContextService $context
    ): RedirectResponse {
        $application = Application::withoutTenantScope()->with(['candidate', 'jobPosting'])->findOrFail($applicationId);
        $context->assertModelBelongsToActiveTenant($application, 'Application');

        try {
            $updated = $resumeParsing->evaluateApplicationMatch($application, $application->candidate, Auth::user());

            return redirect()->route('applications.show', $updated->id)
                ->with('status', "Evaluated explainable AI candidate-to-job match for {$updated->application_number} (Score: {$updated->ai_match_score}%).");
        } catch (Throwable $e) {
            return redirect()->route('applications.show', $application->id)
                ->withErrors(['ai_match' => $e->getMessage()]);
        }
    }

    public function reviewApplicationAiMatch(
        int $applicationId,
        ResumeParsingService $resumeParsing,
        TenantContextService $context
    ): RedirectResponse {
        $application = Application::withoutTenantScope()->findOrFail($applicationId);
        $context->assertModelBelongsToActiveTenant($application, 'Application');

        $updated = $resumeParsing->reviewApplicationMatch($application, Auth::user());

        return redirect()->route('applications.show', $updated->id)
            ->with('status', "AI candidate-to-job match assessment for {$updated->application_number} marked as human-reviewed.");
    }

    public function transitionApplicationStage(
        Request $request,
        int $applicationId,
        CandidateAndApplicationService $service
    ): RedirectResponse {
        $application = Application::withoutTenantScope()->findOrFail($applicationId);

        $validated = $request->validate([
            'target_stage' => [
                'required',
                'in:RECEIVED,ELIGIBILITY_REVIEW,SCREENING,SHORTLISTED,ASSESSMENT,INTERVIEW,DECISION_PENDING,OFFER_IN_PROGRESS,HIRED_PENDING_HR_HANDOVER,REJECTED,WITHDRAWN,ON_HOLD',
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $service->transitionStage(
            $application,
            $validated['target_stage'],
            Auth::user(),
            $validated['notes'] ?? null
        );

        return redirect()->route('applications.show', $updated->id)
            ->with('status', "Transitioned {$updated->application_number} to {$updated->current_stage}.");
    }

    public function updateConsent(
        Request $request,
        int $candidateId,
        CandidateAndApplicationService $service,
        TenantContextService $context
    ): RedirectResponse {
        $candidate = Candidate::withoutTenantScope()->findOrFail($candidateId);
        $context->assertModelBelongsToActiveTenant($candidate, 'Candidate');

        $validated = $request->validate([
            'consent_given' => ['required', 'boolean'],
            'dsar_status' => ['required', 'string', 'in:NONE,EXPORT_REQUESTED,DELETION_REQUESTED,ANONYMIZED'],
        ]);

        $updated = $service->updateCandidateConsentAndDsar($candidate, $validated, Auth::user());

        return redirect()->route('candidates.show', $updated->id)
            ->with('status', "Updated privacy consent & DSAR status for {$updated->candidate_code}.");
    }
}
