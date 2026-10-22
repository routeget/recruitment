<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\AiProviderConfig;

interface AiProviderAdapterInterface
{
    public function getProviderKey(): string;

    public function getDisplayName(): string;

    public function getDefaultEndpoint(): string;

    /**
     * @return array<int, string>
     */
    public function getSupportedModels(): array;

    /**
     * @return array<int, string>
     */
    public function getSupportedCapabilities(): array;

    /**
     * Validate provider credentials and connectivity without leaking secrets.
     *
     * @return array{connected: bool, status: string, error_code: ?string, message: string, latency_ms: int}
     */
    public function validateConnection(AiProviderConfig $config): array;

    /**
     * Execute a governed structured AI task against the provider.
     *
     * @param  array<int, string>  $requiredSchemaKeys
     * @param  array<string, mixed>  $options
     * @return array{structured_output: array<string, mixed>, tokens_used: int, latency_ms: int, execution_mode: string, retry_count: int}
     */
    public function executeStructuredTask(
        AiProviderConfig $config,
        string $taskType,
        string $systemPrompt,
        string $userPrompt,
        array $requiredSchemaKeys,
        array $options = []
    ): array;
}
