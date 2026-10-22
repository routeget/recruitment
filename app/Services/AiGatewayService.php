<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AiProviderAdapterInterface;
use App\Models\AiPromptVersion;
use App\Models\AiProviderConfig;
use App\Models\AiTaskRoute;
use App\Models\AiTaskRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Adapters\AnthropicClaudeAdapter;
use App\Services\Ai\Adapters\DeterministicStructuredAdapter;
use App\Services\Ai\Adapters\GoogleGeminiAdapter;
use App\Services\Ai\Adapters\OpenAiAdapter;
use App\Services\Ai\ResumeContentAnalyzer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class AiGatewayService
{
    /**
     * Canonical and legacy-compatible governed AI tasks with explicit required output schema keys.
     *
     * @var array<string, array{title: string, canonical_key: string, required_keys: array<int, string>}>
     */
    public const ALLOWED_TASKS = [
        'resume.extract' => [
            'title' => 'Structured Resume / CV Profile Extraction (Schema v1.0)',
            'canonical_key' => 'resume.extract',
            'required_keys' => [
                'schema_version',
                'full_name',
                'contact',
                'professional_summary',
                'skills',
                'work_experience',
                'education',
                'missing_fields',
                'evidence_quotes',
            ],
        ],
        'resume_extraction' => [
            'title' => 'Structured Resume / CV Profile Extraction',
            'canonical_key' => 'resume.extract',
            'required_keys' => ['extracted_skills', 'years_experience_estimate', 'education_highlights', 'evidence_quotes'],
        ],
        'candidate.match' => [
            'title' => 'Evidence-Based Candidate-to-Job Qualification Matching',
            'canonical_key' => 'candidate.match',
            'required_keys' => ['extracted_facts', 'ai_interpretation', 'criteria_scores', 'overall_score', 'recommendation_advisory', 'missing_evidence_flags'],
        ],
        'candidate.match.explanation' => [
            'title' => 'Explainable Candidate Match & Missing Evidence Analysis',
            'canonical_key' => 'candidate.match.explanation',
            'required_keys' => ['extracted_facts', 'ai_interpretation', 'criteria_scores', 'overall_score', 'recommendation_advisory', 'missing_evidence_flags'],
        ],
        'candidate_screening' => [
            'title' => 'Evidence-Based Candidate Screening Breakdown (Human Review Required)',
            'canonical_key' => 'candidate.match',
            'required_keys' => ['criteria_scores', 'overall_score', 'recommendation_advisory', 'missing_evidence_flags'],
        ],
        'job.description.generate' => [
            'title' => 'Job Description Drafting & Inclusive Language Check',
            'canonical_key' => 'job.description.generate',
            'required_keys' => ['suggested_title', 'summary', 'responsibilities', 'inclusive_language_notes'],
        ],
        'job_description_draft' => [
            'title' => 'Job Description Drafting & Inclusive Language Check',
            'canonical_key' => 'job.description.generate',
            'required_keys' => ['suggested_title', 'summary', 'responsibilities', 'inclusive_language_notes'],
        ],
        'interview.summary' => [
            'title' => 'Interview Scorecard Evidence Synthesis & Debrief Summary',
            'canonical_key' => 'interview.summary',
            'required_keys' => ['consensus_strengths', 'consensus_concerns', 'open_questions_for_debrief'],
        ],
        'interview_guide_generation' => [
            'title' => 'Structured Competency Interview Guide Generation',
            'canonical_key' => 'interview.summary',
            'required_keys' => ['competencies', 'structured_questions', 'rubric_anchors'],
        ],
        'interview_feedback_synthesis' => [
            'title' => 'Interview Scorecard Evidence Synthesis',
            'canonical_key' => 'interview.summary',
            'required_keys' => ['consensus_strengths', 'consensus_concerns', 'open_questions_for_debrief'],
        ],
        'offer.draft' => [
            'title' => 'Governed Candidate Offer & Compensation Summary Draft',
            'canonical_key' => 'offer.draft',
            'required_keys' => ['offer_summary', 'compensation_narrative', 'onboarding_prerequisites'],
        ],
    ];

    /**
     * Canonical configurable routing tasks exposed in the AI routing console.
     *
     * @var array<int, string>
     */
    public const ROUTABLE_TASKS = [
        'resume.extract',
        'candidate.match',
        'candidate.match.explanation',
        'job.description.generate',
        'interview.summary',
        'offer.draft',
    ];

    public const PROHIBITED_TASKS = [
        'final_hiring_decision',
        'automated_candidate_rejection',
        'hr_handover_approval',
        'protected_trait_inference',
        'biometric_emotion_scoring',
    ];

    /**
     * @var array<string, AiProviderAdapterInterface>
     */
    private array $adapters = [];

    public function __construct(
        private readonly AuditService $auditService,
        private readonly TenantContextService $tenantContext,
        private readonly ?PlatformConfigService $platformConfig = null,
    ) {
        $analyzer = new ResumeContentAnalyzer;
        $this->registerAdapter(new AnthropicClaudeAdapter);
        $this->registerAdapter(new GoogleGeminiAdapter);
        $this->registerAdapter(new OpenAiAdapter);
        $this->registerAdapter(new DeterministicStructuredAdapter($analyzer));
    }

    public function registerAdapter(AiProviderAdapterInterface $adapter): void
    {
        $this->adapters[$adapter->getProviderKey()] = $adapter;
    }

    /**
     * @return array<string, AiProviderAdapterInterface>
     */
    public function getRegisteredAdapters(): array
    {
        return $this->adapters;
    }

    public function resolveAdapter(string $providerKey): AiProviderAdapterInterface
    {
        return $this->getAdapter($providerKey);
    }

    public function getAdapter(string $providerKey): AiProviderAdapterInterface
    {
        $normalized = strtolower(trim($providerKey));
        if ($normalized === 'codex') {
            throw new InvalidArgumentException('AI_PROVIDER_CODEX_NOT_RUNTIME: Codex is an engineering workflow and must not be configured or invoked as a runtime model provider.');
        }

        if (! isset($this->adapters[$normalized])) {
            throw new InvalidArgumentException("AI_PROVIDER_UNSUPPORTED: Provider adapter '{$providerKey}' is not registered.");
        }

        return $this->adapters[$normalized];
    }

    private function config(): PlatformConfigService
    {
        return $this->platformConfig ?? app(PlatformConfigService::class);
    }

    public function ensureDefaultsForTenant(int $tenantId): void
    {
        $defaultPrimary = $this->config()->getString('ai.default_primary_provider');
        $globalCap = $this->config()->getInt('ai.global_monthly_budget_cap_minor');

        $providers = [
            ['provider_key' => 'claude', 'model_name' => 'claude-3-7-sonnet', 'monthly_budget_minor' => min(50000, $globalCap)],
            ['provider_key' => 'gemini', 'model_name' => 'gemini-2.5-pro', 'monthly_budget_minor' => min(50000, $globalCap)],
            ['provider_key' => 'openai', 'model_name' => 'gpt-4.1', 'monthly_budget_minor' => min(50000, $globalCap)],
            ['provider_key' => 'deterministic_fake', 'model_name' => 'urap-deterministic-v1', 'monthly_budget_minor' => min(100000, $globalCap)],
        ];

        foreach ($providers as $p) {
            $adapter = $this->getAdapter($p['provider_key']);
            $existing = AiProviderConfig::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('provider_key', $p['provider_key'])
                ->first();

            if ($existing === null) {
                $status = $p['provider_key'] === 'deterministic_fake'
                    ? AiProviderConfig::STATUS_BUILT_IN
                    : AiProviderConfig::STATUS_UNCONFIGURED;

                $created = new AiProviderConfig([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'provider_key' => $p['provider_key'],
                    'model_name' => $p['model_name'],
                    'endpoint_url' => $adapter->getDefaultEndpoint(),
                    'supported_models' => $adapter->getSupportedModels(),
                    'capabilities' => $adapter->getSupportedCapabilities(),
                    'fallback_provider_key' => $p['provider_key'] !== 'deterministic_fake' ? 'deterministic_fake' : null,
                    'timeout_seconds' => 15,
                    'max_retries' => 2,
                    'rate_limit_rpm' => 60,
                    'connection_status' => $status,
                    'is_enabled' => true,
                    'is_primary' => $p['provider_key'] === $defaultPrimary,
                    'monthly_budget_minor' => $p['monthly_budget_minor'],
                    'current_spend_minor' => 0,
                    'kill_switch_engaged' => false,
                    'redaction_enabled' => true,
                    'settings' => ['timeout_seconds' => 15, 'mode' => 'governed_structured_json'],
                ]);

                if ($created->hasConfiguredCredentials() && $p['provider_key'] !== 'deterministic_fake') {
                    $created->connection_status = AiProviderConfig::STATUS_CONNECTED;
                }

                $created->save();
            } else {
                $dirty = false;
                if (empty($existing->supported_models)) {
                    $existing->supported_models = $adapter->getSupportedModels();
                    $dirty = true;
                }
                if (empty($existing->capabilities)) {
                    $existing->capabilities = $adapter->getSupportedCapabilities();
                    $dirty = true;
                }
                if (empty($existing->endpoint_url)) {
                    $existing->endpoint_url = $adapter->getDefaultEndpoint();
                    $dirty = true;
                }
                if ($existing->provider_key === 'deterministic_fake' && $existing->connection_status === AiProviderConfig::STATUS_UNCONFIGURED) {
                    $existing->connection_status = AiProviderConfig::STATUS_BUILT_IN;
                    $dirty = true;
                }
                if ($dirty) {
                    $existing->saveQuietly();
                }
            }
        }

        foreach (self::ROUTABLE_TASKS as $taskType) {
            AiTaskRoute::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenantId, 'task_type' => $taskType],
                [
                    'uuid' => (string) Str::uuid(),
                    'primary_provider_key' => $defaultPrimary ?: 'claude',
                    'primary_model_name' => match ($defaultPrimary) {
                        'gemini' => 'gemini-2.5-pro',
                        'openai' => 'gpt-4.1',
                        'deterministic_fake' => 'urap-deterministic-v1',
                        default => 'claude-3-7-sonnet',
                    },
                    'fallback_provider_key' => 'deterministic_fake',
                    'fallback_model_name' => 'urap-deterministic-v1',
                    'temperature' => 0.10,
                    'max_output_tokens' => 2000,
                    'timeout_seconds' => 15,
                    'max_retries' => 2,
                    'quality_threshold' => 0.75,
                    'require_structured_output' => true,
                    'require_human_review' => true,
                    'is_enabled' => true,
                ]
            );
        }

        foreach (self::ALLOWED_TASKS as $taskType => $meta) {
            AiPromptVersion::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenantId, 'task_type' => $taskType, 'version' => 1],
                [
                    'uuid' => (string) Str::uuid(),
                    'system_prompt' => "You are a governed recruitment AI assistant performing {$meta['title']}. Cite only verifiable job-relevant evidence from the document. Missing information must remain null or empty. Never invent qualifications, dates, employers, or skills. Never infer protected characteristics or make autonomous hiring/HR decisions.",
                    'output_schema' => ['required' => $meta['required_keys']],
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * Validate provider credentials and live connectivity without leaking secrets.
     *
     * @return array{ok: bool, connected: bool, status: string, error_code: ?string, message: string, latency_ms: int}
     */
    public function validateProviderConnection(AiProviderConfig $config, ?User $actor = null): array
    {
        $adapter = $this->getAdapter($config->provider_key);
        $result = $adapter->validateConnection($config);

        $config->update([
            'connection_status' => $result['status'],
            'last_validated_at' => now(),
            'last_error_code' => $result['error_code'],
            'last_error_message' => $result['connected'] ? null : $result['message'],
        ]);

        $this->auditService->record(
            module: 'ai_gateway',
            action: 'ai.provider_connection_validated',
            outcome: $result['connected'] ? 'SUCCESS' : 'DENIED',
            errorCode: $result['error_code'],
            entityType: 'ai_provider_config',
            entityId: (string) $config->id,
            metadata: [
                'provider_key' => $config->provider_key,
                'model_name' => $config->model_name,
                'connection_status' => $result['status'],
                'latency_ms' => $result['latency_ms'],
            ],
            tenantId: (int) $config->tenant_id,
            actorUserId: $actor ? (int) $actor->id : null,
        );

        return array_merge($result, ['ok' => $result['connected']]);
    }

    /**
     * Execute a governed AI task with task-specific routing, credential checks, bounded retries, fallback, and schema validation.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $contextMeta
     */
    public function executeTask(
        string $taskType,
        string $providerKey,
        string $rawInputText,
        ?User $actor,
        int $tenantId,
        array $options = [],
        array $contextMeta = [],
    ): AiTaskRun {
        $options = array_merge($options, $contextMeta);
        $this->ensureDefaultsForTenant($tenantId);

        if ($this->config()->getBool('ai.global_kill_switch')) {
            $this->auditService->record(
                module: 'ai_gateway',
                action: 'ai.global_kill_switch_blocked',
                outcome: 'DENIED',
                errorCode: 'AI_GLOBAL_KILL_SWITCH_ENGAGED',
                entityType: 'ai_task',
                entityId: $taskType,
                metadata: ['provider_key' => $providerKey, 'task_type' => $taskType],
                tenantId: $tenantId,
                actorUserId: $actor ? (int) $actor->id : null,
            );

            throw new RuntimeException('AI invocation blocked: Platform-wide AI emergency kill switch is engaged (AI_GLOBAL_KILL_SWITCH_ENGAGED).', 409);
        }

        $tenant = Tenant::withoutGlobalScopes()->find($tenantId);
        if ($tenant !== null && $tenant->ai_kill_switch) {
            $this->auditService->record(
                module: 'ai_gateway',
                action: 'ai.tenant_kill_switch_blocked',
                outcome: 'DENIED',
                errorCode: 'AI_TENANT_KILL_SWITCH_ENGAGED',
                entityType: 'Tenant',
                entityId: (string) $tenantId,
                metadata: ['provider_key' => $providerKey, 'task_type' => $taskType],
                tenantId: $tenantId,
                actorUserId: $actor ? (int) $actor->id : null,
            );

            throw new RuntimeException('AI invocation blocked: Tenant-level AI emergency kill switch is engaged (AI_TENANT_KILL_SWITCH_ENGAGED).', 409);
        }

        if (in_array($taskType, self::PROHIBITED_TASKS, true) || ! array_key_exists($taskType, self::ALLOWED_TASKS)) {
            $this->auditService->record(
                module: 'ai_gateway',
                action: 'ai.prohibited_task_blocked',
                outcome: 'DENIED',
                errorCode: 'AI_PROHIBITED_USE',
                entityType: 'ai_task',
                entityId: $taskType,
                metadata: ['task_type' => $taskType],
                tenantId: $tenantId,
                actorUserId: $actor ? (int) $actor->id : null,
            );

            throw new InvalidArgumentException("AI task '{$taskType}' is prohibited by platform governance policy (AI_PROHIBITED_USE).");
        }

        $canonicalTask = self::ALLOWED_TASKS[$taskType]['canonical_key'];
        $taskRoute = AiTaskRoute::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('task_type', [$taskType, $canonicalTask])
            ->first();

        if ($taskRoute !== null && ! $taskRoute->is_enabled) {
            throw new RuntimeException("AI_TASK_DISABLED: Task routing for '{$taskType}' is disabled by administrator configuration.", 409);
        }

        $resolvedProviderKey = ($providerKey === 'auto' && $taskRoute !== null)
            ? $taskRoute->primary_provider_key
            : $providerKey;

        // Validate adapter exists & block 'codex'
        $this->getAdapter($resolvedProviderKey);

        /** @var AiProviderConfig $providerConfig */
        $providerConfig = AiProviderConfig::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('provider_key', $resolvedProviderKey)
            ->firstOrFail();

        if ($providerConfig->kill_switch_engaged || ! $providerConfig->is_enabled) {
            $this->auditService->record(
                module: 'ai_gateway',
                action: 'ai.kill_switch_blocked',
                outcome: 'DENIED',
                errorCode: 'AI_KILL_SWITCH_ENGAGED',
                entityType: 'ai_provider_config',
                entityId: (string) $providerConfig->id,
                metadata: ['provider_key' => $resolvedProviderKey, 'task_type' => $taskType],
                tenantId: $tenantId,
                actorUserId: $actor ? (int) $actor->id : null,
            );

            throw new RuntimeException('AI provider invocation blocked: tenant kill switch is engaged (AI_KILL_SWITCH_ENGAGED).', 409);
        }

        $estimatedCostMinor = 25; // $0.25 per structured call
        $globalCap = $this->config()->getInt('ai.global_monthly_budget_cap_minor');
        $effectiveBudgetCap = min((int) $providerConfig->monthly_budget_minor, $globalCap);

        if (($providerConfig->current_spend_minor + $estimatedCostMinor) > $effectiveBudgetCap) {
            $this->auditService->record(
                module: 'ai_gateway',
                action: 'ai.budget_exceeded_blocked',
                outcome: 'DENIED',
                errorCode: 'AI_BUDGET_EXCEEDED',
                entityType: 'ai_provider_config',
                entityId: (string) $providerConfig->id,
                metadata: [
                    'provider_key' => $resolvedProviderKey,
                    'current_spend_minor' => $providerConfig->current_spend_minor,
                    'monthly_budget_minor' => $providerConfig->monthly_budget_minor,
                    'global_budget_cap_minor' => $globalCap,
                ],
                tenantId: $tenantId,
                actorUserId: $actor ? (int) $actor->id : null,
            );

            throw new RuntimeException('AI provider monthly budget cap exceeded (AI_BUDGET_EXCEEDED).', 429);
        }

        $mustRedact = (bool) $providerConfig->redaction_enabled || $this->config()->getBool('ai.mandatory_pii_redaction');
        $preserveCandidateContact = (bool) ($options['preserve_candidate_contact'] ?? false);
        $redactedInput = $mustRedact
            ? $this->redactPiiAndSecrets($rawInputText, ! $preserveCandidateContact)
            : $rawInputText;

        $promptVersion = AiPromptVersion::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('task_type', [$taskType, $canonicalTask])
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        $systemPrompt = $promptVersion?->system_prompt
            ?? 'You are a governed recruitment AI assistant. Cite only verifiable evidence and return valid JSON.';
        $requiredKeys = self::ALLOWED_TASKS[$taskType]['required_keys'];

        $executionOptions = array_merge([
            'timeout_seconds' => $taskRoute?->timeout_seconds ?? $providerConfig->timeout_seconds ?? 15,
            'max_retries' => $taskRoute?->max_retries ?? $providerConfig->max_retries ?? 2,
            'max_output_tokens' => $taskRoute?->max_output_tokens ?? 2000,
        ], $options);

        $fallbackUsed = false;
        $primaryProviderKey = $providerConfig->provider_key;
        $activeProviderConfig = $providerConfig;

        try {
            $execResult = $this->invokeAdapterOrLocalAnalyzer(
                $activeProviderConfig,
                $taskType,
                $systemPrompt,
                $redactedInput,
                $requiredKeys,
                $executionOptions
            );
        } catch (RuntimeException $primaryError) {
            $fallbackKey = (string) ($options['fallback_provider_key']
                ?? ($providerKey === 'auto' ? $taskRoute?->fallback_provider_key : $providerConfig->fallback_provider_key)
                ?? '');

            $allowFallback = ($options['allow_fallback'] ?? true) === true
                && $fallbackKey !== ''
                && $fallbackKey !== $providerConfig->provider_key;

            if ($allowFallback) {
                /** @var AiProviderConfig|null $fallbackConfig */
                $fallbackConfig = AiProviderConfig::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('provider_key', $fallbackKey)
                    ->where('is_enabled', true)
                    ->where('kill_switch_engaged', false)
                    ->first();

                if ($fallbackConfig !== null) {
                    $fallbackUsed = true;
                    $activeProviderConfig = $fallbackConfig;
                    $fallbackOptions = $executionOptions;
                    unset($fallbackOptions['model_name'], $fallbackOptions['require_live_credentials']);

                    $this->auditService->record(
                        module: 'ai_gateway',
                        action: 'ai.provider_fallback_engaged',
                        entityType: 'ai_provider_config',
                        entityId: (string) $fallbackConfig->id,
                        metadata: [
                            'task_type' => $taskType,
                            'primary_provider_key' => $providerConfig->provider_key,
                            'fallback_provider_key' => $fallbackConfig->provider_key,
                            'reason' => substr($primaryError->getMessage(), 0, 160),
                        ],
                        tenantId: $tenantId,
                        actorUserId: $actor ? (int) $actor->id : null,
                    );

                    $execResult = $this->invokeAdapterOrLocalAnalyzer(
                        $fallbackConfig,
                        $taskType,
                        $systemPrompt,
                        $redactedInput,
                        $requiredKeys,
                        $fallbackOptions
                    );
                    if ($execResult['execution_mode'] !== 'LIVE_PROVIDER_API') {
                        $execResult['execution_mode'] = 'fallback_'.$execResult['execution_mode'];
                    }
                } else {
                    $this->recordFailedTaskRun($tenantId, $actor, $taskType, $providerConfig, $promptVersion, $redactedInput, $primaryError);
                    throw $primaryError;
                }
            } else {
                $this->recordFailedTaskRun($tenantId, $actor, $taskType, $providerConfig, $redactedInput ? $promptVersion : null, $redactedInput, $primaryError);
                throw $primaryError;
            }
        }

        $structuredOutput = $execResult['structured_output'];
        if (in_array('contact', $requiredKeys, true) && ! array_key_exists('contact', $structuredOutput)) {
            if (array_key_exists('emails', $structuredOutput) || array_key_exists('phones', $structuredOutput)) {
                $structuredOutput['contact'] = [
                    'email' => $structuredOutput['emails'][0] ?? null,
                    'phone' => $structuredOutput['phones'][0] ?? null,
                    'location_city' => $structuredOutput['location'] ?? null,
                    'country_code' => $structuredOutput['country_code'] ?? null,
                    'links' => $structuredOutput['professional_links'] ?? [],
                ];
            }
        }
        if (in_array('evidence_quotes', $requiredKeys, true) && ! array_key_exists('evidence_quotes', $structuredOutput)) {
            $structuredOutput['evidence_quotes'] = [];
        }

        $schemaValid = $this->validateStructuredSchema($structuredOutput, $requiredKeys);

        if (! $schemaValid) {
            $schemaException = new RuntimeException("SCHEMA_VALIDATION_FAILED: Provider output for '{$taskType}' is missing required schema keys.", 422);
            $this->recordFailedTaskRun($tenantId, $actor, $taskType, $activeProviderConfig, $promptVersion, $redactedInput, $schemaException, $structuredOutput, false);
            throw $schemaException;
        }

        return DB::transaction(function () use (
            $tenantId,
            $actor,
            $taskType,
            $activeProviderConfig,
            $primaryProviderKey,
            $fallbackUsed,
            $promptVersion,
            $redactedInput,
            $structuredOutput,
            $schemaValid,
            $estimatedCostMinor,
            $execResult,
            $options
        ): AiTaskRun {
            $activeProviderConfig->increment('current_spend_minor', $estimatedCostMinor);

            $run = AiTaskRun::query()->create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'user_id' => $actor?->id,
                'task_type' => $taskType,
                'provider_key' => $activeProviderConfig->provider_key,
                'model_name' => $activeProviderConfig->model_name,
                'execution_mode' => $execResult['execution_mode'],
                'fallback_used' => $fallbackUsed,
                'primary_provider_key' => $primaryProviderKey,
                'retry_count' => $execResult['retry_count'],
                'prompt_version' => $promptVersion?->version ?? 1,
                'input_hash' => hash('sha256', $redactedInput),
                'redacted_input_summary' => substr($redactedInput, 0, 500),
                'structured_output' => $structuredOutput,
                'schema_valid' => $schemaValid,
                'human_reviewed' => false,
                'latency_ms' => $execResult['latency_ms'],
                'tokens_used' => $execResult['tokens_used'],
                'cost_minor' => $estimatedCostMinor,
                'status' => 'SUCCEEDED',
                'correlation_id' => $this->tenantContext->getCorrelationId(),
                'entity_type' => isset($options['entity_type']) ? (string) $options['entity_type'] : null,
                'entity_id' => isset($options['entity_id']) && is_numeric($options['entity_id']) ? (int) $options['entity_id'] : null,
            ]);

            $this->auditService->record(
                module: 'ai_gateway',
                action: 'ai.task_executed',
                entityType: 'ai_task_run',
                entityId: (string) $run->id,
                metadata: [
                    'task_type' => $taskType,
                    'provider_key' => $activeProviderConfig->provider_key,
                    'primary_provider_key' => $primaryProviderKey,
                    'fallback_used' => $fallbackUsed,
                    'execution_mode' => $execResult['execution_mode'],
                    'model_name' => $activeProviderConfig->model_name,
                    'prompt_version' => $run->prompt_version,
                    'schema_valid' => $schemaValid,
                    'cost_minor' => $estimatedCostMinor,
                ],
                tenantId: $tenantId,
                actorUserId: $actor ? (int) $actor->id : null,
            );

            return $run;
        });
    }

    /**
     * @param  array<int, string>  $requiredKeys
     * @param  array<string, mixed>  $options
     * @return array{structured_output: array<string, mixed>, tokens_used: int, latency_ms: int, execution_mode: string, retry_count: int}
     */
    private function invokeAdapterOrLocalAnalyzer(
        AiProviderConfig $config,
        string $taskType,
        string $systemPrompt,
        string $redactedInput,
        array $requiredKeys,
        array $options
    ): array {
        $adapter = $this->getAdapter($config->provider_key);
        $requireLiveCredentials = (bool) ($options['require_live_credentials'] ?? ($config->settings['require_live_credentials'] ?? false));

        if ($config->provider_key === 'deterministic_fake') {
            return $adapter->executeStructuredTask($config, $taskType, $systemPrompt, $redactedInput, $requiredKeys, $options);
        }

        if ($config->hasConfiguredCredentials() || $requireLiveCredentials) {
            $result = $adapter->executeStructuredTask($config, $taskType, $systemPrompt, $redactedInput, $requiredKeys, $options);
            $config->update([
                'connection_status' => AiProviderConfig::STATUS_CONNECTED,
                'last_validated_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ]);

            return $result;
        }

        // When no external cloud API key is configured and strict live mode is not forced, execute via the local governed document analyzer
        $localAdapter = $this->getAdapter('deterministic_fake');

        return $localAdapter->executeStructuredTask($config, $taskType, $systemPrompt, $redactedInput, $requiredKeys, $options);
    }

    /**
     * @param  array<string, mixed>  $structuredOutput
     * @param  array<int, string>  $requiredKeys
     */
    public function validateStructuredSchema(array $structuredOutput, array $requiredKeys): bool
    {
        foreach ($requiredKeys as $reqKey) {
            if (! array_key_exists($reqKey, $structuredOutput)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $rawOutput
     */
    private function recordFailedTaskRun(
        int $tenantId,
        ?User $actor,
        string $taskType,
        AiProviderConfig $config,
        ?AiPromptVersion $promptVersion,
        string $redactedInput,
        RuntimeException $error,
        ?array $rawOutput = null,
        bool $schemaValid = false
    ): void {
        $parts = explode(':', $error->getMessage(), 2);
        $errorCode = trim($parts[0]) ?: 'AI_EXECUTION_FAILED';
        $sanitizedMessage = substr(trim($parts[1] ?? $error->getMessage()), 0, 240);

        $run = AiTaskRun::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'user_id' => $actor?->id,
            'task_type' => $taskType,
            'provider_key' => $config->provider_key,
            'model_name' => $config->model_name,
            'execution_mode' => 'failed',
            'fallback_used' => false,
            'primary_provider_key' => $config->provider_key,
            'retry_count' => (int) ($config->max_retries ?? 0),
            'prompt_version' => $promptVersion?->version ?? 1,
            'input_hash' => hash('sha256', $redactedInput),
            'redacted_input_summary' => substr($redactedInput, 0, 500),
            'structured_output' => $rawOutput,
            'schema_valid' => $schemaValid,
            'human_reviewed' => false,
            'latency_ms' => 15,
            'tokens_used' => 0,
            'cost_minor' => 0,
            'status' => 'FAILED',
            'error_code' => substr($errorCode, 0, 60),
            'error_message' => $sanitizedMessage,
            'correlation_id' => $this->tenantContext->getCorrelationId(),
        ]);

        $this->auditService->record(
            module: 'ai_gateway',
            action: 'ai.task_failed',
            outcome: 'FAILED',
            errorCode: substr($errorCode, 0, 60),
            entityType: 'ai_task_run',
            entityId: (string) $run->id,
            metadata: [
                'task_type' => $taskType,
                'provider_key' => $config->provider_key,
                'error_code' => substr($errorCode, 0, 60),
            ],
            tenantId: $tenantId,
            actorUserId: $actor ? (int) $actor->id : null,
        );
    }

    public function reviewTaskRun(AiTaskRun $run, User $reviewer): AiTaskRun
    {
        $run->update([
            'human_reviewed' => true,
            'reviewed_by_user_id' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $this->auditService->record(
            module: 'ai_gateway',
            action: 'ai.task_human_reviewed',
            entityType: 'ai_task_run',
            entityId: (string) $run->id,
            metadata: ['task_type' => $run->task_type],
            tenantId: (int) $run->tenant_id,
            actorUserId: (int) $reviewer->id,
        );

        return $run->fresh(['reviewer']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateProviderConfig(AiProviderConfig $config, array $data, User $actor): AiProviderConfig
    {
        $adapter = $this->getAdapter($config->provider_key);
        $globalCap = $this->config()->getInt('ai.global_monthly_budget_cap_minor');
        $requestedBudget = (int) ($data['monthly_budget_minor'] ?? $config->monthly_budget_minor);
        $mandatoryRedaction = $this->config()->getBool('ai.mandatory_pii_redaction');

        if (! empty($data['model_name'])) {
            $requestedModel = (string) $data['model_name'];
            if (! in_array($requestedModel, $adapter->getSupportedModels(), true)) {
                throw new InvalidArgumentException("AI_MODEL_INCOMPATIBLE: Model '{$requestedModel}' is not supported by {$adapter->getDisplayName()}.");
            }
            $config->model_name = $requestedModel;
        }

        if (array_key_exists('api_key', $data) && $data['api_key'] !== null && trim((string) $data['api_key']) !== '') {
            $config->setPlaintextApiKey((string) $data['api_key']);
            $config->connection_status = AiProviderConfig::STATUS_CONNECTED;
            $config->last_error_code = null;
            $config->last_error_message = null;
        } elseif (! empty($data['clear_api_key'])) {
            $config->setPlaintextApiKey(null);
            $config->connection_status = $config->provider_key === 'deterministic_fake'
                ? AiProviderConfig::STATUS_BUILT_IN
                : AiProviderConfig::STATUS_UNCONFIGURED;
        }

        if (array_key_exists('endpoint_url', $data) && ! empty($data['endpoint_url'])) {
            $config->endpoint_url = (string) $data['endpoint_url'];
        }

        if (array_key_exists('fallback_provider_key', $data)) {
            $fb = trim((string) $data['fallback_provider_key']);
            if ($fb !== '') {
                $this->getAdapter($fb);
            }
            $config->fallback_provider_key = $fb !== '' ? $fb : null;
        }

        if (isset($data['timeout_seconds'])) {
            $config->timeout_seconds = max(3, min(120, (int) $data['timeout_seconds']));
        }
        if (isset($data['max_retries'])) {
            $config->max_retries = max(0, min(5, (int) $data['max_retries']));
        }
        if (isset($data['rate_limit_rpm'])) {
            $config->rate_limit_rpm = max(1, min(10000, (int) $data['rate_limit_rpm']));
        }
        if (isset($data['is_primary']) && (bool) $data['is_primary']) {
            AiProviderConfig::withoutGlobalScopes()
                ->where('tenant_id', $config->tenant_id)
                ->where('id', '!=', $config->id)
                ->update(['is_primary' => false]);
            $config->is_primary = true;
        }

        $config->kill_switch_engaged = (bool) ($data['kill_switch_engaged'] ?? false);
        $config->is_enabled = (bool) ($data['is_enabled'] ?? true);
        $config->monthly_budget_minor = min($requestedBudget, $globalCap);
        $config->redaction_enabled = $mandatoryRedaction ? true : (bool) ($data['redaction_enabled'] ?? true);
        $config->save();

        $this->auditService->record(
            module: 'ai_gateway',
            action: 'ai.provider_config_updated',
            entityType: 'ai_provider_config',
            entityId: (string) $config->id,
            metadata: [
                'provider_key' => $config->provider_key,
                'model_name' => $config->model_name,
                'kill_switch_engaged' => $config->kill_switch_engaged,
                'monthly_budget_minor' => $config->monthly_budget_minor,
                'credential_updated' => ! empty($data['api_key']),
                'fallback_provider_key' => $config->fallback_provider_key,
            ],
            tenantId: (int) $config->tenant_id,
            actorUserId: (int) $actor->id,
        );

        return $config;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTaskRoute(AiTaskRoute $route, array $data, User $actor): AiTaskRoute
    {
        $primaryProvider = (string) ($data['primary_provider_key'] ?? $route->primary_provider_key);
        $primaryAdapter = $this->getAdapter($primaryProvider);
        $primaryModel = (string) ($data['primary_model_name'] ?? $route->primary_model_name);

        if (! in_array($primaryModel, $primaryAdapter->getSupportedModels(), true)) {
            $primaryModel = $primaryAdapter->getSupportedModels()[0];
        }

        $fallbackProvider = ! empty($data['fallback_provider_key']) ? (string) $data['fallback_provider_key'] : null;
        $fallbackModel = null;
        if ($fallbackProvider !== null) {
            $fbAdapter = $this->getAdapter($fallbackProvider);
            $requestedFbModel = (string) ($data['fallback_model_name'] ?? '');
            $fallbackModel = in_array($requestedFbModel, $fbAdapter->getSupportedModels(), true)
                ? $requestedFbModel
                : $fbAdapter->getSupportedModels()[0];
        }

        $route->update([
            'primary_provider_key' => $primaryProvider,
            'primary_model_name' => $primaryModel,
            'fallback_provider_key' => $fallbackProvider,
            'fallback_model_name' => $fallbackModel,
            'timeout_seconds' => max(3, min(120, (int) ($data['timeout_seconds'] ?? $route->timeout_seconds))),
            'max_retries' => max(0, min(5, (int) ($data['max_retries'] ?? $route->max_retries))),
            'quality_threshold' => max(0.0, min(1.0, (float) ($data['quality_threshold'] ?? $route->quality_threshold))),
            'require_structured_output' => (bool) ($data['require_structured_output'] ?? true),
            'require_human_review' => (bool) ($data['require_human_review'] ?? true),
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
            'updated_by_user_id' => $actor->id,
        ]);

        $this->auditService->record(
            module: 'ai_gateway',
            action: 'ai.task_route_updated',
            entityType: 'ai_task_route',
            entityId: (string) $route->id,
            metadata: [
                'task_type' => $route->task_type,
                'primary_provider_key' => $route->primary_provider_key,
                'primary_model_name' => $route->primary_model_name,
                'fallback_provider_key' => $route->fallback_provider_key,
            ],
            tenantId: (int) $route->tenant_id,
            actorUserId: (int) $actor->id,
        );

        return $route->fresh();
    }

    /**
     * Create a new versioned prompt template for a task in the active tenant.
     *
     * @param  array<string, mixed>  $data
     */
    public function createPromptVersion(int $tenantId, array $data, User $actor): AiPromptVersion
    {
        $taskType = (string) $data['task_type'];
        if (! array_key_exists($taskType, self::ALLOWED_TASKS)) {
            throw new InvalidArgumentException("Task type '{$taskType}' is not an allowed AI task.");
        }

        return DB::transaction(function () use ($tenantId, $taskType, $data, $actor): AiPromptVersion {
            $latestVersion = (int) AiPromptVersion::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('task_type', $taskType)
                ->max('version');

            AiPromptVersion::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('task_type', $taskType)
                ->update(['is_active' => false]);

            $prompt = AiPromptVersion::withoutGlobalScopes()->create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'task_type' => $taskType,
                'version' => $latestVersion + 1,
                'system_prompt' => trim((string) $data['system_prompt']),
                'output_schema' => ['required' => self::ALLOWED_TASKS[$taskType]['required_keys']],
                'is_active' => true,
                'created_by_user_id' => $actor->id,
            ]);

            $this->auditService->record(
                module: 'ai_gateway',
                action: 'ai.prompt_version_created',
                entityType: 'ai_prompt_version',
                entityId: (string) $prompt->id,
                metadata: [
                    'task_type' => $taskType,
                    'version' => $prompt->version,
                ],
                tenantId: $tenantId,
                actorUserId: (int) $actor->id,
            );

            return $prompt;
        });
    }

    public function redactPiiAndSecrets(string $text, bool $redactEmail = true): string
    {
        $redacted = $redactEmail
            ? (string) preg_replace('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', '[REDACTED_EMAIL]', $text)
            : $text;
        $redacted = (string) preg_replace('/\b\d{3}[-.]?\d{2}[-.]?\d{4}\b/', '[REDACTED_SSN]', $redacted);
        $redacted = (string) preg_replace('/(sk-[a-zA-Z0-9\-_]{10,}|github_pat_[a-zA-Z0-9_]+|AIza[0-9A-Za-z\-_]{20,})/', '[REDACTED_SECRET]', $redacted);

        return $redacted;
    }
}
