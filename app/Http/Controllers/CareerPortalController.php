<?php

namespace App\Http\Controllers;

use App\Models\JobPosting;
use App\Models\Tenant;
use App\Services\CandidateAndApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class CareerPortalController extends Controller
{
    public function index(Request $request): View
    {
        $query = JobPosting::withoutTenantScope()
            ->with(['tenant', 'department', 'location'])
            ->whereHas('tenant', fn ($tenantQuery) => $tenantQuery->where('status', 'ACTIVE'))
            ->where('status', JobPosting::STATUS_PUBLISHED)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('published_at');

        $selectedTenant = null;
        if ($request->filled('tenant')) {
            $selectedTenant = Tenant::where('slug', $request->string('tenant')->toString())
                ->where('status', 'ACTIVE')
                ->firstOrFail();
            $query->where('tenant_id', $selectedTenant->id);
        }

        if ($request->filled('work_mode')) {
            $query->where('work_mode', $request->string('work_mode')->toString());
        }

        if ($request->filled('q')) {
            $term = $request->string('q')->toString();
            $query->where(function ($sub) use ($term) {
                $sub->where('title', 'like', "%{$term}%")
                    ->orWhere('summary', 'like', "%{$term}%");
            });
        }

        return view('careers.index', [
            'jobs' => $query->paginate(12)->withQueryString(),
            'tenants' => Tenant::where('status', 'ACTIVE')->orderBy('name')->get(),
            'tenant' => null,
        ]);
    }

    public function tenantIndex(Request $request, string $tenantSlug): View
    {
        $tenant = Tenant::where('slug', $tenantSlug)->where('status', 'ACTIVE')->firstOrFail();
        // The path is authoritative; a query-string tenant must not switch the portal context.
        $request->query->set('tenant', $tenant->slug);
        $request->merge(['tenant' => $tenant->slug]);

        return $this->index($request)->with('tenant', $tenant);
    }

    public function showJob(string $tenantSlug, string $jobSlug): View
    {
        $tenant = Tenant::where('slug', $tenantSlug)->where('status', 'ACTIVE')->firstOrFail();

        $job = JobPosting::withoutTenantScope()
            ->with(['tenant', 'department', 'location'])
            ->where('tenant_id', $tenant->id)
            ->where('slug', $jobSlug)
            ->where('status', JobPosting::STATUS_PUBLISHED)
            ->firstOrFail();

        if ($job->expires_at && $job->expires_at->isPast()) {
            abort(404, 'This job posting has expired.');
        }

        return view('careers.show', [
            'tenant' => $tenant,
            'job' => $job,
            'jsonLd' => $job->toJobPostingSchemaJsonLd(),
        ]);
    }

    public function submitPublicApplication(
        Request $request,
        string $tenantSlug,
        string $jobSlug,
        CandidateAndApplicationService $service
    ): RedirectResponse {
        $tenant = Tenant::where('slug', $tenantSlug)->where('status', 'ACTIVE')->firstOrFail();

        $job = JobPosting::withoutTenantScope()
            ->with('requisition')
            ->where('tenant_id', $tenant->id)
            ->where('slug', $jobSlug)
            ->where('status', JobPosting::STATUS_PUBLISHED)
            ->firstOrFail();

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
            'cover_note' => ['nullable', 'string', 'max:2000'],
            'consent_given' => ['accepted'],
            'resume_file' => ['nullable', 'file', 'max:5120'],
        ]);

        $candidate = $service->upsertCandidateWithConsent([
            ...$validated,
            'consent_given' => true,
            'source_channel' => 'CAREER_PORTAL',
        ], $tenant->id);

        $application = $service->submitApplication($job, $candidate, [
            'cover_note' => $validated['cover_note'] ?? null,
        ]);

        if ($request->hasFile('resume_file')) {
            $service->storePrivateDocument(
                $candidate,
                $request->file('resume_file'),
                'RESUME',
                $application->id
            );
        }

        return redirect()->route('careers.show', ['tenantSlug' => $tenant->slug, 'jobSlug' => $job->slug])
            ->with('status', "Application {$application->application_number} received for {$job->title}. Status: {$application->candidate_visible_status}.");
    }

    public function sitemap(): Response
    {
        $jobs = JobPosting::withoutTenantScope()
            ->with('tenant')
            ->whereHas('tenant', fn ($tenantQuery) => $tenantQuery->where('status', 'ACTIVE'))
            ->where('status', JobPosting::STATUS_PUBLISHED)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('published_at')
            ->get();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        $xml .= '  <url><loc>'.e(route('careers.index')).'</loc></url>'."\n";

        foreach ($jobs as $job) {
            if ($job->tenant) {
                $url = route('careers.show', [$job->tenant->slug, $job->slug]);
                $lastmod = ($job->updated_at ?? $job->published_at ?? now())->toAtomString();
                $xml .= '  <url><loc>'.e($url).'</loc><lastmod>'.e($lastmod).'</lastmod></url>'."\n";
            }
        }
        $xml .= '</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(): Response
    {
        $content = "User-agent: *\nAllow: /\nAllow: /*/jobs/\nDisallow: /platformadmin\nDisallow: /admin\nDisallow: /candidates\nDisallow: /applications\nDisallow: /offers\nDisallow: /hr-handover\nSitemap: ".route('careers.sitemap')."\n";

        return response($content, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
