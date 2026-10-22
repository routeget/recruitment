<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiTaskRun extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'user_id',
        'task_type',
        'provider_key',
        'model_name',
        'execution_mode',
        'fallback_used',
        'primary_provider_key',
        'retry_count',
        'prompt_version',
        'input_hash',
        'redacted_input_summary',
        'structured_output',
        'schema_valid',
        'human_reviewed',
        'reviewed_by_user_id',
        'reviewed_at',
        'latency_ms',
        'tokens_used',
        'cost_minor',
        'status',
        'error_code',
        'error_message',
        'correlation_id',
        'entity_type',
        'entity_id',
    ];

    protected function casts(): array
    {
        return [
            'fallback_used' => 'boolean',
            'retry_count' => 'integer',
            'prompt_version' => 'integer',
            'structured_output' => 'array',
            'schema_valid' => 'boolean',
            'human_reviewed' => 'boolean',
            'reviewed_at' => 'datetime',
            'latency_ms' => 'integer',
            'tokens_used' => 'integer',
            'cost_minor' => 'integer',
            'entity_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
