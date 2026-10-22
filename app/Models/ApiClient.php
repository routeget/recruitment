<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiClient extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'name',
        'client_id',
        'client_secret_hash',
        'scopes',
        'rate_limit_per_minute',
        'is_active',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'rate_limit_per_minute' => 'integer',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }
}
