<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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
            'is_demo' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            if (empty($tenant->uuid)) {
                $tenant->uuid = (string) Str::uuid();
            }
        });
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }
}
