<?php

declare(strict_types=1);

namespace App\Services\Ai\Adapters;

use App\Contracts\AiProviderAdapterInterface;
use App\Models\AiProviderConfig;
use App\Services\Ai\ResumeContentAnalyzer;

class DeterministicStructuredAdapter implements AiProviderAdapterInterface
{
    public function __construct(
        private readonly ResumeContentAnalyzer $analyzer,
    ) {}

    public function getProviderKey(): string
    {
        return 'deterministic_fake';
    }

    public function getDisplayName(): string
    {
        return 'Built-In Structured Rule Analyzer';
    }

    public function getDefaultEndpoint(): string
    {
        return 'internal://governed-structured-analyzer';
    }

    public function getSupportedModels(): array
    {
        return [
            'urap-deterministic-v1',
        ];
    }

    public function getSupportedCapabilities(): array
    {
        return [
            'resume.extract',
            'resume_extraction',
            'candidate.match',
            'candidate_screening',
            'candidate.match.explanation',
            'job.description.generate',
            'job_description_draft',
            'interview.summary',
            'interview_guide_generation',
            'interview_feedback_synthesis',
            'offer.draft',
        ];
    }

    public function validateConnection(AiProviderConfig $config): array
    {
        return [
            'connected' => true,
            'status' => AiProviderConfig::STATUS_BUILT_IN,
            'error_code' => null,
            'message' => 'Built-in deterministic structured analyzer is active (no external cloud API key required).',
            'latency_ms' => 1,
        ];
    }

    public function executeStructuredTask(
        AiProviderConfig $config,
        string $taskType,
        string $systemPrompt,
        string $userPrompt,
        array $requiredSchemaKeys,
        array $options = []
    ): array {
        $start = microtime(true);

        $output = match ($taskType) {
            'resume.extract', 'resume_extraction' => array_merge(
                ['provider_adapter' => $config->provider_key],
                $this->analyzer->extractStructuredResume($userPrompt)
            ),
            'candidate.match', 'candidate.match.explanation', 'candidate_screening' => array_merge(
                ['provider_adapter' => $config->provider_key],
                $this->analyzer->evaluateCandidateMatch(
                    is_array($options['resume_data'] ?? null) ? $options['resume_data'] : [],
                    is_array($options['job_requirements'] ?? null) ? $options['job_requirements'] : [],
                    $userPrompt
                )
            ),
            'job.description.generate', 'job_description_draft' => [
                'provider_adapter' => $config->provider_key,
                'suggested_title' => 'Senior Distributed Systems & Platform Engineer',
                'summary' => 'Design and operate high-reliability multi-tenant backend services with strict security and auditability.',
                'responsibilities' => [
                    'Architect resilient transactional workflows and idempotent enterprise integrations.',
                    'Maintain strict tenant isolation, RBAC/ABAC policies, and observability metrics.',
                ],
                'inclusive_language_notes' => 'Verified gender-neutral phrasing and competency-focused requirements.',
            ],
            'interview_guide_generation' => [
                'provider_adapter' => $config->provider_key,
                'competencies' => ['Transactional Integrity', 'Multi-Tenant Security', 'API Idempotency'],
                'structured_questions' => [
                    'How do you prevent duplicate side effects when an external ERP call times out ambiguously?',
                    'Walk through how you enforce tenant isolation across both web and background queue workers.',
                ],
                'rubric_anchors' => [
                    'Strong (4-5): Explains transactional outbox, idempotency keys, and reconciliation before retry.',
                ],
            ],
            'offer.draft' => [
                'provider_adapter' => $config->provider_key,
                'offer_summary' => 'Competitive total rewards package aligned with approved requisition band.',
                'compensation_narrative' => 'Includes base salary within approved requisition band and standard enterprise benefits.',
                'onboarding_prerequisites' => [
                    'Accepted candidate offer',
                    'Completed post-offer HR validation checklist',
                    'Explicit HR/onboarding approver sign-off prior to Dynamics 365 handover',
                ],
            ],
            default => [
                'provider_adapter' => $config->provider_key,
                'consensus_strengths' => ['Strong systems engineering fundamentals and clear communication.'],
                'consensus_concerns' => ['Clarify preferred start date window.'],
                'open_questions_for_debrief' => ['Confirm team placement between Core Platform and Integrations.'],
            ],
        };

        $latencyMs = max(1, (int) round((microtime(true) - $start) * 1000));

        return [
            'structured_output' => $output,
            'tokens_used' => max(64, (int) ceil(strlen($userPrompt) / 4)),
            'latency_ms' => $latencyMs,
            'execution_mode' => 'local_governed_analyzer',
            'retry_count' => 0,
        ];
    }
}
