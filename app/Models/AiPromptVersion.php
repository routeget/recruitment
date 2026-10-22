<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiPromptVersion extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'task_type',
        'version',
        'system_prompt',
        'output_schema',
        'is_active',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'output_schema' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
