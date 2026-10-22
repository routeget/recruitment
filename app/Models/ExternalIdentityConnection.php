<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalIdentityConnection extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'provider',
        'display_name',
        'entra_tenant_id',
        'client_id',
        'allowed_domains',
        'jit_provisioning_enabled',
        'default_jit_role_slug',
        'require_sso_for_tenant',
        'allow_emergency_local_login',
        'is_enabled',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'allowed_domains' => 'array',
            'jit_provisioning_enabled' => 'boolean',
            'require_sso_for_tenant' => 'boolean',
            'allow_emergency_local_login' => 'boolean',
            'is_enabled' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function externalIdentities(): HasMany
    {
        return $this->hasMany(ExternalIdentity::class, 'connection_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(SsoInvitation::class, 'connection_id');
    }
}
