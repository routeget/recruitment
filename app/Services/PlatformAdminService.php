<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiClient;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class PlatformAdminService
{
    private const FORBIDDEN_DEMO_PASSWORDS = [
        'alphademo#2026!',
        'tenantuser#2026!',
        'password123!',
        'admin123456!',
    ];

    public function __construct(
        private readonly AuditService $auditService,
        private readonly PlatformConfigService $configService,
        private readonly AiGatewayService $aiGatewayService,
        private readonly DynamicsHrConnectorService $dynamicsService,
    ) {}

    /**
     * Inspect current Platform Super Admin posture without exposing credentials or full emails.
     *
     * @return array<string, mixed>
     */
    public function inspectSuperAdminStatus(): array
    {
        $admins = User::query()
            ->where('is_platform_superadmin', true)
            ->orderBy('id')
            ->get();

        $activeRealCount = 0;
        $activeDemoCount = 0;
        $suspendedCount = 0;
        $sanitizedAccounts = [];

        foreach ($admins as $admin) {
            $isActive = $admin->isActiveAccount();
            $isDemo = $admin->isSyntheticDemoIdentity();

            if (! $isActive) {
                $suspendedCount++;
            } elseif ($isDemo) {
                $activeDemoCount++;
            } else {
                $activeRealCount++;
            }

            $sanitizedAccounts[] = [
                'id' => $admin->id,
                'uuid' => $admin->uuid,
                'name' => $admin->name,
                'masked_email' => $admin->maskedEmail(),
                'status' => $admin->status ?? 'ACTIVE',
                'platform_role' => $admin->platform_role ?? 'platform_super_admin',
                'is_platform_superadmin' => (bool) $admin->is_platform_superadmin,
                'mfa_enabled' => (bool) $admin->mfa_enabled,
                'must_rotate_password' => (bool) ($admin->must_rotate_password ?? false),
                'is_synthetic_demo' => $isDemo,
                'granted_at' => $admin->platform_admin_granted_at?->toIso8601String(),
                'granted_by' => $admin->platform_admin_granted_by,
                'last_login_at' => $admin->last_login_at?->toIso8601String(),
            ];
        }

        return [
            'real_superadmin_provisioned' => $activeRealCount > 0,
            'active_real_superadmin_count' => $activeRealCount,
            'active_demo_superadmin_count' => $activeDemoCount,
            'suspended_superadmin_count' => $suspendedCount,
            'total_superadmin_records' => count($sanitizedAccounts),
            'accounts' => $sanitizedAccounts,
        ];
    }

    /**
     * Validate strong password policy for Platform Super Admins.
     */
    public function validateStrongPassword(string $password, string $email): void
    {
        if (strlen($password) < 14) {
            throw new InvalidArgumentException('PASSWORD_POLICY_FAILED: Platform Super Admin password must be at least 14 characters long.');
        }

        if (! preg_match('/[A-Z]/', $password)
            || ! preg_match('/[a-z]/', $password)
            || ! preg_match('/[0-9]/', $password)
            || ! preg_match('/[^A-Za-z0-9]/', $password)
        ) {
            throw new InvalidArgumentException('PASSWORD_POLICY_FAILED: Password must include uppercase, lowercase, numeric, and special symbol characters.');
        }

        if (in_array(strtolower($password), self::FORBIDDEN_DEMO_PASSWORDS, true)) {
            throw new InvalidArgumentException('PASSWORD_POLICY_FAILED: Known synthetic or demo passwords are strictly forbidden.');
        }

        $localPart = strtolower((string) explode('@', $email)[0]);
        if (strlen($localPart) >= 4 && str_contains(strtolower($password), $localPart)) {
            throw new InvalidArgumentException('PASSWORD_POLICY_FAILED: Password must not contain the account email identifier.');
        }
    }

    /**
     * Explicit, idempotent, auditable Platform Super Admin provisioning & recovery operation.
     *
     * @return array<string, mixed>
     */
    public function provisionSuperAdmin(
        string $email,
        string $name,
        ?string $plainPassword,
        string $operatorIdentifier,
        string $reason,
        bool $allowPromoteExisting = false,
        bool $allowSuspendedRecovery = false,
        bool $forcePasswordUpdate = false,
        bool $requirePasswordRotation = false,
        ?User $actorUser = null,
    ): array {
        $normalizedEmail = strtolower(trim($email));
        $cleanName = trim($name);
        $cleanOperator = trim($operatorIdentifier);
        $cleanReason = trim($reason);

        if (! filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('INVALID_EMAIL: A valid administrator email address is required.');
        }

        // Refuse synthetic / demo identities
        foreach (['.test', '.example', '.invalid', '.localhost'] as $forbiddenSuffix) {
            if (str_ends_with($normalizedEmail, $forbiddenSuffix) || $normalizedEmail === 'platform.superadmin@tringers.test') {
                $this->auditService->record(
                    module: 'platform_admin',
                    action: 'platform_superadmin.provision_rejected',
                    outcome: 'DENIED',
                    errorCode: 'SYNTHETIC_EMAIL_FORBIDDEN',
                    actorUserId: $actorUser?->id,
                    actorType: $actorUser ? 'USER' : 'CLI_OPERATOR',
                    actorIdentifier: $cleanOperator ?: 'cli',
                    metadata: ['reason' => 'Attempted to provision synthetic/demo email domain as Platform Super Admin.'],
                );

                throw new InvalidArgumentException('SYNTHETIC_EMAIL_FORBIDDEN: Synthetic or .test email addresses cannot be provisioned as production Platform Super Admins.');
            }
        }

        if (strlen($cleanName) < 3) {
            throw new InvalidArgumentException('INVALID_NAME: Administrator full name must be at least 3 characters.');
        }

        if (strlen($cleanOperator) < 3) {
            throw new InvalidArgumentException('OPERATOR_REQUIRED: An operator identifier is required for audit attribution.');
        }

        if (strlen($cleanReason) < 10) {
            throw new InvalidArgumentException('REASON_REQUIRED: A provisioning/recovery justification of at least 10 characters is required.');
        }

        $txOutcome = DB::transaction(function () use (
            $normalizedEmail,
            $cleanName,
            $plainPassword,
            $cleanOperator,
            $cleanReason,
            $allowPromoteExisting,
            $allowSuspendedRecovery,
            $forcePasswordUpdate,
            $requirePasswordRotation,
            $actorUser
        ): array {
            /** @var User|null $existing */
            $existing = User::query()
                ->where('email', $normalizedEmail)
                ->lockForUpdate()
                ->first();

            $actorType = $actorUser ? 'USER' : 'CLI_OPERATOR';

            if ($existing !== null) {
                $wasActive = $existing->isActiveAccount();
                $wasSuperAdmin = (bool) $existing->is_platform_superadmin;

                if (! $wasActive && ! $allowSuspendedRecovery) {
                    $this->auditService->record(
                        module: 'platform_admin',
                        action: 'platform_superadmin.provision_rejected',
                        outcome: 'DENIED',
                        errorCode: 'ACCOUNT_SUSPENDED',
                        entityType: 'User',
                        entityId: (string) $existing->id,
                        actorUserId: $actorUser?->id,
                        actorType: $actorType,
                        actorIdentifier: $cleanOperator,
                        metadata: [
                            'target_masked_email' => $existing->maskedEmail(),
                            'reason' => $cleanReason,
                        ],
                    );

                    return [
                        'denied' => true,
                        'message' => 'ACCOUNT_SUSPENDED: Target account is suspended. Pass --recover-suspended explicitly to recover a suspended administrator.',
                    ];
                }

                // Idempotent path if already an active Platform Super Admin and no password reset requested
                if ($wasActive && $wasSuperAdmin && ! $forcePasswordUpdate) {
                    $event = $this->auditService->record(
                        module: 'platform_admin',
                        action: 'platform_superadmin.provision_idempotent_noop',
                        outcome: 'SUCCESS',
                        entityType: 'User',
                        entityId: (string) $existing->id,
                        actorUserId: $actorUser?->id,
                        actorType: $actorType,
                        actorIdentifier: $cleanOperator,
                        metadata: [
                            'target_masked_email' => $existing->maskedEmail(),
                            'reason' => $cleanReason,
                            'privilege_changed' => false,
                        ],
                    );

                    return [
                        'denied' => false,
                        'action' => 'IDEMPOTENT_NOOP',
                        'user_id' => $existing->id,
                        'user_uuid' => $existing->uuid,
                        'masked_email' => $existing->maskedEmail(),
                        'audit_event_uuid' => $event->uuid,
                    ];
                }

                if (! $wasSuperAdmin && ! $allowPromoteExisting) {
                    $this->auditService->record(
                        module: 'platform_admin',
                        action: 'platform_superadmin.promotion_rejected',
                        outcome: 'DENIED',
                        errorCode: 'EXISTING_TENANT_ACCOUNT_CONFLICT',
                        entityType: 'User',
                        entityId: (string) $existing->id,
                        actorUserId: $actorUser?->id,
                        actorType: $actorType,
                        actorIdentifier: $cleanOperator,
                        metadata: [
                            'target_masked_email' => $existing->maskedEmail(),
                            'reason' => $cleanReason,
                        ],
                    );

                    return [
                        'denied' => true,
                        'message' => 'EXISTING_TENANT_ACCOUNT_CONFLICT: Account already exists without Platform Super Admin authority. Pass --promote-existing explicitly to promote an existing account.',
                    ];
                }

                $beforeState = [
                    'status' => $existing->status ?? 'ACTIVE',
                    'is_platform_superadmin' => (bool) $existing->is_platform_superadmin,
                    'platform_role' => $existing->platform_role,
                ];

                $updates = [
                    'name' => $cleanName,
                    'status' => 'ACTIVE',
                    'is_platform_superadmin' => true,
                    'platform_role' => 'platform_super_admin',
                    'mfa_enabled' => true,
                    'mfa_enrolled_at' => $existing->mfa_enrolled_at ?? now(),
                    'platform_admin_granted_at' => now(),
                    'platform_admin_granted_by' => $cleanOperator,
                    'platform_admin_reason' => $cleanReason,
                ];

                if ($plainPassword !== null && $plainPassword !== '') {
                    $this->validateStrongPassword($plainPassword, $normalizedEmail);
                    $updates['password'] = Hash::make($plainPassword);
                    $updates['password_changed_at'] = now();
                    $updates['must_rotate_password'] = $requirePasswordRotation;
                }

                $existing->forceFill($updates)->save();

                $actionName = ! $wasActive
                    ? 'platform_superadmin.recovered'
                    : ($wasSuperAdmin ? 'platform_superadmin.credentials_rotated' : 'platform_superadmin.promoted');

                $event = $this->auditService->record(
                    module: 'platform_admin',
                    action: $actionName,
                    outcome: 'SUCCESS',
                    entityType: 'User',
                    entityId: (string) $existing->id,
                    actorUserId: $actorUser?->id,
                    actorType: $actorType,
                    actorIdentifier: $cleanOperator,
                    metadata: [
                        'target_masked_email' => $existing->maskedEmail(),
                        'reason' => $cleanReason,
                        'before' => $beforeState,
                        'after' => [
                            'status' => 'ACTIVE',
                            'is_platform_superadmin' => true,
                            'platform_role' => 'platform_super_admin',
                        ],
                    ],
                );

                return [
                    'denied' => false,
                    'action' => strtoupper(str_replace('platform_superadmin.', '', $actionName)),
                    'user_id' => $existing->id,
                    'user_uuid' => $existing->uuid,
                    'masked_email' => $existing->maskedEmail(),
                    'audit_event_uuid' => $event->uuid,
                ];
            }

            // Creating a brand-new Platform Super Admin account
            if ($plainPassword === null || $plainPassword === '') {
                throw new InvalidArgumentException('PASSWORD_REQUIRED: A strong password is required when creating a new Platform Super Admin.');
            }

            $this->validateStrongPassword($plainPassword, $normalizedEmail);

            $user = new User([
                'uuid' => (string) Str::uuid(),
                'name' => $cleanName,
                'email' => $normalizedEmail,
                'password' => Hash::make($plainPassword),
                'mfa_enabled' => true,
            ]);

            $user->forceFill([
                'status' => 'ACTIVE',
                'is_platform_superadmin' => true,
                'platform_role' => 'platform_super_admin',
                'must_rotate_password' => $requirePasswordRotation,
                'password_changed_at' => now(),
                'mfa_enrolled_at' => now(),
                'platform_admin_granted_at' => now(),
                'platform_admin_granted_by' => $cleanOperator,
                'platform_admin_reason' => $cleanReason,
                'email_verified_at' => now(),
            ])->save();

            $event = $this->auditService->record(
                module: 'platform_admin',
                action: 'platform_superadmin.provisioned',
                outcome: 'SUCCESS',
                entityType: 'User',
                entityId: (string) $user->id,
                actorUserId: $actorUser?->id,
                actorType: $actorType,
                actorIdentifier: $cleanOperator,
                metadata: [
                    'target_masked_email' => $user->maskedEmail(),
                    'reason' => $cleanReason,
                    'must_rotate_password' => $requirePasswordRotation,
                    'after' => [
                        'status' => 'ACTIVE',
                        'is_platform_superadmin' => true,
                        'platform_role' => 'platform_super_admin',
                    ],
                ],
            );

            return [
                'denied' => false,
                'action' => 'PROVISIONED',
                'user_id' => $user->id,
                'user_uuid' => $user->uuid,
                'masked_email' => $user->maskedEmail(),
                'audit_event_uuid' => $event->uuid,
            ];
        });

        if (! empty($txOutcome['denied'])) {
            throw new RuntimeException((string) $txOutcome['message']);
        }

        return $txOutcome;
    }

    /**
     * Revoke Platform Super Admin authority with Last-Super-Admin lockout protection.
     *
     * @return array<string, mixed>
     */
    public function revokeSuperAdmin(
        User $targetUser,
        string $operatorIdentifier,
        string $reason,
        bool $suspendAccount = false,
        ?User $actorUser = null,
    ): array {
        $cleanOperator = trim($operatorIdentifier);
        $cleanReason = trim($reason);
        $actorType = $actorUser ? 'USER' : 'CLI_OPERATOR';

        if (strlen($cleanReason) < 10) {
            throw new InvalidArgumentException('REASON_REQUIRED: A revocation reason of at least 10 characters is required.');
        }

        if ($actorUser !== null && (int) $actorUser->id === (int) $targetUser->id) {
            $this->auditService->record(
                module: 'platform_admin',
                action: 'platform_superadmin.revoke_rejected_self',
                outcome: 'DENIED',
                errorCode: 'SELF_REVOKE_FORBIDDEN',
                entityType: 'User',
                entityId: (string) $targetUser->id,
                actorUserId: $actorUser->id,
                actorType: $actorType,
                actorIdentifier: $cleanOperator,
                metadata: ['reason' => $cleanReason],
            );

            throw new RuntimeException('SELF_REVOKE_FORBIDDEN: A Platform Super Admin cannot revoke or suspend their own active account.');
        }

        $txOutcome = DB::transaction(function () use ($targetUser, $cleanOperator, $cleanReason, $suspendAccount, $actorUser, $actorType): array {
            $allSuperAdmins = User::query()
                ->where('is_platform_superadmin', true)
                ->lockForUpdate()
                ->get();

            /** @var User|null $lockedTarget */
            $lockedTarget = User::query()->whereKey($targetUser->id)->lockForUpdate()->firstOrFail();

            $remainingActiveTotal = $allSuperAdmins
                ->filter(fn (User $u) => (int) $u->id !== (int) $lockedTarget->id && $u->isActivePlatformSuperAdmin())
                ->count();

            $remainingActiveReal = $allSuperAdmins
                ->filter(fn (User $u) => (int) $u->id !== (int) $lockedTarget->id && $u->isActivePlatformSuperAdmin() && ! $u->isSyntheticDemoIdentity())
                ->count();

            $targetIsReal = ! $lockedTarget->isSyntheticDemoIdentity();

            if ($remainingActiveTotal === 0 || ($targetIsReal && $remainingActiveReal === 0)) {
                $this->auditService->record(
                    module: 'platform_admin',
                    action: 'platform_superadmin.revoke_blocked_last_admin',
                    outcome: 'DENIED',
                    errorCode: 'LAST_SUPERADMIN_PROTECTION_TRIGGERED',
                    entityType: 'User',
                    entityId: (string) $lockedTarget->id,
                    actorUserId: $actorUser?->id,
                    actorType: $actorType,
                    actorIdentifier: $cleanOperator,
                    metadata: [
                        'target_masked_email' => $lockedTarget->maskedEmail(),
                        'remaining_active_total' => $remainingActiveTotal,
                        'remaining_active_real' => $remainingActiveReal,
                        'reason' => $cleanReason,
                    ],
                );

                return [
                    'denied' => true,
                    'message' => 'LAST_SUPERADMIN_PROTECTION_TRIGGERED: Cannot revoke or suspend the final active Platform Super Admin.',
                ];
            }

            $before = [
                'status' => $lockedTarget->status ?? 'ACTIVE',
                'is_platform_superadmin' => (bool) $lockedTarget->is_platform_superadmin,
                'platform_role' => $lockedTarget->platform_role,
            ];

            $newStatus = $suspendAccount ? 'SUSPENDED' : ($lockedTarget->status ?? 'ACTIVE');

            $lockedTarget->forceFill([
                'status' => $newStatus,
                'is_platform_superadmin' => false,
                'platform_role' => null,
                'platform_admin_reason' => 'Revoked: '.$cleanReason,
            ])->save();

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $lockedTarget->id)->delete();
            }

            $event = $this->auditService->record(
                module: 'platform_admin',
                action: 'platform_superadmin.revoked',
                outcome: 'SUCCESS',
                entityType: 'User',
                entityId: (string) $lockedTarget->id,
                actorUserId: $actorUser?->id,
                actorType: $actorType,
                actorIdentifier: $cleanOperator,
                metadata: [
                    'target_masked_email' => $lockedTarget->maskedEmail(),
                    'reason' => $cleanReason,
                    'suspended_account' => $suspendAccount,
                    'before' => $before,
                    'after' => [
                        'status' => $newStatus,
                        'is_platform_superadmin' => false,
                        'platform_role' => null,
                    ],
                ],
            );

            return [
                'denied' => false,
                'action' => 'REVOKED',
                'user_id' => $lockedTarget->id,
                'masked_email' => $lockedTarget->maskedEmail(),
                'status' => $newStatus,
                'audit_event_uuid' => $event->uuid,
            ];
        });

        if (! empty($txOutcome['denied'])) {
            throw new RuntimeException((string) $txOutcome['message']);
        }

        return $txOutcome;
    }

    /**
     * Create a new enterprise tenant from the Platform Administration Centre.
     *
     * @param  array<string, mixed>  $data
     */
    public function createTenant(array $data, User $actor): Tenant
    {
        if (! $actor->isActivePlatformAdmin()) {
            throw new InvalidArgumentException('PLATFORM_ADMIN_REQUIRED');
        }

        $minRetention = $this->configService->getInt('security.min_retention_days');
        $retentionDays = isset($data['retention_days']) ? (int) $data['retention_days'] : max(365, $minRetention);
        if ($retentionDays < $minRetention) {
            throw ValidationException::withMessages([
                'retention_days' => ["RETENTION_BELOW_PLATFORM_FLOOR: Retention days cannot be less than {$minRetention} days."],
            ]);
        }

        return DB::transaction(function () use ($data, $actor, $retentionDays): Tenant {
            $tenant = Tenant::query()->create([
                'uuid' => (string) Str::uuid(),
                'slug' => strtolower(trim((string) $data['slug'])),
                'name' => trim((string) $data['name']),
                'primary_domain' => ! empty($data['primary_domain']) ? strtolower(trim((string) $data['primary_domain'])) : null,
                'default_timezone' => (string) ($data['default_timezone'] ?? $this->configService->getString('platform.default_timezone')),
                'default_currency' => (string) ($data['default_currency'] ?? $this->configService->getString('platform.default_currency')),
                'default_locale' => 'en',
                'status' => 'ACTIVE',
                'branding_config' => ['primary_color' => '#0d6efd', 'tagline' => 'Enterprise Talent Acquisition'],
                'settings' => ['require_self_approval_block' => true, 'require_hr_dual_control' => true],
                'retention_days' => $retentionDays,
                'ai_kill_switch' => false,
                'ai_monthly_budget_usd' => 500.00,
                'mcp_enabled' => true,
                'dynamics_live_enabled' => false,
                'sso_required' => false,
                'sso_allow_local_fallback' => true,
                'is_demo' => false,
            ]);

            $this->aiGatewayService->ensureDefaultsForTenant((int) $tenant->id);
            $this->dynamicsService->ensureConfigForTenant((int) $tenant->id);

            $this->auditService->record(
                module: 'platform_admin',
                action: 'tenant.created',
                outcome: 'SUCCESS',
                entityType: 'Tenant',
                entityId: (string) $tenant->id,
                metadata: [
                    'slug' => $tenant->slug,
                    'name' => $tenant->name,
                    'retention_days' => $tenant->retention_days,
                    'reason' => trim((string) ($data['reason'] ?? 'Provisioned via Platform Configuration Centre')),
                ],
                tenantId: (int) $tenant->id,
                actorUserId: (int) $actor->id,
            );

            return $tenant;
        });
    }

    /**
     * Update tenant lifecycle state and platform-governed controls with mandatory reason and before/after audit.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateTenantLifecycle(Tenant $tenant, array $data, User $actor): Tenant
    {
        if (! $actor->isActivePlatformSuperAdmin()) {
            throw new InvalidArgumentException('PLATFORM_SUPERADMIN_REQUIRED');
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if (strlen($reason) < 10) {
            throw ValidationException::withMessages([
                'reason' => ['REASON_REQUIRED: A justification of at least 10 characters is required for tenant lifecycle changes.'],
            ]);
        }

        $minRetention = $this->configService->getInt('security.min_retention_days');
        $retentionDays = isset($data['retention_days']) ? (int) $data['retention_days'] : (int) $tenant->retention_days;
        if ($retentionDays < $minRetention) {
            throw ValidationException::withMessages([
                'retention_days' => ["RETENTION_BELOW_PLATFORM_FLOOR: Retention days cannot be less than {$minRetention} days."],
            ]);
        }

        $newStatus = (string) ($data['status'] ?? $tenant->status);
        if (! in_array($newStatus, ['ACTIVE', 'SUSPENDED'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Invalid tenant lifecycle status. Must be ACTIVE or SUSPENDED.'],
            ]);
        }

        return DB::transaction(function () use ($tenant, $data, $actor, $reason, $retentionDays, $newStatus): Tenant {
            /** @var Tenant $locked */
            $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            $before = [
                'status' => $locked->status,
                'ai_kill_switch' => (bool) $locked->ai_kill_switch,
                'mcp_enabled' => (bool) $locked->mcp_enabled,
                'sso_required' => (bool) $locked->sso_required,
                'retention_days' => (int) $locked->retention_days,
            ];

            $locked->update([
                'status' => $newStatus,
                'ai_kill_switch' => isset($data['ai_kill_switch']) ? (bool) $data['ai_kill_switch'] : (bool) $locked->ai_kill_switch,
                'mcp_enabled' => isset($data['mcp_enabled']) ? (bool) $data['mcp_enabled'] : (bool) $locked->mcp_enabled,
                'sso_required' => isset($data['sso_required']) ? (bool) $data['sso_required'] : (bool) $locked->sso_required,
                'retention_days' => $retentionDays,
            ]);

            $after = [
                'status' => $locked->status,
                'ai_kill_switch' => (bool) $locked->ai_kill_switch,
                'mcp_enabled' => (bool) $locked->mcp_enabled,
                'sso_required' => (bool) $locked->sso_required,
                'retention_days' => (int) $locked->retention_days,
            ];

            $this->auditService->record(
                module: 'platform_admin',
                action: 'tenant.lifecycle_updated',
                outcome: 'SUCCESS',
                entityType: 'Tenant',
                entityId: (string) $locked->id,
                metadata: [
                    'tenant_slug' => $locked->slug,
                    'reason' => $reason,
                    'before' => $before,
                    'after' => $after,
                ],
                tenantId: (int) $locked->id,
                actorUserId: (int) $actor->id,
            );

            return $locked;
        });
    }

    /**
     * Emergency revoke or reactivate a machine ApiClient from the Platform Configuration Centre.
     */
    public function updateApiClientStatus(ApiClient $client, bool $isActive, string $reason, User $actor): ApiClient
    {
        if (! $actor->isActivePlatformSuperAdmin()) {
            throw new InvalidArgumentException('PLATFORM_SUPERADMIN_REQUIRED');
        }

        $cleanReason = trim($reason);
        if (strlen($cleanReason) < 10) {
            throw ValidationException::withMessages([
                'reason' => ['REASON_REQUIRED: A justification of at least 10 characters is required.'],
            ]);
        }

        return DB::transaction(function () use ($client, $isActive, $cleanReason, $actor): ApiClient {
            /** @var ApiClient $locked */
            $locked = ApiClient::withoutGlobalScopes()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $before = (bool) $locked->is_active;

            $locked->update(['is_active' => $isActive]);

            $this->auditService->record(
                module: 'platform_admin',
                action: $isActive ? 'api_client.reactivated' : 'api_client.revoked',
                outcome: 'SUCCESS',
                entityType: 'ApiClient',
                entityId: (string) $locked->id,
                metadata: [
                    'client_id' => $locked->client_id,
                    'reason' => $cleanReason,
                    'before' => ['is_active' => $before],
                    'after' => ['is_active' => $isActive],
                ],
                tenantId: (int) $locked->tenant_id,
                actorUserId: (int) $actor->id,
            );

            return $locked;
        });
    }

    /**
     * Disable or enable a tenant WebhookSubscription from the Platform Configuration Centre.
     */
    public function updateWebhookSubscriptionStatus(WebhookSubscription $subscription, bool $isActive, string $reason, User $actor): WebhookSubscription
    {
        if (! $actor->isActivePlatformSuperAdmin()) {
            throw new InvalidArgumentException('PLATFORM_SUPERADMIN_REQUIRED');
        }

        $cleanReason = trim($reason);
        if (strlen($cleanReason) < 10) {
            throw ValidationException::withMessages([
                'reason' => ['REASON_REQUIRED: A justification of at least 10 characters is required.'],
            ]);
        }

        return DB::transaction(function () use ($subscription, $isActive, $cleanReason, $actor): WebhookSubscription {
            /** @var WebhookSubscription $locked */
            $locked = WebhookSubscription::withoutGlobalScopes()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $before = (bool) $locked->is_active;

            $locked->update(['is_active' => $isActive]);

            $this->auditService->record(
                module: 'platform_admin',
                action: $isActive ? 'webhook_subscription.enabled' : 'webhook_subscription.disabled',
                outcome: 'SUCCESS',
                entityType: 'WebhookSubscription',
                entityId: (string) $locked->id,
                metadata: [
                    'endpoint_url' => $locked->endpoint_url,
                    'reason' => $cleanReason,
                    'before' => ['is_active' => $before],
                    'after' => ['is_active' => $isActive],
                ],
                tenantId: (int) $locked->tenant_id,
                actorUserId: (int) $actor->id,
            );

            return $locked;
        });
    }
}
