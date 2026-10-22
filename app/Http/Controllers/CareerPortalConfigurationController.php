<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CareerPortalAsset;
use App\Models\CareerPortalConfiguration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CareerPortalConfigurationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class CareerPortalConfigurationController extends Controller
{
    public const VALID_SECTIONS = [
        'overview',
        'brand_theme',
        'header_navigation',
        'hero_search',
        'value_propositions',
        'job_categories',
        'featured_jobs',
        'life_at_tringers',
        'employee_stories',
        'final_cta',
        'footer',
        'seo',
        'accessibility',
        'analytics_consent',
        'preview_history',
    ];

    public function __construct(
        private readonly CareerPortalConfigurationService $portalService,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request, ?string $section = null): View
    {
        $actor = $this->requireConfigurePermission('view_workspace');

        $activeSection = $section ?: $request->string('section', 'overview')->toString();
        if (! in_array($activeSection, self::VALID_SECTIONS, true)) {
            $activeSection = 'overview';
        }

        $scopeTenantId = $request->integer('tenant_id') ?: null;
        $scopeTenant = $scopeTenantId ? Tenant::findOrFail($scopeTenantId) : null;

        $config = $scopeTenant
            ? $this->portalService->ensureTenantConfiguration($scopeTenant, $actor)
            : $this->portalService->ensureGlobalConfiguration($actor);

        $draftPayload = $this->portalService->mergeWithDefaults((array) ($config->draft_payload ?? []));
        $publishedPayload = $this->portalService->mergeWithDefaults((array) ($config->published_payload ?? []));
        $validation = $this->portalService->validateDraftPayload($draftPayload, $scopeTenant);

        $versions = $config->versions()->with('creator:id,name,email')->limit(25)->get();
        $publicationEvents = $config->publicationEvents()->limit(30)->get();
        $assets = CareerPortalAsset::query()->orderByDesc('id')->get();
        $eligibleJobs = $this->portalService->baseEligibleJobsQuery($scopeTenant)
            ->with(['tenant:id,name,slug', 'department:id,name,code', 'location:id,name,city,country_code'])
            ->orderByDesc('published_at')
            ->get();
        $tenants = Tenant::query()->where('status', 'ACTIVE')->orderBy('name')->get();

        return view('platform_admin.career_portal_configuration', [
            'activeSection' => $activeSection,
            'config' => $config,
            'scopeTenant' => $scopeTenant,
            'draftPayload' => $draftPayload,
            'publishedPayload' => $publishedPayload,
            'validation' => $validation,
            'versions' => $versions,
            'publicationEvents' => $publicationEvents,
            'assets' => $assets,
            'eligibleJobs' => $eligibleJobs,
            'tenants' => $tenants,
            'approvedFonts' => CareerPortalConfigurationService::APPROVED_FONTS,
            'approvedIcons' => CareerPortalConfigurationService::APPROVED_ICONS,
            'canPublish' => $actor->canPublishCareerPortal(),
        ]);
    }

    public function saveDraft(Request $request): RedirectResponse
    {
        $actor = $this->requireConfigurePermission('save_draft');
        $config = $this->resolveTargetConfig($request, $actor);

        $activeSection = $request->string('active_section', 'overview')->toString();
        $expectedDraftVersion = $request->filled('expected_draft_version')
            ? (int) $request->input('expected_draft_version')
            : null;
        $changeSummary = $request->string('change_summary', 'Saved draft changes in section '.$activeSection)->toString();
        $payloadUpdates = (array) $request->input('payload', []);

        try {
            $updated = $this->portalService->saveDraft(
                config: $config,
                sectionUpdates: $payloadUpdates,
                actor: $actor,
                expectedDraftVersion: $expectedDraftVersion,
                changeSummary: $changeSummary,
            );
        } catch (RuntimeException $e) {
            return redirect()
                ->route('platform-admin.career-portal.index', array_filter([
                    'section' => $activeSection,
                    'tenant_id' => $config->tenant_id,
                ]))
                ->withErrors(['concurrency' => $e->getMessage()]);
        }

        return redirect()
            ->route('platform-admin.career-portal.index', array_filter([
                'section' => $activeSection,
                'tenant_id' => $config->tenant_id,
            ]))
            ->with('status', "Saved Career Portal draft v{$updated->draft_version} (validation={$updated->validation_status}). Live public portal remains on published v{$updated->published_version_number}.");
    }

    public function validateDraft(Request $request): RedirectResponse
    {
        $actor = $this->requireConfigurePermission('validate_draft');
        $config = $this->resolveTargetConfig($request, $actor);

        $draftPayload = $this->portalService->mergeWithDefaults((array) ($config->draft_payload ?? []));
        $validation = $this->portalService->validateDraftPayload($draftPayload, $config->tenant);

        $config->forceFill([
            'validation_status' => $validation['valid'] ? 'VALID' : 'INVALID',
            'validation_errors' => $validation['errors'],
            'last_validated_at' => now(),
        ])->save();

        if (! $validation['valid']) {
            return redirect()
                ->route('platform-admin.career-portal.index', array_filter([
                    'section' => 'preview_history',
                    'tenant_id' => $config->tenant_id,
                ]))
                ->withErrors($validation['errors']);
        }

        return redirect()
            ->route('platform-admin.career-portal.index', array_filter([
                'section' => 'preview_history',
                'tenant_id' => $config->tenant_id,
            ]))
            ->with('status', "Draft v{$config->draft_version} passed all structural, URL, WCAG 2.2 AA contrast, job eligibility, and content governance checks.");
    }

    public function resetDefault(Request $request): RedirectResponse
    {
        $actor = $this->requireConfigurePermission('reset_default');
        $config = $this->resolveTargetConfig($request, $actor);

        $expectedDraftVersion = $request->filled('expected_draft_version')
            ? (int) $request->input('expected_draft_version')
            : null;

        try {
            $updated = $this->portalService->resetDraftToDefault($config, $actor, $expectedDraftVersion);
        } catch (RuntimeException $e) {
            return redirect()
                ->route('platform-admin.career-portal.index', array_filter([
                    'section' => 'brand_theme',
                    'tenant_id' => $config->tenant_id,
                ]))
                ->withErrors(['concurrency' => $e->getMessage()]);
        }

        return redirect()
            ->route('platform-admin.career-portal.index', array_filter([
                'section' => 'brand_theme',
                'tenant_id' => $config->tenant_id,
            ]))
            ->with('status', "Reset draft v{$updated->draft_version} to the approved Tringers Default Theme. Live published portal (v{$updated->published_version_number}) is unchanged until you publish.");
    }

    public function generatePreview(Request $request): RedirectResponse
    {
        $actor = $this->requireConfigurePermission('generate_preview');
        $config = $this->resolveTargetConfig($request, $actor);

        $token = $this->portalService->generatePreviewToken($config, $actor, 30);
        $previewTenantSlug = trim($request->string('preview_tenant_slug', '')->toString());

        if ($previewTenantSlug !== '') {
            $tenant = Tenant::where('slug', $previewTenantSlug)->where('status', 'ACTIVE')->firstOrFail();

            return redirect()->to(route('tenant.careers.index', [
                'tenantSlug' => $tenant->slug,
                'preview_token' => $token,
                'preview_config_id' => $config->id,
            ]));
        }

        if ($config->tenant) {
            return redirect()->to(route('tenant.careers.index', [
                'tenantSlug' => $config->tenant->slug,
                'preview_token' => $token,
                'preview_config_id' => $config->id,
            ]));
        }

        return redirect()->to(route('careers.index', [
            'preview_token' => $token,
            'preview_config_id' => $config->id,
        ]));
    }

    public function publish(Request $request): RedirectResponse
    {
        $actor = $this->requirePublishPermission('publish_draft');
        $config = $this->resolveTargetConfig($request, $actor);

        $validated = $request->validate([
            'change_summary' => ['required', 'string', 'min:5', 'max:450'],
            'expected_draft_version' => ['nullable', 'integer'],
        ]);

        try {
            $version = $this->portalService->publishDraft(
                config: $config,
                actor: $actor,
                expectedDraftVersion: isset($validated['expected_draft_version']) ? (int) $validated['expected_draft_version'] : null,
                changeSummary: $validated['change_summary'],
            );
        } catch (ValidationException $e) {
            return redirect()
                ->route('platform-admin.career-portal.index', array_filter([
                    'section' => 'preview_history',
                    'tenant_id' => $config->tenant_id,
                ]))
                ->withErrors($e->errors());
        } catch (RuntimeException $e) {
            return redirect()
                ->route('platform-admin.career-portal.index', array_filter([
                    'section' => 'preview_history',
                    'tenant_id' => $config->tenant_id,
                ]))
                ->withErrors(['concurrency' => $e->getMessage()]);
        }

        return redirect()
            ->route('platform-admin.career-portal.index', array_filter([
                'section' => 'preview_history',
                'tenant_id' => $config->tenant_id,
            ]))
            ->with('status', "Published Career Portal Configuration v{$version->version_number} atomically and invalidated public portal caches.");
    }

    public function rollback(Request $request, int $versionNumber): RedirectResponse
    {
        $actor = $this->requirePublishPermission('rollback_version');
        $config = $this->resolveTargetConfig($request, $actor);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:450'],
        ]);

        $newVersion = $this->portalService->rollbackToVersion(
            config: $config,
            targetVersionNumber: $versionNumber,
            actor: $actor,
            reason: $validated['reason'],
        );

        return redirect()
            ->route('platform-admin.career-portal.index', array_filter([
                'section' => 'preview_history',
                'tenant_id' => $config->tenant_id,
            ]))
            ->with('status', "Restored immutable v{$versionNumber} as new published version v{$newVersion->version_number} and invalidated public portal caches.");
    }

    public function uploadAsset(Request $request): RedirectResponse
    {
        $actor = $this->requireConfigurePermission('upload_asset');

        $validated = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'alt_text' => ['required', 'string', 'min:5', 'max:240'],
            'focal_point_x' => ['nullable', 'integer', 'min:0', 'max:100'],
            'focal_point_y' => ['nullable', 'integer', 'min:0', 'max:100'],
            'asset_file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $asset = $this->portalService->storeUploadedAsset(
            file: $request->file('asset_file'),
            title: $validated['title'],
            altText: $validated['alt_text'],
            focalPointX: (int) ($validated['focal_point_x'] ?? 50),
            focalPointY: (int) ($validated['focal_point_y'] ?? 50),
            actor: $actor,
        );

        return redirect()
            ->route('platform-admin.career-portal.index', ['section' => 'brand_theme'])
            ->with('status', "Uploaded approved public media asset '{$asset->title}' ({$asset->public_path}).");
    }

    private function resolveTargetConfig(Request $request, User $actor): CareerPortalConfiguration
    {
        $tenantId = $request->integer('tenant_id') ?: null;
        if ($tenantId !== null) {
            $tenant = Tenant::findOrFail($tenantId);

            return $this->portalService->ensureTenantConfiguration($tenant, $actor);
        }

        return $this->portalService->ensureGlobalConfiguration($actor);
    }

    private function requireConfigurePermission(string $operation): User
    {
        /** @var User|null $actor */
        $actor = Auth::user();
        if (! $actor || ! $actor->canConfigureCareerPortal()) {
            $this->audit->record(
                module: 'career_portal',
                action: 'career_portal.configure_permission_denied',
                outcome: 'DENIED',
                errorCode: 'CAREER_PORTAL_PERMISSION_REQUIRED',
                metadata: ['operation' => $operation],
                actorUserId: $actor?->id,
            );

            abort(403, 'CAREER_PORTAL_PERMISSION_REQUIRED: Explicit Career Portal Configuration authority is required.');
        }

        return $actor;
    }

    private function requirePublishPermission(string $operation): User
    {
        /** @var User|null $actor */
        $actor = Auth::user();
        if (! $actor || ! $actor->canPublishCareerPortal()) {
            $this->audit->record(
                module: 'career_portal',
                action: 'career_portal.publish_permission_denied',
                outcome: 'DENIED',
                errorCode: 'CAREER_PORTAL_PUBLISH_PERMISSION_REQUIRED',
                metadata: ['operation' => $operation],
                actorUserId: $actor?->id,
            );

            abort(403, 'CAREER_PORTAL_PUBLISH_PERMISSION_REQUIRED: Explicit Career Portal Publication authority is required.');
        }

        return $actor;
    }
}
