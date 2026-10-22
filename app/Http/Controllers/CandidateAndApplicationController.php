<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\JobPosting;
use App\Services\AuditService;
use App\Services\CandidateAndApplicationService;
use App\Services\TenantContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CandidateAndApplicationController extends Controller
{
    public function candidatesIndex(Request $request): View
    {
        $query = Candidate::withCount(['applications', 'documents'])->orderByDesc('id');

        if ($request->filled('q')) {
            $q = $request->string('q')->toString();
            $query->where(function ($sub) use ($q) {
                $sub->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('candidate_code', 'like', "%{$q}%");
            });
        }

        return view('candidates.index', [
            'candidates' => $query->paginate(15)->withQueryString(),
            'publishedJobs' => JobPosting::where('status', JobPosting::STATUS_PUBLISHED)->orderBy('title')->get(),
        ]);
    }

    public function candidateShow(int $candidateId, TenantContextService $context): View
    {
        $candidate = Candidate::withoutTenantScope()
            ->with(['documents.uploader', 'applications.jobPosting'])
            ->findOrFail($candidateId);

        $context->assertModelBelongsToActiveTenant($candidate, 'Candidate');

        return view('candidates.show', [
            'candidate' => $candidate,
            'publishedJobs' => JobPosting::where('status', JobPosting::STATUS_PUBLISHED)->orderBy('title')->get(),
        ]);
    }

    public function storeCandidate(
        Request $request,
        CandidateAndApplicationService $service,
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
        ], $context->getActiveTenantId(), Auth::user());

        if ($request->hasFile('resume_file')) {
            $service->storePrivateDocument(
                $candidate,
                $request->file('resume_file'),
                'RESUME',
                null,
                Auth::user()
            );
        }

        return redirect()->route('candidates.show', $candidate->id)
            ->with('status', "Candidate profile {$candidate->candidate_code} ({$candidate->full_name}) saved.");
    }

    public function uploadDocument(
        Request $request,
        int $candidateId,
        CandidateAndApplicationService $service,
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

        return redirect()->route('candidates.show', $candidate->id)
            ->with('status', "Stored private document {$doc->original_filename} (SHA-256: ".substr($doc->sha256_checksum, 0, 12).'...).');
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
        $application = Application::withoutTenantScope()
            ->with(['candidate.documents', 'jobPosting', 'requisition', 'stageEvents.actor'])
            ->findOrFail($applicationId);

        $context->assertModelBelongsToActiveTenant($application, 'Application');

        return view('applications.show', [
            'application' => $application,
        ]);
    }

    public function storeApplication(
        Request $request,
        CandidateAndApplicationService $service,
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

        return redirect()->route('applications.show', $app->id)
            ->with('status', "Created application {$app->application_number} in stage {$app->current_stage}.");
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
