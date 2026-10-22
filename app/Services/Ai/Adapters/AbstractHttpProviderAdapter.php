<?php

declare(strict_types=1);

namespace App\Services\Ai\Adapters;

use App\Contracts\AiProviderAdapterInterface;
use App\Models\AiProviderConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

abstract class AbstractHttpProviderAdapter implements AiProviderAdapterInterface
{
    /**
     * @return array<int, string>
     */
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

    /**
     * Normalize HTTP status codes into sanitized error codes and connection states.
     *
     * @return array{status: string, error_code: string, message: string, retryable: bool}
     */
    protected function normalizeHttpFailure(int $httpStatus): array
    {
        if ($httpStatus === 401 || $httpStatus === 403) {
            return [
                'status' => AiProviderConfig::STATUS_AUTH_FAILED,
                'error_code' => 'PROVIDER_AUTH_FAILED',
                'message' => 'Provider rejected credentials (invalid, expired, or revoked API key).',
                'retryable' => false,
            ];
        }

        if ($httpStatus === 429) {
            return [
                'status' => AiProviderConfig::STATUS_RATE_LIMITED,
                'error_code' => 'PROVIDER_RATE_LIMITED',
                'message' => 'Provider rate limit or quota reached (HTTP 429).',
                'retryable' => true,
            ];
        }

        if ($httpStatus >= 500 || $httpStatus === 408) {
            return [
                'status' => AiProviderConfig::STATUS_DEGRADED,
                'error_code' => 'PROVIDER_UNAVAILABLE',
                'message' => "Provider service temporarily unavailable (HTTP {$httpStatus}).",
                'retryable' => true,
            ];
        }

        return [
            'status' => AiProviderConfig::STATUS_DEGRADED,
            'error_code' => 'PROVIDER_REQUEST_FAILED',
            'message' => "Provider rejected request with status HTTP {$httpStatus}.",
            'retryable' => false,
        ];
    }

    /**
     * Decode JSON payload from model text response, stripping optional markdown fences.
     *
     * @return array<string, mixed>
     */
    protected function decodeStructuredJson(string $rawText): array
    {
        $cleaned = trim($rawText);
        if (str_starts_with($cleaned, '```')) {
            $cleaned = (string) preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
            $cleaned = (string) preg_replace('/\s*```$/', '', $cleaned);
            $cleaned = trim($cleaned);
        }

        $decoded = json_decode($cleaned, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('PROVIDER_MALFORMED_RESPONSE: Provider did not return valid structured JSON.', 502);
        }

        return $decoded;
    }

    /**
     * Execute an HTTP callback with bounded retries on transient failures.
     *
     * @param  callable(): array{http_status: int, body: array<string, mixed>|null, raw_text: ?string, tokens_used: int}  $requestFn
     * @return array{structured_output: array<string, mixed>, tokens_used: int, latency_ms: int, execution_mode: string, retry_count: int}
     */
    protected function executeWithBoundedRetries(
        AiProviderConfig $config,
        callable $requestFn,
        int $maxRetries
    ): array {
        $attempt = 0;
        $maxAttempts = max(1, $maxRetries + 1);
        $startMicro = microtime(true);

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $response = $requestFn();
                $httpStatus = (int) $response['http_status'];

                if ($httpStatus >= 200 && $httpStatus < 300) {
                    $rawText = (string) ($response['raw_text'] ?? '');
                    $structured = $this->decodeStructuredJson($rawText);
                    $latencyMs = max(1, (int) round((microtime(true) - $startMicro) * 1000));

                    return [
                        'structured_output' => $structured,
                        'tokens_used' => max(1, (int) ($response['tokens_used'] ?? 250)),
                        'latency_ms' => $latencyMs,
                        'execution_mode' => 'live_provider_http',
                        'retry_count' => $attempt - 1,
                    ];
                }

                $failure = $this->normalizeHttpFailure($httpStatus);
                if (! $failure['retryable'] || $attempt >= $maxAttempts) {
                    $code = $httpStatus === 429 ? 429 : ($httpStatus === 401 || $httpStatus === 403 ? 401 : 502);
                    throw new RuntimeException("{$failure['error_code']}: {$failure['message']}", $code);
                }
            } catch (ConnectionException $e) {
                if ($attempt >= $maxAttempts) {
                    throw new RuntimeException('PROVIDER_TIMEOUT: Connection to AI provider timed out or was unreachable.', 504);
                }
            } catch (RuntimeException $e) {
                throw $e;
            } catch (Throwable) {
                if ($attempt >= $maxAttempts) {
                    throw new RuntimeException('PROVIDER_UNAVAILABLE: Unexpected transport failure communicating with AI provider.', 502);
                }
            }
        }

        throw new RuntimeException('PROVIDER_UNAVAILABLE: Maximum retry attempts exhausted.', 502);
    }
}
