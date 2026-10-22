<?php

namespace App\Http\Controllers;

use App\Models\JobPosting;
use App\Models\Requisition;
use App\Services\JobPublishingService;
use App\Services\TenantContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class JobPostingController extends Controller
{
    public function index(): View
    {
        return view('jobs.index', [
            'jobs' => JobPosting::with(['requisition', 'department', 'location', 'approver'])->orderByDesc('id')->paginate(15),
            'requisitions' => Requisition::whereIn('status', [Requisition::STATUS_APPROVED, Requisition::STATUS_OPEN])->orderByDesc('id')->get(),
        ]);
    }

    public function show(int $jobId, TenantContextService $context): View
    {
        $job = JobPosting::withoutTenantScope()->with(['requisition', 'department', 'location', 'approver', 'versions.creator'])->findOrFail($jobId);
        $context->assertModelBelongsToActiveTenant($job, 'JobPosting');

        return view('jobs.show', [
            'job' => $job,
        ]);
    }

    public function store(Request $request, JobPublishingService $service): RedirectResponse
    {
        $validated = $request->validate([
            'requisition_id' => ['required', 'integer', 'exists:requisitions,id'],
            'title' => ['required', 'string', 'max:180'],
            'work_mode' => ['required', 'in:ONSITE,HYBRID,REMOTE'],
            'experience_level' => ['required', 'in:ENTRY,MID,SENIOR,LEAD,EXECUTIVE'],
            'summary' => ['required', 'string', 'min:10'],
            'responsibilities' => ['required', 'string', 'min:10'],
            'qualifications' => ['required', 'string', 'min:10'],
            'skills_required' => ['nullable', 'string', 'max:500'],
            'show_compensation_publicly' => ['nullable', 'boolean'],
        ]);

        $job = $service->createJobPosting($validated, Auth::user());

        return redirect()->route('jobs.show', $job->id)
            ->with('status', "Created job description {$job->code} in DRAFT status.");
    }

    public function publish(Request $request, int $jobId, JobPublishingService $service): RedirectResponse
    {
        $job = JobPosting::withoutTenantScope()->findOrFail($jobId);
        $updated = $service->approveAndPublish($job, Auth::user(), $request->boolean('publish_now', true));

        return redirect()->route('jobs.show', $updated->id)
            ->with('status', "Job {$updated->code} is now {$updated->status}.");
    }

    public function changeStatus(Request $request, int $jobId, JobPublishingService $service): RedirectResponse
    {
        $job = JobPosting::withoutTenantScope()->findOrFail($jobId);
        $validated = $request->validate([
            'target_status' => ['required', 'in:PUBLISHED,UNPUBLISHED,EXPIRED,CLOSED'],
        ]);

        $updated = $service->transitionPublicationStatus($job, $validated['target_status'], Auth::user());

        return redirect()->route('jobs.show', $updated->id)
            ->with('status', "Updated job {$updated->code} status to {$updated->status}.");
    }
}
