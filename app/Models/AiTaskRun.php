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

    public function getProviderDisplayNameAttribute(): string
    {
        return match ($this->provider_key) {
            'claude' => 'Anthropic Claude',
            'gemini' => 'Google Gemini',
            'openai' => 'OpenAI',
            'deterministic_fake' => 'Built-In Structured Rule Analyzer',
            default => strtoupper((string) ($this->provider_key ?: 'AI Engine')),
        };
    }

    public function getModelDisplayNameAttribute(): string
    {
        return match ($this->model_name) {
            'urap-deterministic-v1' => 'Governed Rule Engine v1.0',
            default => (string) ($this->model_name ?: 'Default Model'),
        };
    }

    public function getExecutionModeDisplayAttribute(): string
    {
        return match ($this->execution_mode) {
            'live_http_adapter' => 'Live Provider Adapter',
            'fallback_local_governed_analyzer' => 'Fallback Governed Analyzer',
            'local_governed_analyzer' => 'Built-In Governed Analyzer',
            default => 'Structured Engine',
        };
    }
}
