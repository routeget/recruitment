<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class Tenant extends Model
{
    protected $fillable = [
        'uuid',
        'slug',
        'name',
        'primary_domain',
        'default_timezone',
        'default_currency',
        'default_locale',
        'status',
        'branding_config',
        'settings',
        'retention_days',
        'ai_kill_switch',
        'ai_monthly_budget_usd',
        'mcp_enabled',
        'dynamics_live_enabled',
        'require_sso',
        'allow_local_fallback',
        'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'branding_config' => 'array',
            'settings' => 'array',
            'retention_days' => 'integer',
            'ai_kill_switch' => 'boolean',
            'ai_monthly_budget_usd' => 'decimal:2',
            'mcp_enabled' => 'boolean',
            'dynamics_live_enabled' => 'boolean',
            'require_sso' => 'boolean',
            'allow_local_fallback' => 'boolean',
            'is_demo' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            if (empty($tenant->uuid)) {
                $tenant->uuid = (string) Str::uuid();
            }

            if (empty($tenant->slug) || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', strtolower(trim((string) $tenant->slug)))) {
                throw new LogicException('TENANT_SLUG_REQUIRED: A valid tenant slug is required at creation.');
            }

            $tenant->slug = strtolower(trim((string) $tenant->slug));
        });

        static::updating(function (Tenant $tenant) {
            if ($tenant->isDirty('slug')) {
                throw new LogicException('TENANT_SLUG_IMMUTABLE: A tenant slug cannot be changed after creation.');
            }
        });
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function externalIdentityConnections(): HasMany
    {
        return $this->hasMany(ExternalIdentityConnection::class);
    }

    public function externalIdentities(): HasMany
    {
        return $this->hasMany(ExternalIdentity::class);
    }

    public function ssoInvitations(): HasMany
    {
        return $this->hasMany(SsoInvitation::class);
    }
}
