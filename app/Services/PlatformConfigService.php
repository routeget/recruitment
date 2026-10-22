<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class PlatformConfigService
{
    /**
     * Typed, allowlisted registry of database-backed platform settings.
     * Arbitrary keys outside this registry are strictly rejected.
     *
     * @var array<string, array<string, mixed>>
     */
    public const REGISTRY = [
        // Section 2: Platform Identity & Branding
        'platform.display_name' => [
            'section' => 'branding',
            'label' => 'Platform Display Name',
            'description' => 'Global application title rendered in navigation headers, login screen, and page titles.',
            'type' => 'string',
            'default' => 'Unified Recruitment AI Platform',
            'rules' => ['required', 'string', 'min:3', 'max:100'],
            'classification' => 'PLATFORM_DB_SETTING',
            'sensitivity' => 'PUBLIC',
            'requires_reason' => false,
            'consumer' => 'EnforceTenantAndSecurityContext, layouts/app.blade.php, auth/login.blade.php',
            'restart_requirement' => 'Immediate runtime effect',
        ],
        'platform.brand_code' => [
            'section' => 'branding',
            'label' => 'Platform Short Brand Badge',
            'description' => 'Short monogram badge displayed in the top navigation bar.',
            'type' => 'string',
            'default' => 'URAP',
            'rules' => ['required', 'string', 'min:2', 'max:12', 'alpha_num'],
            'classification' => 'PLATFORM_DB_SETTING',
            'sensitivity' => 'PUBLIC',
            'requires_reason' => false,
            'consumer' => 'layouts/app.blade.php',
            'restart_requirement' => 'Immediate runtime effect',
        ],
        'platform.support_email' => [
            'section' => 'branding',
            'label' => 'Platform Operations Support Email',
            'description' => 'Global support contact displayed in platform footers, SSO diagnostics, and help banners.',
            'type' => 'string',
            'default' => 'support@career.tringers.com',
            'rules' => ['required', 'email', 'max:150'],
            'classification' => 'PLATFORM_DB_SETTING',
            'sensitivity' => 'PUBLIC',
            'requires_reason' => false,
            'consumer' => 'layouts/app.blade.php, auth/login.blade.php',
            'restart_requirement' => 'Immediate runtime effect',
        ],
        'platform.default_timezone' => [
            'section' => 'branding',
            'label' => 'Global Default Timezone',
            'description' => 'Default timezone assigned when provisioning new enterprise tenants.',
            'type' => 'string',
            'default' => 'UTC',
            'rules' => ['required', 'timezone:all'],
            'classification' => 'PLATFORM_DB_SETTING',
            'sensitivity' => 'INTERNAL',
            'requires_reason' => false,
            'consumer' => 'PlatformAdminService::createTenant',
            'restart_requirement' => 'Immediate runtime effect',
        ],
        'platform.default_currency' => [
            'section' => 'branding',
            'label' => 'Global Default Currency',
            'description' => 'Default ISO-4217 currency code assigned to new tenants.',
            'type' => 'enum',
            'options' => ['USD', 'GBP', 'EUR', 'AED', 'INR'],
            'default' => 'USD',
            'rules' => ['required', 'string', 'in:USD,GBP,EUR,AED,INR'],
            'classification' => 'PLATFORM_DB_SETTING',
            'sensitivity' => 'INTERNAL',
            'requires_reason' => false,
            'consumer' => 'PlatformAdminService::createTenant',
            'restart_requirement' => 'Immediate runtime effect',
        ],
        'platform.career_portal_banner' => [
            'section' => 'branding',
            'label' => 'Public Career Portal Headline Banner',
            'description' => 'Global trust & compliance banner displayed on the public career portal (/careers).',
            'type' => 'string',
            'default' => 'Verified Enterprise Opportunities — Powered by Governed Recruitment AI',
            'rules' => ['required', 'string', 'min:5', 'max:240'],
            'classification' => 'PLATFORM_DB_SETTING',
            'sensitivity' => 'PUBLIC',
            'requires_reason' => false,
            'consumer' => 'CareerPortalController::index',
            'restart_requirement' => 'Immediate runtime effect',
        ],

        // Section 6: AI Provider & Usage Governance
        'ai.global_kill_switch' => [
            'section' => 'ai_governance',
            'label' => 'Platform-Wide AI Emergency Kill Switch',
            'description' => 'When enabled, immediately halts all AI gateway task executions across all tenants (AI_GLOBAL_KILL_SWITCH_ENGAGED).',
            'type' => 'boolean',
            'default' => false,
            'rules' => ['required', 'boolean'],
            'classification' => 'PLATFORM_DB_SETTING',
            'sensitivity' => 'SENSITIVE_GOVERNANCE',
            'requires_reason' => true,
            'consumer' => 'AiGatewayService::executeTask',
            'restart_requirement' => 'Immediate runtime effect',
        ],
        'ai.global_monthly_budget_cap_minor' => [
            'section' => 'ai_governance',
            'label' => 'Global Per-Provider Monthly Budget Ceiling (Minor Units / Cents)',
            'description' => 'Maximum monthly AI budget (in minor currency units, e.g. 250000 = $2,500.00) any tenant provider may configure or consume.',
            'type' => 'integer',
            'default' => 250000,
            'rules' => ['required', 'integer', 'min:0', 'max:10000000'],
            'classification' => 'PLATFORM_DB_SETTING',
            'sensitivity' => 'SENSITIVE_GOVERNANCE',
            'requires_reason' => true,
            'consumer' => 'AiGatewayService::executeTask, AiGatewayService::updateProviderConfig',
            'restart_requirement' => 'Immediate runtime effect',
        ],
        'ai.mandatory_pii_redaction' => [
            'section' => 'ai_governance',
            'label' => 'Enforce Mandatory PII & Secret Redaction Before AI Prompts',
            'description' => 'Forces automatic email, national ID, and token redaction on all AI gateway inputs regardless of tenant overrides.',
            'type' => 'boolean',
            'default' => true,
            'rules' => ['required', 'boolean'],
            'classification' => 'PLATFORM_DB_SETTING',
            'sensitivity' => 'SENSITIVE_GOVERNANCE',
            'requires_reason' => true,
            'consumer' => 'AiGatewayService::executeTask',
            'restart_requirement' => 'Immediate runtime effect',
        ],
        'ai.default_primary_provider' => [
            'section' => 'ai_governance',
            'label' => 'Default Primary AI Provider Adapter',
            'description' => 'Default primary adapter selected when initializing AI governance for a tenant.',
            'type' => 'enum',
            'options' => ['claude', 'gemini', 'openai', 'deterministic_fake'],
            'default' => 'claude',
            'rules' => ['required', 'string', 'in:claude,gemini,openai,deterministic_fake'],
            'classification' => 'PLATFORM_DB_SETTING',
            'sensitivity' => 'INTERNAL',
            'requires_reason' => true,
            'consumer' => 'AiGatewayService::ensureDefaultsForTenant',
            'restart_requirement' => 'Immediate runtime effect',
        ],

        // Section 7: API, MCP & Webhook Governance
        'api.global_mcp_enabled' => [
            'section' => 'api_mcp',
            'label' => 'Platform-Wide MCP Server Enablement (/mcp/v1)',
            'description' => 'Controls whether the Model Context Protocol endpoints (/mcp/v1/tools and /mcp/v1/invoke) accept requests.',
            'type' => 'boolean',
            'default' => true,
            'rules' => ['required', 'boolean'],
            'classification' => 'PLATFORM_DB_SETTING',
            'sensitivity' => 'SENSITIVE_GOVERNANCE',
            'requires_reason' => true,
            'consumer' => 'ApiV1AndMcpController::listMcpTools, ApiV1AndMcpController::invokeMcpTool',
            'restart_requirement' => 'Immediate runtime effect',
        ],
        'webhooks.global_delivery_enabled' => [
            'section' => 'api_mcp',
            'label' => 'Platform-Wide Outbound Webhook Delivery Enablement',
            'description' => 'Master switch for outbound signed webhook dispatches across all tenants.',
            'type' => 'boolean',
            'default' => true,
            'rules' => ['required', 'boolean'],
            'classification' => 'PLATFORM_DB_SETTING',
            'sensitivity' => 'SENSITIVE_GOVERNANCE',
            'requires_reason' => true,
            'consumer' => 'ApiAndMcpPlatformService::emitWebhook, DeliverSignedWebhookJob::handle',
            'restart_requirement' => 'Immediate runtime effect',
        ],
        'webhooks.max_subscriptions_per_tenant' => [
            'section' => 'api_mcp',
            'label' => 'Maximum Webhook Subscriptions Per Tenant',
            'description' => 'Upper bound on registered webhook subscriptions for any single tenant.',
            'type' => 'integer',
            'default' => 25,
            'rules' => ['required', 'integer', 'min:1', 'max:100'],
            'classification' => 'PLATFORM_DB_SETTING',
            'sensitivity' => 'INTERNAL',
            'requires_reason' => false,
            'consumer' => 'ApiAndMcpPlatformService::registerWebhookSubscription',
            'restart_requirement' => 'Immediate runtime effect',
        ],

        // Section 8: External Integrations (Dynamics 365 HR Safety Gate)
        'dynamics.global_live_writes_blocked' => [
            'section' => 'integrations',
            'label' => 'Block Live Outbound Dynamics 365 Employee Creation Platform-Wide',
            'description' => 'Safety gate keeping Dynamics 365 HR employee creation in fail-closed / sandbox validation mode platform-wide.',
            'type' => 'boolean',
            'default' => true,
            'rules' => ['required', 'boolean'],
            'classification' => 'PLATFORM_DB_SETTING',
            'sensitivity' => 'SENSITIVE_GOVERNANCE',
            'requires_reason' => true,
            'consumer' => 'DynamicsHrConnectorService::updateConfig, OfferAndHrHandoverService::dispatchApprovedHandover',
            'restart_requirement' => 'Immediate runtime effect',
        ],

        // Section 9: Security Policies
        'security.login_max_attempts_per_minute' => [
            'section' => 'security',
            'label' => 'Login Rate Limit (Max Attempts Per Minute Per Email/IP)',
            'description' => 'Maximum failed authentication attempts allowed before rate-limiting (RATE_LIMITED).',
            'type' => 'integer',
            'default' => 8,
            'rules' => ['required', 'integer', 'min:3', 'max:30'],
            'classification' => 'PLATFORM_DB_SETTING',
            'sensitivity' => 'SENSITIVE_GOVERNANCE',
            'requires_reason' => true,
            'consumer' => 'AuthController::login',
            'restart_requirement' => 'Immediate runtime effect',
        ],
        'security.require_mfa_for_platform_admins' => [
            'section' => 'security',
            'label' => 'Require MFA Enrollment for Platform Super Admins',
            'description' => 'Enforces server-side check that any Platform Super Admin has mfa_enabled = true before accessing /platform-admin.',
            'type' => 'boolean',
            'default' => true,
            'rules' => ['required', 'boolean'],
            'classification' => 'PLATFORM_DB_SETTING',
            'sensitivity' => 'SENSITIVE_GOVERNANCE',
            'requires_reason' => true,
            'consumer' => 'RequirePlatformSuperAdmin::handle',
            'restart_requirement' => 'Immediate runtime effect',
        ],
        'security.require_step_up_for_sensitive_changes' => [
            'section' => 'security',
            'label' => 'Require Step-Up Password Verification for Privilege Changes',
            'description' => 'Requires current password confirmation when promoting, revoking, or suspending Platform Super Admins in the UI.',
            'type' => 'boolean',
            'default' => true,
            'rules' => ['required', 'boolean'],
            'classification' => 'PLATFORM_DB_SETTING',
            'sensitivity' => 'SENSITIVE_GOVERNANCE',
            'requires_reason' => true,
            'consumer' => 'PlatformAdminController::promoteAdmin, PlatformAdminController::revokeAdmin',
            'restart_requirement' => 'Immediate runtime effect',
        ],
        'security.min_retention_days' => [
            'section' => 'security',
            'label' => 'Minimum Tenant Data Retention Floor (Days)',
            'description' => 'Minimum compliance retention period (days) that any tenant lifecycle update may configure.',
            'type' => 'integer',
            'default' => 180,
            'rules' => ['required', 'integer', 'min:30', 'max:3650'],
            'classification' => 'PLATFORM_DB_SETTING',
            'sensitivity' => 'SENSITIVE_GOVERNANCE',
            'requires_reason' => true,
            'consumer' => 'PlatformAdminService::updateTenantLifecycle',
            'restart_requirement' => 'Immediate runtime effect',
        ],
    ];

    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    public function ensureDefaultsSeeded(): void
    {
        if (! Schema::hasTable('platform_settings')) {
            return;
        }

        foreach (self::REGISTRY as $key => $meta) {
            PlatformSetting::query()->firstOrCreate(
                ['key' => $key],
                [
                    'section' => $meta['section'],
                    'value_json' => ['value' => $meta['default']],
                    'value_type' => $meta['type'],
                    'classification' => $meta['classification'],
                    'sensitivity' => $meta['sensitivity'],
                    'version' => 1,
                ]
            );
        }
    }

    public function get(string $key): mixed
    {
        if (! array_key_exists($key, self::REGISTRY)) {
            throw new InvalidArgumentException("Unknown platform setting key: {$key}");
        }

        $meta = self::REGISTRY[$key];

        try {
            if (! Schema::hasTable('platform_settings')) {
                return $meta['default'];
            }

            $record = PlatformSetting::query()->where('key', $key)->first();
            if (! $record) {
                return $meta['default'];
            }

            $val = $record->extractTypedValue();

            return $val ?? $meta['default'];
        } catch (Throwable) {
            return $meta['default'];
        }
    }

    public function getString(string $key): string
    {
        return (string) $this->get($key);
    }

    public function getInt(string $key): int
    {
        return (int) $this->get($key);
    }

    public function getBool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /**
     * Validate a CSS hex color token strictly (#RRGGBB) to prevent arbitrary CSS injection.
     */
    public static function sanitizeHexColor(mixed $value, string $fallback): string
    {
        if (! is_string($value)) {
            return $fallback;
        }

        $trimmed = trim($value);
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $trimmed) === 1) {
            return strtoupper($trimmed);
        }

        return $fallback;
    }

    /**
     * Resolve validated Microsoft Dynamics 365 / Fluent enterprise theme tokens.
     *
     * @return array<string, string>
     */
    public function getThemeTokens(): array
    {
        $rawPrimary = config('recruitment.theme.primary_color', '#0078D4');
        $rawHeaderDark = config('recruitment.theme.header_dark_color', '#002050');
        $rawHeaderEmphasis = config('recruitment.theme.header_emphasis_color', '#004578');

        return [
            'primary_actions' => self::sanitizeHexColor($rawPrimary, '#0078D4'),
            'header_dark' => self::sanitizeHexColor($rawHeaderDark, '#002050'),
            'header_emphasis' => self::sanitizeHexColor($rawHeaderEmphasis, '#004578'),
            'surface_main' => '#FFFFFF',
            'bg_canvas' => '#F5F5F5',
            'surface_secondary' => '#F1F5F9',
            'text_main' => '#323130',
            'text_secondary' => '#605E5C',
            'text_high_emphasis' => '#201F1E',
            'disabled_controls' => '#A19F9D',
            'border_input' => '#D2D0CE',
            'border_divider' => '#EDEBE9',
            'hover_bg' => '#F3F9FD',
            'selected_row_bg' => '#EFF6FC',
            'selected_control' => '#DEECF9',
            'success_fg' => '#107C10',
            'success_bg' => '#DFF6DD',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getSectionMetadataAndValues(string $section): array
    {
        $this->ensureDefaultsSeeded();

        $records = Schema::hasTable('platform_settings')
            ? PlatformSetting::query()->where('section', $section)->with('updatedBy')->get()->keyBy('key')
            : collect();

        $result = [];
        foreach (self::REGISTRY as $key => $meta) {
            if ($meta['section'] !== $section) {
                continue;
            }

            /** @var PlatformSetting|null $rec */
            $rec = $records->get($key);
            $result[$key] = array_merge($meta, [
                'key' => $key,
                'value' => $rec ? $rec->extractTypedValue() : $meta['default'],
                'version' => $rec ? $rec->version : 1,
                'updated_at' => $rec?->updated_at,
                'updated_by_email' => $rec?->updatedBy?->maskedEmail(),
                'last_change_reason' => $rec?->last_change_reason,
            ]);
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAllRegistryWithValues(): array
    {
        $this->ensureDefaultsSeeded();

        $records = Schema::hasTable('platform_settings')
            ? PlatformSetting::query()->with('updatedBy')->get()->keyBy('key')
            : collect();

        $result = [];
        foreach (self::REGISTRY as $key => $meta) {
            /** @var PlatformSetting|null $rec */
            $rec = $records->get($key);
            $result[$key] = array_merge($meta, [
                'key' => $key,
                'value' => $rec ? $rec->extractTypedValue() : $meta['default'],
                'version' => $rec ? $rec->version : 1,
                'updated_at' => $rec?->updated_at,
                'updated_by_email' => $rec?->updatedBy?->maskedEmail(),
                'last_change_reason' => $rec?->last_change_reason,
            ]);
        }

        return $result;
    }

    /**
     * Update a section of allowlisted platform settings atomically with validation,
     * optimistic locking, and append-only audit logging.
     *
     * @param  array<string, mixed>  $rawInputs
     * @param  array<string, int>  $expectedVersions
     * @return array<string, mixed>
     */
    public function updateSectionSettings(
        string $section,
        array $rawInputs,
        User $actor,
        ?string $reason = null,
        array $expectedVersions = [],
    ): array {
        if (! $actor->isActivePlatformSuperAdmin()) {
            throw new InvalidArgumentException('PLATFORM_SUPERADMIN_REQUIRED: Only an active Platform Super Admin can mutate platform settings.');
        }

        $this->ensureDefaultsSeeded();

        $sectionKeys = [];
        foreach (self::REGISTRY as $key => $meta) {
            if ($meta['section'] === $section) {
                $sectionKeys[$key] = $meta;
            }
        }

        if ($sectionKeys === []) {
            throw new InvalidArgumentException("Invalid configuration section: {$section}");
        }

        // Reject any arbitrary or out-of-section keys supplied by caller
        foreach (array_keys($rawInputs) as $suppliedKey) {
            if (! array_key_exists($suppliedKey, $sectionKeys)) {
                $this->auditService->record(
                    module: 'platform_admin',
                    action: 'platform_config.unregistered_key_rejected',
                    outcome: 'DENIED',
                    errorCode: 'UNREGISTERED_CONFIG_KEY',
                    entityType: 'PlatformSettingSection',
                    entityId: $section,
                    metadata: ['rejected_key' => (string) $suppliedKey],
                    actorUserId: (int) $actor->id,
                );

                throw ValidationException::withMessages([
                    'settings' => ["UNREGISTERED_CONFIG_KEY: Setting '{$suppliedKey}' is not an allowlisted setting in section '{$section}'."],
                ]);
            }
        }

        // Normalize and validate all section keys atomically
        $normalized = [];
        $validationData = [];
        $validationRules = [];

        foreach ($sectionKeys as $key => $meta) {
            $formField = str_replace('.', '__', $key);
            $rawVal = $rawInputs[$key] ?? ($meta['type'] === 'boolean' ? false : null);

            if ($meta['type'] === 'boolean') {
                $typedVal = filter_var($rawVal, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            } elseif ($meta['type'] === 'integer' && is_numeric($rawVal)) {
                $typedVal = (int) $rawVal;
            } else {
                $typedVal = is_string($rawVal) ? trim($rawVal) : $rawVal;
            }

            $normalized[$key] = $typedVal;
            $validationData[$formField] = $typedVal;
            $validationRules[$formField] = $meta['rules'];
        }

        $validator = Validator::make($validationData, $validationRules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Check if any modified sensitive setting requires a change reason
        $requiresReason = false;
        foreach ($sectionKeys as $key => $meta) {
            if (! empty($meta['requires_reason'])) {
                $requiresReason = true;
                break;
            }
        }

        $cleanReason = trim((string) $reason);
        if ($requiresReason && strlen($cleanReason) < 10) {
            throw ValidationException::withMessages([
                'reason' => ['REASON_REQUIRED: A change justification of at least 10 characters is required for governance/security settings.'],
            ]);
        }

        return DB::transaction(function () use ($section, $sectionKeys, $normalized, $actor, $cleanReason, $expectedVersions): array {
            $changes = [];

            foreach ($sectionKeys as $key => $meta) {
                /** @var PlatformSetting $setting */
                $setting = PlatformSetting::query()
                    ->where('key', $key)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (isset($expectedVersions[$key]) && (int) $expectedVersions[$key] !== (int) $setting->version) {
                    throw ValidationException::withMessages([
                        'version' => ["CONCURRENT_MODIFICATION_CONFLICT: Setting '{$key}' was modified by another administrator. Please reload and retry."],
                    ]);
                }

                $oldValue = $setting->extractTypedValue();
                $newValue = $normalized[$key];

                if ($oldValue !== $newValue) {
                    $setting->update([
                        'value_json' => ['value' => $newValue],
                        'version' => $setting->version + 1,
                        'updated_by_user_id' => $actor->id,
                        'last_change_reason' => $cleanReason !== '' ? $cleanReason : 'Updated via Configuration Centre',
                    ]);

                    $changes[$key] = [
                        'before' => $oldValue,
                        'after' => $newValue,
                        'version' => $setting->version,
                    ];
                }
            }

            $this->auditService->record(
                module: 'platform_admin',
                action: 'platform_config.section_updated',
                outcome: 'SUCCESS',
                entityType: 'PlatformSettingSection',
                entityId: $section,
                metadata: [
                    'section' => $section,
                    'reason' => $cleanReason !== '' ? $cleanReason : 'Updated via Configuration Centre',
                    'changed_keys_count' => count($changes),
                    'changes' => $changes,
                ],
                actorUserId: (int) $actor->id,
            );

            return $changes;
        });
    }

    /**
     * Read-only inventory of environment-managed and secret-manager reference settings.
     * Never exposes raw secret values, prefixes, or hashes.
     *
     * @return array<int, array<string, string>>
     */
    public function getSanitizedEnvironmentCatalogue(): array
    {
        $entraClientIdPresent = trim((string) config('entra_sso.client_id', '')) !== '';
        $entraSecretPresent = trim((string) config('entra_sso.client_secret', '')) !== '';
        $entraEnabled = (bool) config('entra_sso.enabled', false);

        return [
            [
                'key' => 'APP_ENV',
                'classification' => 'ENVIRONMENT_MANAGED',
                'scope' => 'Platform Global',
                'sensitivity' => 'INTERNAL',
                'effective_state' => (string) app()->environment(),
                'editable_in_ui' => 'NO (Deployment Runbook Only)',
                'consumer' => 'Application Bootstrap & Seeder Guard',
            ],
            [
                'key' => 'APP_DEBUG',
                'classification' => 'ENVIRONMENT_MANAGED',
                'scope' => 'Platform Global',
                'sensitivity' => 'SENSITIVE_SECURITY',
                'effective_state' => config('app.debug') ? 'true' : 'false (Safe Production Mode)',
                'editable_in_ui' => 'NO (Deployment Runbook Only)',
                'consumer' => 'Exception Handler',
            ],
            [
                'key' => 'APP_KEY',
                'classification' => 'SECRET_ENV_REFERENCE',
                'scope' => 'Platform Global',
                'sensitivity' => 'CRITICAL_SECRET',
                'effective_state' => ! empty(config('app.key')) ? 'CONFIGURED (MASKED)' : 'NOT_SET',
                'editable_in_ui' => 'NO (Never Exposed in UI/API)',
                'consumer' => 'Laravel Encrypter (Webhook Secrets, Cookies)',
            ],
            [
                'key' => 'MICROSOFT_ENTRA_SSO_ENABLED',
                'classification' => 'ENVIRONMENT_MANAGED',
                'scope' => 'Platform Global',
                'sensitivity' => 'SENSITIVE_SECURITY',
                'effective_state' => $entraEnabled ? 'true (ENABLED)' : 'false (DISABLED_BY_DEFAULT — FAIL_CLOSED)',
                'editable_in_ui' => 'NO (Requires Verified Entra App & Test Tenant)',
                'consumer' => 'EntraSsoService::isEnabled',
            ],
            [
                'key' => 'MICROSOFT_ENTRA_TENANT_MODE',
                'classification' => 'ENVIRONMENT_MANAGED',
                'scope' => 'Platform Global',
                'sensitivity' => 'SENSITIVE_SECURITY',
                'effective_state' => (string) config('entra_sso.tenant_mode', 'organizations'),
                'editable_in_ui' => 'NO (Work/School Accounts Policy)',
                'consumer' => 'EntraSsoService::createAuthorizationRedirect',
            ],
            [
                'key' => 'MICROSOFT_ENTRA_CLIENT_ID',
                'classification' => 'ENVIRONMENT_MANAGED',
                'scope' => 'Platform Global',
                'sensitivity' => 'INTERNAL',
                'effective_state' => $entraClientIdPresent ? 'CONFIGURED (MASKED_IDENTIFIER)' : 'NOT_SET (Awaiting App Registration)',
                'editable_in_ui' => 'NO (Environment / Secret Manager)',
                'consumer' => 'EntraSsoService',
            ],
            [
                'key' => 'MICROSOFT_ENTRA_CLIENT_SECRET',
                'classification' => 'SECRET_ENV_REFERENCE',
                'scope' => 'Platform Global',
                'sensitivity' => 'CRITICAL_SECRET',
                'effective_state' => $entraSecretPresent ? 'CONFIGURED (MASKED)' : 'NOT_SET (Awaiting App Registration)',
                'editable_in_ui' => 'NO (Never Exposed in UI/API)',
                'consumer' => 'EntraSsoService::exchangeAuthorizationCode',
            ],
            [
                'key' => 'SESSION_DRIVER / CACHE_STORE / QUEUE_CONNECTION',
                'classification' => 'ENVIRONMENT_MANAGED',
                'scope' => 'Platform Global',
                'sensitivity' => 'INTERNAL',
                'effective_state' => sprintf(
                    'session=%s | cache=%s | queue=%s',
                    (string) config('session.driver'),
                    (string) config('cache.default'),
                    (string) config('queue.default')
                ),
                'editable_in_ui' => 'NO (Infrastructure Managed)',
                'consumer' => 'Session, Redis Cache & Queue Workers',
            ],
        ];
    }
}
