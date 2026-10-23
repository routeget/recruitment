<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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

    /**
     * AI tasks that process candidate personal data or resume-derived content.
     *
     * @var array<int, string>
     */
    public const CANDIDATE_PII_TASKS = [
        'resume.extract',
        'resume_extraction',
        'candidate.match',
        'candidate.match.explanation',
        'candidate_screening',
    ];

    /**
     * External cloud AI providers subject to fail-closed DPA/ZDR attestation gating.
     *
     * @var array<int, string>
     */
    public const EXTERNAL_PROVIDER_KEYS = [
        'claude',
        'gemini',
        'openai',
    ];

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

    public function isExternalProvider(): bool
    {
        return $this->provider_key !== 'deterministic_fake';
    }

    /**
     * Evaluate fail-closed external AI privacy eligibility for candidate/resume PII tasks.
     *
     * @return array{eligible: bool, error_code: ?string, message: string, attestation_summary: array<string, mixed>}
     */
    public function evaluateCandidatePrivacyEligibility(
        string $taskType,
        string $canonicalTask,
        ?string $resolvedModel = null
    ): array {
        if (! $this->isExternalProvider()) {
            return [
                'eligible' => true,
                'error_code' => null,
                'message' => 'Built-in local deterministic analyzer processes data in-process without external transmission.',
                'attestation_summary' => ['mode' => 'local_deterministic'],
            ];
        }

        if (! $this->redaction_enabled) {
            return [
                'eligible' => false,
                'error_code' => 'EXTERNAL_AI_REDACTION_REQUIRED',
                'message' => "External AI provider '{$this->provider_key}' cannot process candidate personal data when PII redaction is disabled.",
                'attestation_summary' => ['redaction_enabled' => false],
            ];
        }

        $settings = is_array($this->settings) ? $this->settings : [];
        $att = $settings['privacy_attestation'] ?? null;

        if (! is_array($att)) {
            return [
                'eligible' => false,
                'error_code' => 'EXTERNAL_AI_PRIVACY_ATTESTATION_REQUIRED',
                'message' => "External AI provider '{$this->provider_key}' requires a verified, structured DPA and zero-data-retention attestation before processing candidate personal data.",
                'attestation_summary' => ['status' => 'MISSING'],
            ];
        }

        $status = strtoupper(trim((string) ($att['status'] ?? '')));
        if (in_array($status, ['REVOKED', 'SUSPENDED', 'WITHDRAWN', 'INVALIDATED'], true)) {
            return [
                'eligible' => false,
                'error_code' => 'EXTERNAL_AI_PRIVACY_ATTESTATION_REVOKED',
                'message' => "External AI provider '{$this->provider_key}' privacy attestation is {$status} and cannot be used for candidate personal data.",
                'attestation_summary' => ['status' => $status],
            ];
        }

        if ($status !== 'VERIFIED') {
            return [
                'eligible' => false,
                'error_code' => 'EXTERNAL_AI_PRIVACY_ATTESTATION_REQUIRED',
                'message' => "External AI provider '{$this->provider_key}' privacy attestation status must be VERIFIED.",
                'attestation_summary' => ['status' => $status ?: 'UNVERIFIED'],
            ];
        }

        $source = trim((string) ($att['attestation_source'] ?? ''));
        $dpaReference = trim((string) ($att['dpa_reference'] ?? ''));
        $zdrReference = trim((string) ($att['zdr_or_retention_reference'] ?? ''));
        $verifierIdentifier = trim((string) ($att['verifier_identifier'] ?? ($att['verified_by_user_id'] ?? '')));
        $verifiedAtRaw = trim((string) ($att['verified_at'] ?? ''));
        $expiresAtRaw = trim((string) ($att['expires_at'] ?? ''));

        if (
            $source === ''
            || strlen($dpaReference) < 4
            || strlen($zdrReference) < 4
            || $verifierIdentifier === ''
            || $verifiedAtRaw === ''
            || $expiresAtRaw === ''
        ) {
            return [
                'eligible' => false,
                'error_code' => 'EXTERNAL_AI_PRIVACY_ATTESTATION_INCOMPLETE',
                'message' => "External AI provider '{$this->provider_key}' privacy attestation is incomplete: attestation_source, dpa_reference, zdr_or_retention_reference, verifier_identifier, verified_at, and expires_at are mandatory.",
                'attestation_summary' => ['status' => $status],
            ];
        }

        try {
            $verifiedAt = Carbon::parse($verifiedAtRaw);
            $expiresAt = Carbon::parse($expiresAtRaw);
        } catch (Throwable) {
            return [
                'eligible' => false,
                'error_code' => 'EXTERNAL_AI_PRIVACY_ATTESTATION_INCOMPLETE',
                'message' => "External AI provider '{$this->provider_key}' privacy attestation contains invalid verified_at or expires_at timestamps.",
                'attestation_summary' => ['status' => $status],
            ];
        }

        if ($expiresAt->lessThanOrEqualTo(now())) {
            return [
                'eligible' => false,
                'error_code' => 'EXTERNAL_AI_PRIVACY_ATTESTATION_EXPIRED',
                'message' => "External AI provider '{$this->provider_key}' privacy attestation expired at {$expiresAt->toIso8601String()}.",
                'attestation_summary' => [
                    'status' => $status,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
            ];
        }

        $approvedTasks = array_values(array_filter(array_map(
            'strval',
            is_array($att['approved_tasks'] ?? null) ? $att['approved_tasks'] : []
        )));

        if (
            empty($approvedTasks)
            || (! in_array($taskType, $approvedTasks, true) && ! in_array($canonicalTask, $approvedTasks, true))
        ) {
            return [
                'eligible' => false,
                'error_code' => 'EXTERNAL_AI_PURPOSE_NOT_APPROVED',
                'message' => "AI task '{$taskType}' is not approved under the privacy attestation for external provider '{$this->provider_key}'.",
                'attestation_summary' => [
                    'status' => $status,
                    'requested_task' => $taskType,
                    'approved_tasks' => $approvedTasks,
                ],
            ];
        }

        $effectiveModel = trim((string) ($resolvedModel ?: $this->model_name));
        $approvedModels = array_values(array_filter(array_map(
            'strval',
            is_array($att['approved_models'] ?? null) ? $att['approved_models'] : []
        )));

        if (empty($approvedModels) || $effectiveModel === '' || ! in_array($effectiveModel, $approvedModels, true)) {
            return [
                'eligible' => false,
                'error_code' => 'EXTERNAL_AI_MODEL_NOT_APPROVED',
                'message' => "Model '{$effectiveModel}' is not approved under the privacy attestation for external provider '{$this->provider_key}'.",
                'attestation_summary' => [
                    'status' => $status,
                    'requested_model' => $effectiveModel,
                    'approved_models' => $approvedModels,
                ],
            ];
        }

        $privSettings = is_array($att['provider_privacy_settings'] ?? null)
            ? $att['provider_privacy_settings']
            : [];

        $allowTraining = (bool) ($privSettings['allow_model_training'] ?? false);
        $trainingOptOutVerified = (bool) ($privSettings['training_opt_out_verified'] ?? false);

        if ($allowTraining || ! $trainingOptOutVerified) {
            return [
                'eligible' => false,
                'error_code' => 'EXTERNAL_AI_PRIVACY_SETTINGS_CONTRADICTORY',
                'message' => "External AI provider '{$this->provider_key}' must verify training_opt_out_verified=true and prohibit model training on candidate data.",
                'attestation_summary' => ['status' => $status],
            ];
        }

        $zdrEnabled = (bool) ($privSettings['zdr_enabled'] ?? false);
        $retentionDays = isset($privSettings['retention_days']) && is_numeric($privSettings['retention_days'])
            ? (int) $privSettings['retention_days']
            : -1;
        $maxAllowedRetentionDays = isset($privSettings['max_allowed_retention_days']) && is_numeric($privSettings['max_allowed_retention_days'])
            ? (int) $privSettings['max_allowed_retention_days']
            : 0;

        if ($retentionDays < 0) {
            return [
                'eligible' => false,
                'error_code' => 'EXTERNAL_AI_PRIVACY_SETTINGS_INVALID',
                'message' => "External AI provider '{$this->provider_key}' must declare a non-negative retention_days setting.",
                'attestation_summary' => ['status' => $status],
            ];
        }

        if ($zdrEnabled && $retentionDays > 0) {
            return [
                'eligible' => false,
                'error_code' => 'EXTERNAL_AI_PRIVACY_SETTINGS_CONTRADICTORY',
                'message' => "External AI provider '{$this->provider_key}' has contradictory privacy settings: zdr_enabled=true cannot be combined with retention_days={$retentionDays}.",
                'attestation_summary' => ['status' => $status],
            ];
        }

        if (! $zdrEnabled && ($maxAllowedRetentionDays <= 0 || $retentionDays > $maxAllowedRetentionDays)) {
            return [
                'eligible' => false,
                'error_code' => 'EXTERNAL_AI_PRIVACY_SETTINGS_CONTRADICTORY',
                'message' => "External AI provider '{$this->provider_key}' requires either zero data retention (zdr_enabled=true, retention_days=0) or retention_days within an approved max_allowed_retention_days policy.",
                'attestation_summary' => ['status' => $status],
            ];
        }

        if ($this->provider_key === 'openai') {
            if (! array_key_exists('store_completions', $privSettings) || $privSettings['store_completions'] !== false) {
                return [
                    'eligible' => false,
                    'error_code' => 'EXTERNAL_AI_PRIVACY_SETTINGS_CONTRADICTORY',
                    'message' => 'OpenAI provider privacy settings must explicitly enforce store_completions=false.',
                    'attestation_summary' => ['status' => $status],
                ];
            }
        } elseif ($this->provider_key === 'gemini') {
            $billingTier = strtoupper(trim((string) ($privSettings['billing_tier'] ?? '')));
            $loggingDisabled = (bool) ($privSettings['project_logging_disabled'] ?? false);
            if (! in_array($billingTier, ['PAID_ENTERPRISE', 'VERTEX_AI_ENTERPRISE'], true) || ! $loggingDisabled) {
                return [
                    'eligible' => false,
                    'error_code' => 'EXTERNAL_AI_PRIVACY_SETTINGS_CONTRADICTORY',
                    'message' => 'Google Gemini provider privacy settings must verify PAID_ENTERPRISE or VERTEX_AI_ENTERPRISE billing tier and project_logging_disabled=true.',
                    'attestation_summary' => ['status' => $status],
                ];
            }
        } elseif ($this->provider_key === 'claude') {
            $commercialTerms = (bool) ($privSettings['commercial_terms_verified'] ?? false);
            $zdrOrg = (bool) ($privSettings['zdr_org_enabled'] ?? $zdrEnabled);
            if (! $commercialTerms || ! $zdrOrg) {
                return [
                    'eligible' => false,
                    'error_code' => 'EXTERNAL_AI_PRIVACY_SETTINGS_CONTRADICTORY',
                    'message' => 'Anthropic Claude provider privacy settings must verify commercial_terms_verified=true and zdr_org_enabled=true.',
                    'attestation_summary' => ['status' => $status],
                ];
            }
        }

        return [
            'eligible' => true,
            'error_code' => null,
            'message' => 'External AI provider privacy attestation verified.',
            'attestation_summary' => [
                'status' => $status,
                'attestation_source' => $source,
                'dpa_reference' => $dpaReference,
                'zdr_or_retention_reference' => $zdrReference,
                'verifier_identifier' => $verifierIdentifier,
                'verified_at' => $verifiedAt->toIso8601String(),
                'expires_at' => $expiresAt->toIso8601String(),
                'retention_days' => $retentionDays,
                'zdr_enabled' => $zdrEnabled,
            ],
        ];
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
