<?php

declare(strict_types=1);

namespace App\Services\Ai\Adapters;

use App\Models\AiProviderConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class GoogleGeminiAdapter extends AbstractHttpProviderAdapter
{
    public function getProviderKey(): string
    {
        return 'gemini';
    }

    public function getDisplayName(): string
    {
        return 'Google Gemini';
    }

    public function getDefaultEndpoint(): string
    {
        return 'https://generativelanguage.googleapis.com/v1beta/models';
    }

    public function getSupportedModels(): array
    {
        return [
            'gemini-2.5-pro',
            'gemini-2.5-flash',
            'gemini-2.0-flash',
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
                'message' => 'Google Gemini API key is not configured.',
                'latency_ms' => 0,
            ];
        }

        $base = rtrim($config->endpoint_url ?: $this->getDefaultEndpoint(), '/');
        $model = $config->model_name ?: 'gemini-2.5-pro';
        $url = str_contains($base, ':generateContent') ? $base : "{$base}/{$model}:generateContent";
        $timeout = max(3, min(60, (int) ($config->timeout_seconds ?: 10)));
        $start = microtime(true);

        try {
            $resp = Http::timeout($timeout)
                ->withHeaders([
                    'x-goog-api-key' => $apiKey,
                    'content-type' => 'application/json',
                ])
                ->post($url, [
                    'contents' => [
                        ['role' => 'user', 'parts' => [['text' => 'Return {"ok":true} as JSON.']]],
                    ],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'maxOutputTokens' => 16,
                    ],
                ]);

            $latency = max(1, (int) round((microtime(true) - $start) * 1000));
            if ($resp->successful()) {
                return [
                    'connected' => true,
                    'status' => AiProviderConfig::STATUS_CONNECTED,
                    'error_code' => null,
                    'message' => 'Google Gemini connection and credentials verified.',
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
                'message' => 'Connection to Google Gemini endpoint timed out.',
                'latency_ms' => max(1, (int) round((microtime(true) - $start) * 1000)),
            ];
        } catch (Throwable) {
            return [
                'connected' => false,
                'status' => AiProviderConfig::STATUS_DEGRADED,
                'error_code' => 'PROVIDER_UNAVAILABLE',
                'message' => 'Unable to reach Google Gemini endpoint.',
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
            throw new RuntimeException('AI_PROVIDER_CREDENTIALS_MISSING: Google Gemini API key is not configured.', 422);
        }

        $model = (string) ($options['model_name'] ?? $config->model_name ?: 'gemini-2.5-pro');
        if (! in_array($model, $this->getSupportedModels(), true)) {
            throw new RuntimeException("MODEL_INCOMPATIBLE: Model '{$model}' is not supported by Google Gemini adapter.", 422);
        }

        $base = rtrim($config->endpoint_url ?: $this->getDefaultEndpoint(), '/');
        $url = str_contains($base, ':generateContent') ? $base : "{$base}/{$model}:generateContent";
        $timeout = max(3, min(120, (int) ($options['timeout_seconds'] ?? $config->timeout_seconds ?: 15)));
        $maxRetries = max(0, min(5, (int) ($options['max_retries'] ?? $config->max_retries ?? 2)));
        $maxTokens = max(128, min(8192, (int) ($options['max_output_tokens'] ?? 2000)));
        $schemaHint = 'Respond strictly with a valid JSON object containing keys: '.implode(', ', $requiredSchemaKeys).'.';

        return $this->executeWithBoundedRetries($config, function () use ($url, $apiKey, $timeout, $maxTokens, $systemPrompt, $schemaHint, $userPrompt): array {
            $resp = Http::timeout($timeout)
                ->withHeaders([
                    'x-goog-api-key' => $apiKey,
                    'content-type' => 'application/json',
                ])
                ->post($url, [
                    'systemInstruction' => [
                        'parts' => [['text' => $systemPrompt."\n\n".$schemaHint]],
                    ],
                    'contents' => [
                        ['role' => 'user', 'parts' => [['text' => $userPrompt]]],
                    ],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'maxOutputTokens' => $maxTokens,
                    ],
                ]);

            $json = $resp->json();
            $rawText = is_array($json) ? (string) ($json['candidates'][0]['content']['parts'][0]['text'] ?? '') : '';
            $tokens = is_array($json) ? (int) ($json['usageMetadata']['totalTokenCount'] ?? 0) : 0;

            return [
                'http_status' => $resp->status(),
                'body' => is_array($json) ? $json : null,
                'raw_text' => $rawText,
                'tokens_used' => max(1, $tokens),
            ];
        }, $maxRetries);
    }
}
