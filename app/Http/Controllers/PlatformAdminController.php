<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiProviderConfig;
use App\Models\AiTaskRun;
use App\Models\ApiClient;
use App\Models\AuditEvent;
use App\Models\DynamicsIntegrationConfig;
use App\Models\ExternalIdentityConnection;
use App\Models\Tenant;
use App\Models\TransactionalOutboxEvent;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\AuditService;
use App\Services\EntraSsoService;
use App\Services\PlatformAdminService;
use App\Services\PlatformConfigService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class PlatformAdminController extends Controller
{
    public const VALID_TABS = [
        'overview',
        'branding',
        'tenants',
        'admins',
        'auth-sso',
        'ai-governance',
        'api-mcp',
        'integrations',
        'security',
        'audit',
        'readiness',
    ];

    public function __construct(
        private readonly PlatformAdminService $adminService,
        private readonly PlatformConfigService $configService,
        private readonly AuditService $auditService,
        private readonly EntraSsoService $entraSso,
    ) {}

    public function index(Request $request, ?string $section = null): View
    {
        $activeTab = $section ?: $request->string('tab', 'overview')->toString();
        if (! in_array($activeTab, self::VALID_TABS, true)) {
            $activeTab = 'overview';
        }

        $superAdminReport = $this->adminService->inspectSuperAdminStatus();
        $allSettings = $this->configService->getAllRegistryWithValues();
        $envCatalogue = $this->configService->getSanitizedEnvironmentCatalogue();

        $tenants = Tenant::query()
            ->withCount('memberships')
            ->orderBy('id')
            ->get();

        $ssoConnections = ExternalIdentityConnection::withoutGlobalScopes()
            ->with('tenant:id,name,slug')
            ->orderByDesc('id')
            ->get();

        $aiProviderConfigs = AiProviderConfig::withoutGlobalScopes()
            ->orderBy('tenant_id')
            ->orderBy('provider_key')
            ->get();

        $aiUsageSummary = [
            'total_runs' => Schema::hasTable('ai_task_runs') ? AiTaskRun::withoutGlobalScopes()->count() : 0,
            'human_reviewed_runs' => Schema::hasTable('ai_task_runs') ? AiTaskRun::withoutGlobalScopes()->where('human_reviewed', true)->count() : 0,
            'total_spend_minor' => Schema::hasTable('ai_provider_configs') ? (int) AiProviderConfig::withoutGlobalScopes()->sum('current_spend_minor') : 0,
        ];

        $apiClients = ApiClient::withoutGlobalScopes()
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'tenant_id', 'name', 'client_id', 'scopes', 'is_active', 'last_used_at', 'created_at']);

        $webhookSubscriptions = WebhookSubscription::withoutGlobalScopes()
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'tenant_id', 'name', 'target_url', 'subscribed_events', 'is_active', 'created_at']);

        $webhookDeliverySummary = [
            'pending' => Schema::hasTable('webhook_deliveries') ? WebhookDelivery::withoutGlobalScopes()->where('status', 'PENDING')->count() : 0,
            'delivered' => Schema::hasTable('webhook_deliveries') ? WebhookDelivery::withoutGlobalScopes()->where('status', 'DELIVERED')->count() : 0,
            'failed_retryable' => Schema::hasTable('webhook_deliveries') ? WebhookDelivery::withoutGlobalScopes()->where('status', 'FAILED_RETRYABLE')->count() : 0,
            'failed_permanent' => Schema::hasTable('webhook_deliveries') ? WebhookDelivery::withoutGlobalScopes()->where('status', 'FAILED_PERMANENT')->count() : 0,
        ];

        $dynamicsConfigs = DynamicsIntegrationConfig::withoutGlobalScopes()
            ->orderBy('tenant_id')
            ->get(['id', 'tenant_id', 'environment_url', 'connection_mode', 'real_employee_creation_enabled', 'last_health_status', 'last_health_check_at']);

        $outboxPendingCount = Schema::hasTable('transactional_outbox_events')
            ? TransactionalOutboxEvent::withoutGlobalScopes()->where('status', 'PENDING')->count()
            : 0;

        // Audit filtering
        $auditQuery = AuditEvent::query()->orderByDesc('id');
        if ($request->filled('audit_module')) {
            $auditQuery->where('module', $request->string('audit_module')->toString());
        }
        if ($request->filled('audit_outcome')) {
            $auditQuery->where('outcome', $request->string('audit_outcome')->toString());
        }
        if ($request->filled('audit_q')) {
            $q = $request->string('audit_q')->toString();
            $auditQuery->where(function ($sub) use ($q) {
                $sub->where('action', 'like', "%{$q}%")
                    ->orWhere('actor_identifier', 'like', "%{$q}%")
                    ->orWhere('correlation_id', 'like', "%{$q}%")
                    ->orWhere('error_code', 'like', "%{$q}%");
            });
        }
        $auditEvents = $auditQuery->paginate(20)->withQueryString();

        $readinessChecks = $this->runReadinessDiagnostics($superAdminReport);

        return view('platform_admin.index', [
            'activeTab' => $activeTab,
            'superAdminReport' => $superAdminReport,
            'allSettings' => $allSettings,
            'brandingSettings' => $this->configService->getSectionMetadataAndValues('branding'),
            'aiGovernanceSettings' => $this->configService->getSectionMetadataAndValues('ai_governance'),
            'apiMcpSettings' => $this->configService->getSectionMetadataAndValues('api_mcp'),
            'integrationsSettings' => $this->configService->getSectionMetadataAndValues('integrations'),
            'securitySettings' => $this->configService->getSectionMetadataAndValues('security'),
            'envCatalogue' => $envCatalogue,
            'tenants' => $tenants,
            'tenantMap' => $tenants->keyBy('id'),
            'ssoConnections' => $ssoConnections,
            'entraSsoEnabled' => $this->entraSso->isEnabled(),
            'aiProviderConfigs' => $aiProviderConfigs,
            'aiUsageSummary' => $aiUsageSummary,
            'apiClients' => $apiClients,
            'webhookSubscriptions' => $webhookSubscriptions,
            'webhookDeliverySummary' => $webhookDeliverySummary,
            'dynamicsConfigs' => $dynamicsConfigs,
            'outboxPendingCount' => $outboxPendingCount,
            'auditEvents' => $auditEvents,
            'readinessChecks' => $readinessChecks,
            'phpVersion' => PHP_VERSION,
            'laravelVersion' => app()->version(),
        ]);
    }

    public function updateSection(Request $request, string $section): RedirectResponse
    {
        $allowedSections = [
            'branding' => 'branding',
            'ai_governance' => 'ai-governance',
            'api_mcp' => 'api-mcp',
            'integrations' => 'integrations',
            'security' => 'security',
        ];

        if (! array_key_exists($section, $allowedSections)) {
            abort(404, 'Unknown configuration section.');
        }

        /** @var User $actor */
        $actor = Auth::user();
        $rawSettings = (array) $request->input('settings', []);
        $reason = $request->input('reason');
        $expectedVersions = (array) $request->input('expected_versions', []);

        $changes = $this->configService->updateSectionSettings(
            section: $section,
            rawInputs: $rawSettings,
            actor: $actor,
            reason: is_string($reason) ? $reason : null,
            expectedVersions: array_map('intval', $expectedVersions),
        );

        $count = count($changes);

        return redirect()
            ->route('platform-admin.index', ['tab' => $allowedSections[$section]])
            ->with('status', "Saved platform configuration section '{$section}' ({$count} setting(s) updated).");
    }

    public function storeTenant(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = Auth::user();

        // Canonical lowercase slug becomes the permanent tenant career portal path.
        $request->merge(['slug' => strtolower(trim((string) $request->input('slug')))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'slug' => ['required', 'string', 'min:3', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'not_in:login,logout,platformadmin,platform-admin,organization,dashboard,careers,health,setup,system,admin,integrations,api,auth,mcp,tenant,sitemap,robots,requisitions,jobs,candidates,documents,applications,evaluations,analytics,offers,hr-handover,ai,webhooks', 'unique:tenants,slug'],
            'primary_domain' => ['nullable', 'string', 'max:190', 'unique:tenants,primary_domain'],
            'default_timezone' => ['nullable', 'timezone:all'],
            'default_currency' => ['nullable', 'string', 'in:USD,GBP,EUR,AED,INR'],
            'retention_days' => ['nullable', 'integer', 'min:30', 'max:3650'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $tenant = $this->adminService->createTenant($validated, $actor);

        return redirect()
            ->route('platform-admin.index', ['tab' => 'tenants'])
            ->with('status', "Provisioned enterprise tenant '{$tenant->name}' ({$tenant->slug}).");
    }

    public function updateTenantLifecycle(Request $request, Tenant $tenant): RedirectResponse
    {
        /** @var User $actor */
        $actor = Auth::user();

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:ACTIVE,SUSPENDED'],
            'ai_kill_switch' => ['nullable', 'boolean'],
            'mcp_enabled' => ['nullable', 'boolean'],
            'sso_required' => ['nullable', 'boolean'],
            'retention_days' => ['required', 'integer', 'min:30', 'max:3650'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $validated['ai_kill_switch'] = $request->boolean('ai_kill_switch');
        $validated['mcp_enabled'] = $request->boolean('mcp_enabled');
        $validated['sso_required'] = $request->boolean('sso_required');

        $updated = $this->adminService->updateTenantLifecycle($tenant, $validated, $actor);

        return redirect()
            ->route('platform-admin.index', ['tab' => 'tenants'])
            ->with('status', "Updated lifecycle & governance controls for tenant '{$updated->name}' (status={$updated->status}).");
    }

    public function promoteAdmin(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = Auth::user();

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'current_password' => ['nullable', 'string'],
        ]);

        $this->assertStepUpPasswordIfRequired($actor, (string) ($validated['current_password'] ?? ''), 'promote_admin');

        try {
            $result = $this->adminService->provisionSuperAdmin(
                email: $validated['email'],
                name: $validated['name'],
                plainPassword: null,
                operatorIdentifier: $actor->email,
                reason: $validated['reason'],
                allowPromoteExisting: true,
                actorUser: $actor,
            );
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'email' => [$e->getMessage()],
            ]);
        }

        return redirect()
            ->route('platform-admin.index', ['tab' => 'admins'])
            ->with('status', "Platform Super Admin promotion result: {$result['action']} ({$result['masked_email']}).");
    }

    public function revokeAdmin(Request $request, User $targetUser): RedirectResponse
    {
        /** @var User $actor */
        $actor = Auth::user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'suspend_account' => ['nullable', 'boolean'],
            'current_password' => ['nullable', 'string'],
        ]);

        $this->assertStepUpPasswordIfRequired($actor, (string) ($validated['current_password'] ?? ''), 'revoke_admin');

        try {
            $result = $this->adminService->revokeSuperAdmin(
                targetUser: $targetUser,
                operatorIdentifier: $actor->email,
                reason: $validated['reason'],
                suspendAccount: $request->boolean('suspend_account'),
                actorUser: $actor,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'revoke' => [$e->getMessage()],
            ]);
        }

        return redirect()
            ->route('platform-admin.index', ['tab' => 'admins'])
            ->with('status', "Revoked Platform Super Admin authority for {$result['masked_email']} (status={$result['status']}).");
    }

    public function updateApiClientStatus(Request $request, int $clientId): RedirectResponse
    {
        /** @var User $actor */
        $actor = Auth::user();

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        /** @var ApiClient $client */
        $client = ApiClient::withoutGlobalScopes()->findOrFail($clientId);
        $updated = $this->adminService->updateApiClientStatus($client, (bool) $validated['is_active'], $validated['reason'], $actor);

        return redirect()
            ->route('platform-admin.index', ['tab' => 'api-mcp'])
            ->with('status', sprintf('API Client %s is now %s.', $updated->client_id, $updated->is_active ? 'ACTIVE' : 'REVOKED'));
    }

    public function updateWebhookStatus(Request $request, int $subscriptionId): RedirectResponse
    {
        /** @var User $actor */
        $actor = Auth::user();

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        /** @var WebhookSubscription $subscription */
        $subscription = WebhookSubscription::withoutGlobalScopes()->findOrFail($subscriptionId);
        $updated = $this->adminService->updateWebhookSubscriptionStatus($subscription, (bool) $validated['is_active'], $validated['reason'], $actor);

        return redirect()
            ->route('platform-admin.index', ['tab' => 'api-mcp'])
            ->with('status', sprintf('Webhook subscription #%d is now %s.', $updated->id, $updated->is_active ? 'ACTIVE' : 'DISABLED'));
    }

    public function rotatePassword(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = Auth::user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:14', 'confirmed'],
        ]);

        if (! Hash::check($validated['current_password'], $actor->password)) {
            $this->auditService->record(
                module: 'platform_admin',
                action: 'platform_superadmin.password_rotation_failed',
                outcome: 'DENIED',
                errorCode: 'STEP_UP_AUTH_FAILED',
                entityType: 'User',
                entityId: (string) $actor->id,
                actorUserId: (int) $actor->id,
            );

            throw ValidationException::withMessages([
                'current_password' => ['STEP_UP_AUTH_FAILED: Current password is incorrect.'],
            ]);
        }

        try {
            $this->adminService->validateStrongPassword($validated['new_password'], $actor->email);
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'new_password' => [$e->getMessage()],
            ]);
        }

        $actor->forceFill([
            'password' => Hash::make($validated['new_password']),
            'must_rotate_password' => false,
            'password_changed_at' => now(),
        ])->save();

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $this->auditService->record(
            module: 'platform_admin',
            action: 'platform_superadmin.password_rotated',
            outcome: 'SUCCESS',
            entityType: 'User',
            entityId: (string) $actor->id,
            actorUserId: (int) $actor->id,
        );

        return redirect()
            ->route('platform-admin.index', ['tab' => 'security'])
            ->with('status', 'Your Platform Super Admin password has been rotated and your session has been regenerated.');
    }

    private function assertStepUpPasswordIfRequired(User $actor, string $currentPassword, string $operation): void
    {
        if (! $this->configService->getBool('security.require_step_up_for_sensitive_changes')) {
            return;
        }

        if ($currentPassword === '' || ! Hash::check($currentPassword, $actor->password)) {
            $this->auditService->record(
                module: 'platform_admin',
                action: 'platform_admin.step_up_auth_failed',
                outcome: 'DENIED',
                errorCode: 'STEP_UP_AUTH_FAILED',
                entityType: 'User',
                entityId: (string) $actor->id,
                metadata: ['operation' => $operation],
                actorUserId: (int) $actor->id,
            );

            throw ValidationException::withMessages([
                'current_password' => ['STEP_UP_AUTH_FAILED: Current password confirmation is required for this privileged operation.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $superAdminReport
     * @return array<string, array<string, mixed>>
     */
    private function runReadinessDiagnostics(array $superAdminReport): array
    {
        $dbHealthy = false;
        try {
            DB::connection()->getPdo();
            $dbHealthy = Schema::hasTable('tenants') && Schema::hasTable('platform_settings');
        } catch (Throwable) {
            $dbHealthy = false;
        }

        $redisHealthy = false;
        try {
            Cache::put('platform_admin_probe', 'ok', 5);
            $redisHealthy = Cache::get('platform_admin_probe') === 'ok';
        } catch (Throwable) {
            $redisHealthy = false;
        }

        $storageHealthy = false;
        try {
            Storage::disk('local')->makeDirectory('private/health');
            $storageHealthy = true;
        } catch (Throwable) {
            $storageHealthy = false;
        }

        return [
            'database' => [
                'healthy' => $dbHealthy,
                'status' => $dbHealthy ? 'CONNECTED_SCHEMA_READY' : 'DEGRADED',
            ],
            'cache_redis' => [
                'healthy' => $redisHealthy,
                'status' => $redisHealthy ? 'OPERATIONAL' : 'DEGRADED',
            ],
            'private_storage' => [
                'healthy' => $storageHealthy,
                'status' => $storageHealthy ? 'PRIVATE_STORAGE_READY' : 'DEGRADED',
            ],
            'real_superadmin' => [
                'healthy' => (bool) $superAdminReport['real_superadmin_provisioned'],
                'status' => $superAdminReport['real_superadmin_provisioned']
                    ? 'REAL_SUPERADMIN_ACTIVE'
                    : 'PENDING_OWNER_CLI_PROVISIONING',
            ],
            'microsoft_entra_sso' => [
                'healthy' => false,
                'status' => $this->entraSso->isEnabled() ? 'ENABLED' : 'DISABLED_BY_DEFAULT (Awaiting Entra App Credentials)',
            ],
            'hr_handover_safety_gate' => [
                'healthy' => true,
                'status' => 'ENFORCED (Offer Acceptance Never Auto-Creates Employee)',
            ],
        ];
    }
}
