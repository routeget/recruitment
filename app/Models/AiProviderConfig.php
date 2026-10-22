<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class AiProviderConfig extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUS_UNCONFIGURED = 'UNCONFIGURED';

    public const STATUS_CONNECTED = 'CONNECTED';

    public const STATUS_AUTH_FAILED = 'AUTH_FAILED';

    public const STATUS_RATE_LIMITED = 'RATE_LIMITED';

    public const STATUS_DEGRADED = 'DEGRADED';

    public const STATUS_DISABLED = 'DISABLED';

    public const STATUS_BUILT_IN = 'BUILT_IN_ENGINE';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'provider_key',
        'model_name',
        'encrypted_api_key',
        'api_key_masked',
        'endpoint_url',
        'supported_models',
        'capabilities',
        'fallback_provider_key',
        'timeout_seconds',
        'max_retries',
        'rate_limit_rpm',
        'connection_status',
        'last_validated_at',
        'last_error_code',
        'last_error_message',
        'is_enabled',
        'is_primary',
        'monthly_budget_minor',
        'current_spend_minor',
        'kill_switch_engaged',
        'redaction_enabled',
        'settings',
    ];

    protected $hidden = [
        'encrypted_api_key',
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
            'supported_models' => 'array',
            'capabilities' => 'array',
            'timeout_seconds' => 'integer',
            'max_retries' => 'integer',
            'rate_limit_rpm' => 'integer',
            'last_validated_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function setPlaintextApiKey(?string $plaintextKey): void
    {
        $trimmed = trim((string) $plaintextKey);
        if ($trimmed === '') {
            $this->encrypted_api_key = null;
            $this->api_key_masked = null;

            return;
        }

        $this->encrypted_api_key = Crypt::encryptString($trimmed);
        $len = strlen($trimmed);
        if ($len <= 8) {
            $this->api_key_masked = str_repeat('*', max(4, $len));
        } else {
            $this->api_key_masked = substr($trimmed, 0, 4).'...'.substr($trimmed, -4);
        }
    }

    public function getDecryptedApiKey(): ?string
    {
        if (empty($this->encrypted_api_key)) {
            $envMap = [
                'claude' => env('ANTHROPIC_API_KEY'),
                'gemini' => env('GEMINI_API_KEY'),
                'openai' => env('OPENAI_API_KEY'),
            ];
            $envVal = trim((string) ($envMap[$this->provider_key] ?? ''));

            return $envVal !== '' ? $envVal : null;
        }

        try {
            return Crypt::decryptString((string) $this->encrypted_api_key);
        } catch (Throwable) {
            return null;
        }
    }

    public function hasConfiguredCredentials(): bool
    {
        if ($this->provider_key === 'deterministic_fake') {
            return true;
        }

        return $this->getDecryptedApiKey() !== null;
    }

    public function getDisplayNameAttribute(): string
    {
        return match ($this->provider_key) {
            'claude' => 'Anthropic Claude',
            'gemini' => 'Google Gemini',
            'openai' => 'OpenAI',
            'deterministic_fake' => 'Built-In Structured Rule Analyzer',
            default => strtoupper((string) $this->provider_key),
        };
    }
}
