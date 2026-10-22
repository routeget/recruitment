<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DynamicsIntegrationConfig extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'environment_url',
        'connection_mode',
        'real_employee_creation_enabled',
        'legal_entity_mappings',
        'field_ownership_matrix',
        'last_health_check_at',
        'last_health_status',
    ];

    protected function casts(): array
    {
        return [
            'real_employee_creation_enabled' => 'boolean',
            'legal_entity_mappings' => 'array',
            'field_ownership_matrix' => 'array',
            'last_health_check_at' => 'datetime',
        ];
    }
}
