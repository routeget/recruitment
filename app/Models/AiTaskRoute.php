<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiTaskRoute extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'task_type',
        'primary_provider_key',
        'primary_model_name',
        'fallback_provider_key',
        'fallback_model_name',
        'temperature',
        'max_output_tokens',
        'timeout_seconds',
        'max_retries',
        'quality_threshold',
        'require_structured_output',
        'require_human_review',
        'is_enabled',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'temperature' => 'decimal:2',
            'max_output_tokens' => 'integer',
            'timeout_seconds' => 'integer',
            'max_retries' => 'integer',
            'quality_threshold' => 'decimal:2',
            'require_structured_output' => 'boolean',
            'require_human_review' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
