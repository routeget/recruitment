<?php

declare(strict_types=1);

namespace App\Services\Ai\Adapters;

use App\Models\AiProviderConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class AnthropicClaudeAdapter extends AbstractHttpProviderAdapter
{
    public function getProviderKey(): string
    {
        return 'claude';
    }

    public function getDisplayName(): string
    {
        return 'Anthropic Claude';
    }

    public function getDefaultEndpoint(): string
    {
        return 'https://api.anthropic.com/v1/messages';
    }

    public function getSupportedModels(): array
    {
        return [
            'claude-3-7-sonnet',
            'claude-3-5-sonnet-latest',
            'claude-3-5-haiku-latest',
        ];
    }

    public function validateConnection(AiProviderConfig $config): array
    {
        $apiKey = $config->getDecryptedApiKey();
        if ($apiKey === null) {
            return [
                'connected' => false,
                'status' => AiProviderConfig::STATUS_UNCONFIGURED,
                'error_code' => 'MISSING_CREDENTIALS',
                'message' => 'Anthropic Claude API key is not configured.',
                'latency_ms' => 0,
            ];
        }

        $endpoint = $config->endpoint_url ?: $this->getDefaultEndpoint();
        $timeout = max(3, min(60, (int) ($config->timeout_seconds ?: 10)));
        $start = microtime(true);

        try {
            $resp = Http::timeout($timeout)
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post($endpoint, [
                    'model' => $config->model_name ?: 'claude-3-7-sonnet',
                    'max_tokens' => 16,
                    'messages' => [
                        ['role' => 'user', 'content' => 'Return {"ok":true} as JSON.'],
                    ],
                ]);

            $latency = max(1, (int) round((microtime(true) - $start) * 1000));
            if ($resp->successful()) {
                return [
                    'connected' => true,
                    'status' => AiProviderConfig::STATUS_CONNECTED,
                    'error_code' => null,
                    'message' => 'Anthropic Claude connection and credentials verified.',
                    'latency_ms' => $latency,
                ];
            }

            $failure = $this->normalizeHttpFailure($resp->status());

            return [
                'connected' => false,
                'status' => $failure['status'],
                'error_code' => $failure['error_code'],
                'message' => $failure['message'],
                'latency_ms' => $latency,
            ];
        } catch (ConnectionException) {
            return [
                'connected' => false,
                'status' => AiProviderConfig::STATUS_DEGRADED,
                'error_code' => 'PROVIDER_TIMEOUT',
                'message' => 'Connection to Anthropic Claude endpoint timed out.',
                'latency_ms' => max(1, (int) round((microtime(true) - $start) * 1000)),
            ];
        } catch (Throwable) {
            return [
                'connected' => false,
                'status' => AiProviderConfig::STATUS_DEGRADED,
                'error_code' => 'PROVIDER_UNAVAILABLE',
                'message' => 'Unable to reach Anthropic Claude endpoint.',
                'latency_ms' => max(1, (int) round((microtime(true) - $start) * 1000)),
            ];
        }
    }

    public function executeStructuredTask(
        AiProviderConfig $config,
        string $taskType,
        string $systemPrompt,
        string $userPrompt,
        array $requiredSchemaKeys,
        array $options = []
    ): array {
        $apiKey = $config->getDecryptedApiKey();
        if ($apiKey === null) {
            throw new RuntimeException('AI_PROVIDER_CREDENTIALS_MISSING: Anthropic Claude API key is not configured.', 422);
        }

        $endpoint = $config->endpoint_url ?: $this->getDefaultEndpoint();
        $model = (string) ($options['model_name'] ?? $config->model_name ?: 'claude-3-7-sonnet');
        if (! in_array($model, $this->getSupportedModels(), true)) {
            throw new RuntimeException("MODEL_INCOMPATIBLE: Model '{$model}' is not supported by Anthropic Claude adapter.", 422);
        }

        $timeout = max(3, min(120, (int) ($options['timeout_seconds'] ?? $config->timeout_seconds ?: 15)));
        $maxRetries = max(0, min(5, (int) ($options['max_retries'] ?? $config->max_retries ?? 2)));
        $maxTokens = max(128, min(8192, (int) ($options['max_output_tokens'] ?? 2000)));
        $schemaHint = 'Respond strictly with a valid JSON object containing keys: '.implode(', ', $requiredSchemaKeys).'.';

        return $this->executeWithBoundedRetries($config, function () use ($endpoint, $apiKey, $model, $timeout, $maxTokens, $systemPrompt, $schemaHint, $userPrompt): array {
            $resp = Http::timeout($timeout)
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post($endpoint, [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'system' => $systemPrompt."\n\n".$schemaHint,
                    'messages' => [
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                ]);

            $json = $resp->json();
            $rawText = is_array($json) ? (string) ($json['content'][0]['text'] ?? '') : '';
            $inTokens = is_array($json) ? (int) ($json['usage']['input_tokens'] ?? 0) : 0;
            $outTokens = is_array($json) ? (int) ($json['usage']['output_tokens'] ?? 0) : 0;

            return [
                'http_status' => $resp->status(),
                'body' => is_array($json) ? $json : null,
                'raw_text' => $rawText,
                'tokens_used' => max(1, $inTokens + $outTokens),
            ];
        }, $maxRetries);
    }
}
