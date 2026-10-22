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
        'is_platform_superadmin',
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
            'last_login_at' => 'datetime',
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
        return $this->memberships()
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->exists();
    }

    public function roleSlugsForTenant(?int $tenantId = null): array
    {
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
            if ($membership->role) {
                foreach ($membership->role->permissions as $perm) {
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
}
