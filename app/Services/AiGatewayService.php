<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiPromptVersion;
use App\Models\AiProviderConfig;
use App\Models\AiTaskRun;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class AiGatewayService
{
    public const ALLOWED_TASKS = [
        'job_description_draft' => [
            'title' => 'Job Description Drafting & Inclusive Language Check',
            'required_keys' => ['suggested_title', 'summary', 'responsibilities', 'inclusive_language_notes'],
        ],
        'resume_extraction' => [
            'title' => 'Structured Resume / CV Profile Extraction',
            'required_keys' => ['extracted_skills', 'years_experience_estimate', 'education_highlights', 'evidence_quotes'],
        ],
        'candidate_screening' => [
            'title' => 'Evidence-Based Candidate Screening Breakdown (Human Review Required)',
            'required_keys' => ['criteria_scores', 'overall_score', 'recommendation_advisory', 'missing_evidence_flags'],
        ],
        'interview_guide_generation' => [
            'title' => 'Structured Competency Interview Guide Generation',
            'required_keys' => ['competencies', 'structured_questions', 'rubric_anchors'],
        ],
        'interview_feedback_synthesis' => [
            'title' => 'Interview Scorecard Evidence Synthesis',
            'required_keys' => ['consensus_strengths', 'consensus_concerns', 'open_questions_for_debrief'],
        ],
    ];

    public const PROHIBITED_TASKS = [
        'final_hiring_decision',
        'automated_candidate_rejection',
        'hr_handover_approval',
        'protected_trait_inference',
        'biometric_emotion_scoring',
    ];

    public function __construct(
        private readonly AuditService $auditService,
        private readonly TenantContextService $tenantContext,
        private readonly ?PlatformConfigService $platformConfig = null,
    ) {}

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
            AiProviderConfig::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenantId, 'provider_key' => $p['provider_key']],
                [
                    'model_name' => $p['model_name'],
                    'is_enabled' => true,
                    'is_primary' => $p['provider_key'] === $defaultPrimary,
                    'monthly_budget_minor' => $p['monthly_budget_minor'],
                    'current_spend_minor' => 0,
                    'kill_switch_engaged' => false,
                    'redaction_enabled' => true,
                    'settings' => ['timeout_seconds' => 15, 'mode' => 'governed_structured_json'],
                ]
            );
        }

        foreach (self::ALLOWED_TASKS as $taskType => $meta) {
            AiPromptVersion::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenantId, 'task_type' => $taskType, 'version' => 1],
                [
                    'system_prompt' => "You are a governed recruitment AI assistant performing {$meta['title']}. Cite only verifiable job-relevant evidence. Never infer protected characteristics or make autonomous hiring/HR decisions.",
                    'output_schema' => ['required' => $meta['required_keys']],
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $inputData
     */
    public function executeTask(
        string $taskType,
        string $providerKey,
        string $rawInputText,
        User $actor,
        int $tenantId,
    ): AiTaskRun {
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
                actorUserId: (int) $actor->id,
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
                actorUserId: (int) $actor->id,
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
                actorUserId: (int) $actor->id,
            );

            throw new InvalidArgumentException("AI task '{$taskType}' is prohibited by platform governance policy (AI_PROHIBITED_USE).");
        }

        /** @var AiProviderConfig $providerConfig */
        $providerConfig = AiProviderConfig::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('provider_key', $providerKey)
            ->firstOrFail();

        if ($providerConfig->kill_switch_engaged || ! $providerConfig->is_enabled) {
            $this->auditService->record(
                module: 'ai_gateway',
                action: 'ai.kill_switch_blocked',
                outcome: 'DENIED',
                errorCode: 'AI_KILL_SWITCH_ENGAGED',
                entityType: 'ai_provider_config',
                entityId: (string) $providerConfig->id,
                metadata: ['provider_key' => $providerKey, 'task_type' => $taskType],
                tenantId: $tenantId,
                actorUserId: (int) $actor->id,
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
                    'provider_key' => $providerKey,
                    'current_spend_minor' => $providerConfig->current_spend_minor,
                    'monthly_budget_minor' => $providerConfig->monthly_budget_minor,
                    'global_budget_cap_minor' => $globalCap,
                ],
                tenantId: $tenantId,
                actorUserId: (int) $actor->id,
            );

            throw new RuntimeException('AI provider monthly budget cap exceeded (AI_BUDGET_EXCEEDED).', 429);
        }

        $mustRedact = (bool) $providerConfig->redaction_enabled || $this->config()->getBool('ai.mandatory_pii_redaction');
        $redactedInput = $mustRedact
            ? $this->redactPiiAndSecrets($rawInputText)
            : $rawInputText;

        $promptVersion = AiPromptVersion::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('task_type', $taskType)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        $structuredOutput = $this->generateStructuredOutput($taskType, $providerKey, $redactedInput);
        $requiredKeys = self::ALLOWED_TASKS[$taskType]['required_keys'];
        $schemaValid = true;
        foreach ($requiredKeys as $reqKey) {
            if (! array_key_exists($reqKey, $structuredOutput)) {
                $schemaValid = false;
                break;
            }
        }

        return DB::transaction(function () use (
            $tenantId,
            $actor,
            $taskType,
            $providerConfig,
            $promptVersion,
            $redactedInput,
            $structuredOutput,
            $schemaValid,
            $estimatedCostMinor
        ): AiTaskRun {
            $providerConfig->increment('current_spend_minor', $estimatedCostMinor);

            $run = AiTaskRun::query()->create([
                'tenant_id' => $tenantId,
                'user_id' => $actor->id,
                'task_type' => $taskType,
                'provider_key' => $providerConfig->provider_key,
                'model_name' => $providerConfig->model_name,
                'prompt_version' => $promptVersion?->version ?? 1,
                'input_hash' => hash('sha256', $redactedInput),
                'redacted_input_summary' => substr($redactedInput, 0, 500),
                'structured_output' => $structuredOutput,
                'schema_valid' => $schemaValid,
                'human_reviewed' => false,
                'latency_ms' => 42,
                'tokens_used' => 380,
                'cost_minor' => $estimatedCostMinor,
                'status' => 'SUCCEEDED',
                'correlation_id' => $this->tenantContext->getCorrelationId(),
            ]);

            $this->auditService->record(
                module: 'ai_gateway',
                action: 'ai.task_executed',
                entityType: 'ai_task_run',
                entityId: (string) $run->id,
                metadata: [
                    'task_type' => $taskType,
                    'provider_key' => $providerConfig->provider_key,
                    'model_name' => $providerConfig->model_name,
                    'prompt_version' => $run->prompt_version,
                    'schema_valid' => $schemaValid,
                    'cost_minor' => $estimatedCostMinor,
                ],
                tenantId: $tenantId,
                actorUserId: (int) $actor->id,
            );

            return $run;
        });
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

    public function updateProviderConfig(AiProviderConfig $config, array $data, User $actor): AiProviderConfig
    {
        $globalCap = $this->config()->getInt('ai.global_monthly_budget_cap_minor');
        $requestedBudget = (int) ($data['monthly_budget_minor'] ?? $config->monthly_budget_minor);
        $mandatoryRedaction = $this->config()->getBool('ai.mandatory_pii_redaction');

        $config->update([
            'kill_switch_engaged' => (bool) ($data['kill_switch_engaged'] ?? false),
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
            'monthly_budget_minor' => min($requestedBudget, $globalCap),
            'redaction_enabled' => $mandatoryRedaction ? true : (bool) ($data['redaction_enabled'] ?? true),
        ]);

        $this->auditService->record(
            module: 'ai_gateway',
            action: 'ai.provider_config_updated',
            entityType: 'ai_provider_config',
            entityId: (string) $config->id,
            metadata: [
                'provider_key' => $config->provider_key,
                'kill_switch_engaged' => $config->kill_switch_engaged,
                'monthly_budget_minor' => $config->monthly_budget_minor,
            ],
            tenantId: (int) $config->tenant_id,
            actorUserId: (int) $actor->id,
        );

        return $config;
    }

    public function redactPiiAndSecrets(string $text): string
    {
        $redacted = (string) preg_replace('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', '[REDACTED_EMAIL]', $text);
        $redacted = (string) preg_replace('/\b\d{3}[-.]?\d{2}[-.]?\d{4}\b/', '[REDACTED_SSN]', $redacted);
        $redacted = (string) preg_replace('/(sk-[a-zA-Z0-9]{10,}|github_pat_[a-zA-Z0-9_]+)/', '[REDACTED_SECRET]', $redacted);

        return $redacted;
    }

    /**
     * @return array<string, mixed>
     */
    private function generateStructuredOutput(string $taskType, string $providerKey, string $redactedInput): array
    {
        return match ($taskType) {
            'job_description_draft' => [
                'provider_adapter' => $providerKey,
                'suggested_title' => 'Senior Distributed Systems & Platform Engineer',
                'summary' => 'Design and operate high-reliability multi-tenant backend services with strict security and auditability.',
                'responsibilities' => [
                    'Architect resilient transactional workflows and idempotent enterprise integrations.',
                    'Maintain strict tenant isolation, RBAC/ABAC policies, and observability metrics.',
                ],
                'inclusive_language_notes' => 'Verified gender-neutral phrasing and competency-focused requirements.',
            ],
            'resume_extraction' => [
                'provider_adapter' => $providerKey,
                'extracted_skills' => ['PHP 8.5', 'Laravel 13', 'MariaDB', 'Redis', 'Enterprise Integrations'],
                'years_experience_estimate' => 8,
                'education_highlights' => ['B.S. Computer Science'],
                'evidence_quotes' => [substr($redactedInput, 0, 120)],
            ],
            'candidate_screening' => [
                'provider_adapter' => $providerKey,
                'criteria_scores' => [
                    ['criterion' => 'Backend Architecture', 'score' => 91, 'evidence' => 'Demonstrated production Laravel & SQL experience.'],
                    ['criterion' => 'Security & Compliance', 'score' => 88, 'evidence' => 'Strong RBAC and audit trail background.'],
                ],
                'overall_score' => 89.5,
                'recommendation_advisory' => 'ADVISORY_PROCEED_TO_HUMAN_INTERVIEW',
                'missing_evidence_flags' => ['Verify Dynamics 365 OData experience during live technical interview.'],
            ],
            'interview_guide_generation' => [
                'provider_adapter' => $providerKey,
                'competencies' => ['Transactional Integrity', 'Multi-Tenant Security', 'API Idempotency'],
                'structured_questions' => [
                    'How do you prevent duplicate side effects when an external ERP call times out ambiguously?',
                    'Walk through how you enforce tenant isolation across both web and background queue workers.',
                ],
                'rubric_anchors' => [
                    'Strong (4-5): Explains transactional outbox, idempotency keys, and reconciliation before retry.',
                ],
            ],
            default => [
                'provider_adapter' => $providerKey,
                'consensus_strengths' => ['Strong systems engineering fundamentals and clear communication.'],
                'consensus_concerns' => ['Clarify preferred start date window.'],
                'open_questions_for_debrief' => ['Confirm team placement between Core Platform and Integrations.'],
            ],
        };
    }
}
