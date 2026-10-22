<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiProviderConfig extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'provider_key',
        'model_name',
        'is_enabled',
        'is_primary',
        'monthly_budget_minor',
        'current_spend_minor',
        'kill_switch_engaged',
        'redaction_enabled',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_primary' => 'boolean',
            'monthly_budget_minor' => 'integer',
            'current_spend_minor' => 'integer',
            'kill_switch_engaged' => 'boolean',
            'redaction_enabled' => 'boolean',
            'settings' => 'array',
        ];
    }
}
