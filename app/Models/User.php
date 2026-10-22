<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'uuid',
        'name',
        'email',
        'password',
        'current_tenant_id',
        'mfa_enabled',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_superadmin' => 'boolean',
            'mfa_enabled' => 'boolean',
            'must_rotate_password' => 'boolean',
            'last_login_at' => 'datetime',
            'platform_admin_granted_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'mfa_enrolled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->uuid)) {
                $user->uuid = (string) Str::uuid();
            }
        });
    }

    public function isActiveAccount(): bool
    {
        return ($this->status ?? 'ACTIVE') === 'ACTIVE';
    }

    /**
     * Authoritative check for global Platform Super Admin authority.
     * Tenant role memberships alone never grant global platform authority.
     */
    public function isActivePlatformSuperAdmin(): bool
    {
        return $this->isActiveAccount() && (bool) $this->is_platform_superadmin === true;
    }

    /**
     * Platform administration access. Super Admin authority remains separately testable
     * for operations that require the highest privilege.
     */
    public function isActivePlatformAdmin(): bool
    {
        return $this->isActiveAccount() && (
            $this->isActivePlatformSuperAdmin()
            || in_array(strtolower((string) $this->platform_role), ['platform_admin', 'platform_super_admin', 'platform_superadmin'], true)
        );
    }

    public function isSyntheticDemoIdentity(): bool
    {
        $email = strtolower((string) $this->email);
        if ($email === 'platform.superadmin@tringers.test') {
            return true;
        }

        foreach (['.test', '.example', '.invalid', '.localhost'] as $suffix) {
            if (str_ends_with($email, $suffix)) {
                return true;
            }
        }

        return false;
    }

    public function maskedEmail(): string
    {
        $email = (string) $this->email;
        $parts = explode('@', $email, 2);
        $local = $parts[0] ?? 'user';
        $domain = $parts[1] ?? 'unknown';
        $prefix = substr($local, 0, min(2, strlen($local)));

        return $prefix.'***@'.$domain;
    }

    public function currentTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'current_tenant_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function externalIdentities(): HasMany
    {
        return $this->hasMany(ExternalIdentity::class);
    }

    public function activeMembershipsForTenant(int $tenantId): HasMany
    {
        return $this->memberships()
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->with('role.permissions');
    }

    public function isMemberOfTenant(int $tenantId): bool
    {
        if (! $this->isActiveAccount()) {
            return false;
        }

        return $this->memberships()
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->exists();
    }

    public function roleSlugsForTenant(?int $tenantId = null): array
    {
        if (! $this->isActiveAccount()) {
            return [];
        }

        $resolvedTenantId = $tenantId ?? $this->current_tenant_id;
        if (! $resolvedTenantId) {
            return [];
        }

        return $this->memberships()
            ->where('tenant_id', $resolvedTenantId)
            ->where('status', 'ACTIVE')
            ->with('role')
            ->get()
            ->pluck('role.slug')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function hasRole(string|array $roles, ?int $tenantId = null): bool
    {
        $targetRoles = (array) $roles;
        $userRoles = $this->roleSlugsForTenant($tenantId);

        return count(array_intersect($targetRoles, $userRoles)) > 0;
    }

    public function permissionSlugsForTenant(?int $tenantId = null): array
    {
        if (! $this->isActiveAccount()) {
            return [];
        }

        $resolvedTenantId = $tenantId ?? $this->current_tenant_id;
        if (! $resolvedTenantId) {
            return [];
        }

        $memberships = $this->memberships()
            ->where('tenant_id', $resolvedTenantId)
            ->where('status', 'ACTIVE')
            ->with('role.permissions')
            ->get();

        $permissions = [];
        foreach ($memberships as $membership) {
            $abac = is_array($membership->abac_scope) ? $membership->abac_scope : [];
            $prohibitCandidatePii = ! empty($abac['candidate_pii_browsing_prohibited']);
            if ($membership->role) {
                foreach ($membership->role->permissions as $perm) {
                    if ($prohibitCandidatePii && in_array($perm->slug, ['candidates.view', 'candidates.manage', 'applications.manage'], true)) {
                        continue;
                    }
                    $permissions[] = $perm->slug;
                }
            }
        }

        return array_values(array_unique($permissions));
    }

    public function hasPermission(string $permissionSlug, ?int $tenantId = null): bool
    {
        return in_array($permissionSlug, $this->permissionSlugsForTenant($tenantId), true);
    }

    /**
     * @param  array<int, string>  $permissionSlugs
     */
    public function hasAnyPermission(array $permissionSlugs, ?int $tenantId = null): bool
    {
        $granted = $this->permissionSlugsForTenant($tenantId);

        return count(array_intersect($permissionSlugs, $granted)) > 0;
    }
}
