<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CareerPortalConfiguration;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CandidateAndApplicationService;
use App\Services\CareerPortalConfigurationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class CareerPortalController extends Controller
{
    public function __construct(
        private readonly CareerPortalConfigurationService $portalService,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request, ?Tenant $scopedTenant = null): Response
    {
        [$isPreview, $previewConfig] = $this->resolvePreviewContext($request);

        $portalConfig = $this->portalService->getResolvedPublicPayload(
            tenant: $scopedTenant,
            useDraftPreview: $isPreview,
            previewConfig: $previewConfig,
        );

        if ($scopedTenant !== null) {
            $currentSubtitle = (string) ($portalConfig['featured_jobs']['subtitle'] ?? '');
            if ($currentSubtitle === '' || $currentSubtitle === 'Explore Approved Open Positions') {
                $portalConfig['featured_jobs']['subtitle'] = 'Explore Open Positions at '.$scopedTenant->name;
            }
        }

        $query = $this->portalService->baseEligibleJobsQuery($scopedTenant)
            ->with(['tenant', 'department', 'location'])
            ->orderByDesc('published_at');

        $selectedTenant = $scopedTenant;
        if ($scopedTenant === null && $request->filled('tenant')) {
            $selectedTenant = Tenant::where('slug', $request->string('tenant')->toString())
                ->where('status', 'ACTIVE')
                ->firstOrFail();
            $query->where('tenant_id', $selectedTenant->id);
        }

        if ($request->filled('work_mode')) {
            $query->where('work_mode', strtoupper($request->string('work_mode')->toString()));
        }

        if ($request->filled('employment_type')) {
            $query->where('employment_type', strtoupper($request->string('employment_type')->toString()));
        }

        if ($request->filled('location')) {
            $locTerm = trim($request->string('location')->toString());
            if ($locTerm !== '') {
                $query->whereHas('location', function ($locQuery) use ($locTerm) {
                    $locQuery->where('city', 'like', "%{$locTerm}%")
                        ->orWhere('name', 'like', "%{$locTerm}%")
                        ->orWhere('country_code', 'like', "%{$locTerm}%");
                });
            }
        }

        $activeCategorySlug = trim($request->string('category', '')->toString());
        if ($activeCategorySlug !== '') {
            $categoriesCfg = collect((array) ($portalConfig['job_categories']['items'] ?? $portalConfig['job_categories']['categories'] ?? []));
            $matchedCat = $categoriesCfg->first(fn ($c) => is_array($c) && strtolower((string) ($c['slug'] ?? '')) === strtolower($activeCategorySlug));

            $deptCodes = is_array($matchedCat) ? array_map('strtoupper', (array) ($matchedCat['department_codes'] ?? [])) : [];
            $keywords = is_array($matchedCat) ? (array) ($matchedCat['keywords'] ?? []) : [];

            $query->where(function ($catQuery) use ($activeCategorySlug, $deptCodes, $keywords) {
                if (Schema::hasColumn('job_postings', 'category_slug')) {
                    $catQuery->where('category_slug', $activeCategorySlug);
                }
                if (! empty($deptCodes)) {
                    $catQuery->orWhereHas('department', fn ($dq) => $dq->whereIn('code', $deptCodes));
                }
                foreach ($keywords as $kw) {
                    if (is_string($kw) && trim($kw) !== '') {
                        $catQuery->orWhere('title', 'like', '%'.trim($kw).'%');
                    }
                }
            });
        }

        if ($request->filled('q')) {
            $term = trim($request->string('q')->toString());
            if ($term !== '') {
                $query->where(function ($sub) use ($term) {
                    $sub->where('title', 'like', "%{$term}%")
                        ->orWhere('summary', 'like', "%{$term}%")
                        ->orWhere('code', 'like', "%{$term}%")
                        ->orWhereHas('location', fn ($lq) => $lq->where('city', 'like', "%{$term}%")->orWhere('country_code', 'like', "%{$term}%"))
                        ->orWhereHas('department', fn ($dq) => $dq->where('name', 'like', "%{$term}%"));
                });
            }
        }

        $jobs = $query->paginate(12)->withQueryString();
        $categoryCards = $this->portalService->computeCategoryCardsWithCounts($portalConfig, $scopedTenant);
        $featuredJobs = $this->portalService->resolveFeaturedJobs($portalConfig, $scopedTenant);

        $verifiedMetrics = collect((array) ($portalConfig['life_at_tringers']['renderable_metrics'] ?? $portalConfig['life_at_tringers']['metrics'] ?? []))
            ->filter(fn ($m) => is_array($m) && (! empty($m['value']) && ! empty($m['label'])))
            ->values();

        if ($verifiedMetrics->isEmpty()) {
            $liveJobCount = $this->portalService->baseEligibleJobsQuery($scopedTenant)->count();
            $liveOrgCount = $scopedTenant ? 1 : Tenant::where('status', 'ACTIVE')->count();
            $verifiedMetrics = collect([
                ['id' => 'live-jobs', 'value' => (string) $liveJobCount, 'label' => 'Verified Open Roles', 'approved' => true, 'verified' => true],
                ['id' => 'live-orgs', 'value' => (string) $liveOrgCount, 'label' => 'Active Hiring Organizations', 'approved' => true, 'verified' => true],
                ['id' => 'live-cats', 'value' => (string) count($categoryCards), 'label' => 'Career Practice Areas', 'approved' => true, 'verified' => true],
            ]);
        }

        $approvedStories = collect((array) ($portalConfig['employee_stories']['renderable_stories'] ?? $portalConfig['employee_stories']['stories'] ?? $portalConfig['employee_stories']['items'] ?? []))
            ->filter(fn ($s) => is_array($s) && ! empty($s['name']) && ! empty($s['quote']))
            ->values();

        $locations = Schema::hasTable('locations')
            ? Location::withoutTenantScope()
                ->when($scopedTenant !== null, fn ($q) => $q->where('tenant_id', $scopedTenant->id))
                ->where('is_active', true)
                ->orderBy('city')
                ->get()
            : collect();

        $canonicalUrl = $scopedTenant
            ? route('tenant.careers.index', ['tenantSlug' => $scopedTenant->slug])
            : route('careers.index');
        $robotsDirective = $isPreview
            ? 'noindex, nofollow'
            : (string) ($portalConfig['seo']['robots_policy'] ?? 'index, follow');

        $html = view('careers.index', [
            'jobs' => $jobs,
            'featuredJobs' => $featuredJobs,
            'categoryCards' => $categoryCards,
            'categoriesWithCounts' => $categoryCards,
            'verifiedMetrics' => $verifiedMetrics,
            'approvedStories' => $approvedStories,
            'locations' => $locations,
            'tenants' => Tenant::where('status', 'ACTIVE')->orderBy('name')->get(),
            'tenant' => $scopedTenant,
            'scopeTenant' => $scopedTenant,
            'portalConfig' => $portalConfig,
            'isPreview' => $isPreview,
            'activeCategorySlug' => $activeCategorySlug,
            'workModes' => ['REMOTE', 'HYBRID', 'ONSITE'],
            'employmentTypes' => ['FULL_TIME', 'PART_TIME', 'CONTRACT', 'INTERNSHIP'],
            'filters' => [
                'q' => $request->string('q')->toString(),
                'category' => $activeCategorySlug,
                'tenant' => $selectedTenant?->slug ?? '',
                'work_mode' => strtoupper($request->string('work_mode')->toString()),
                'employment_type' => strtoupper($request->string('employment_type')->toString()),
                'location' => $request->string('location')->toString(),
            ],
            'canonicalUrl' => $canonicalUrl,
            'robotsDirective' => $robotsDirective,
        ])->render();

        $response = response($html, 200);

        if ($isPreview) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    public function tenantIndex(Request $request, string $tenantSlug): Response
    {
        $tenant = Tenant::where('slug', $tenantSlug)->where('status', 'ACTIVE')->firstOrFail();
        // The path is authoritative; a query-string tenant must not switch the portal context.
        $request->query->set('tenant', $tenant->slug);
        $request->merge(['tenant' => $tenant->slug]);

        return $this->index($request, $tenant);
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

        $portalConfig = $this->portalService->getResolvedPublicPayload($tenant);

        return view('careers.show', [
            'tenant' => $tenant,
            'scopeTenant' => $tenant,
            'job' => $job,
            'portalConfig' => $portalConfig,
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

        if ($job->expires_at && $job->expires_at->isPast()) {
            abort(404, 'This job posting has expired.');
        }

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

    public function legalPage(string $page): View
    {
        $aliases = [
            'privacy' => 'privacy-policy',
            'terms' => 'terms-of-use',
            'cookies' => 'cookie-policy',
        ];
        $page = $aliases[$page] ?? $page;

        $pages = [
            'privacy-policy' => [
                'title' => 'Privacy Policy',
                'heading' => 'Candidate & Career Portal Privacy Policy',
                'version' => 'PRIVACY-v2.0',
                'summary' => 'How Tringers and participating organizations collect, govern, retain, and protect candidate personal data.',
            ],
            'terms-of-use' => [
                'title' => 'Terms of Use',
                'heading' => 'Career Portal Terms of Use',
                'version' => 'TERMS-v1.0',
                'summary' => 'Governing terms for browsing open positions and submitting applications through the Tringers Career Portal.',
            ],
            'cookie-policy' => [
                'title' => 'Cookie Policy',
                'heading' => 'Cookie & Consent Governance Policy',
                'version' => 'COOKIES-v1.0',
                'summary' => 'Strictly necessary session cookies and optional privacy-preserving portal analytics controls.',
            ],
        ];

        if (! isset($pages[$page])) {
            abort(404);
        }

        $portalConfig = $this->portalService->getResolvedPublicPayload();

        return view('careers.legal', [
            'pageKey' => $page,
            'pageSlug' => $page,
            'pageMeta' => $pages[$page],
            'title' => $pages[$page]['heading'],
            'subtitle' => $pages[$page]['summary'],
            'canonicalUrl' => route('careers.legal', ['page' => $page]),
            'portalConfig' => $portalConfig,
        ]);
    }

    public function saveCookieConsent(Request $request): RedirectResponse
    {
        $choice = $request->string('consent_choice', 'essential_only')->toString();
        if (! in_array($choice, ['essential_only', 'accepted', 'analytics_accepted'], true)) {
            $choice = 'essential_only';
        }

        return back()
            ->withCookie(cookie('tringers_cookie_consent', $choice, 60 * 24 * 180, '/', null, true, true, false, 'Lax'))
            ->with('status', in_array($choice, ['accepted', 'analytics_accepted'], true)
                ? 'Cookie preferences saved (essential + privacy-preserving analytics).'
                : 'Cookie preferences saved (strictly necessary cookies only).');
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

        foreach (Tenant::where('status', 'ACTIVE')->orderBy('slug')->get() as $activeTenant) {
            $xml .= '  <url><loc>'.e(route('tenant.careers.index', ['tenantSlug' => $activeTenant->slug])).'</loc></url>'."\n";
        }

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
        $content = "User-agent: *\nAllow: /\nAllow: /*/jobs/\nDisallow: /platformadmin\nDisallow: /organization\nDisallow: /admin\nDisallow: /candidates\nDisallow: /applications\nDisallow: /offers\nDisallow: /hr-handover\nSitemap: ".route('careers.sitemap')."\n";

        return response($content, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * @return array{0: bool, 1: ?CareerPortalConfiguration}
     */
    private function resolvePreviewContext(Request $request): array
    {
        if (! $request->filled('preview_token')) {
            return [false, null];
        }

        /** @var User|null $user */
        $user = Auth::user();
        if (! $user || ! $user->canConfigureCareerPortal()) {
            $this->audit->record(
                module: 'career_portal',
                action: 'career_portal.preview_denied_unauthorized',
                outcome: 'DENIED',
                errorCode: 'PREVIEW_ACCESS_DENIED',
                actorUserId: $user?->id,
            );
            abort(403, 'PREVIEW_ACCESS_DENIED: Authorized Career Portal Configuration access is required to view draft previews.');
        }

        $configId = $request->integer('preview_config_id');
        $config = $configId
            ? CareerPortalConfiguration::find($configId)
            : $this->portalService->ensureGlobalConfiguration($user);

        if (! $config || ! $this->portalService->validatePreviewToken($config, $request->string('preview_token')->toString())) {
            $this->audit->record(
                module: 'career_portal',
                action: 'career_portal.preview_denied_invalid_token',
                outcome: 'DENIED',
                errorCode: 'PREVIEW_TOKEN_INVALID_OR_EXPIRED',
                actorUserId: $user->id,
            );
            abort(403, 'PREVIEW_TOKEN_INVALID_OR_EXPIRED: The draft preview token is invalid or has expired.');
        }

        return [true, $config];
    }
}
