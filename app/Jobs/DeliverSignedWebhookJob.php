<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\AuditService;
use App\Services\PlatformConfigService;
use App\Support\WebhookSsrfGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class DeliverSignedWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 10;

    public function __construct(
        public readonly int $webhookDeliveryId,
    ) {}

    public function handle(WebhookSsrfGuard $ssrfGuard, AuditService $audit): void
    {
        /** @var WebhookDelivery|null $delivery */
        $delivery = WebhookDelivery::withoutGlobalScopes()->find($this->webhookDeliveryId);
        if ($delivery === null || $delivery->status === 'DELIVERED') {
            return;
        }

        if (! app(PlatformConfigService::class)->getBool('webhooks.global_delivery_enabled')) {
            $delivery->update([
                'status' => 'PAUSED_BY_PLATFORM_POLICY',
                'error_code' => 'WEBHOOK_GLOBAL_DELIVERY_DISABLED',
                'error_message' => 'Outbound webhook dispatch is globally paused by platform governance policy.',
            ]);

            $audit->record(
                module: 'api_platform',
                action: 'webhook.delivery_paused_by_platform',
                outcome: 'DENIED',
                errorCode: 'WEBHOOK_GLOBAL_DELIVERY_DISABLED',
                entityType: 'webhook_delivery',
                entityId: (string) $delivery->id,
                metadata: ['event_id' => $delivery->event_id],
                tenantId: (int) $delivery->tenant_id,
                actorType: 'SYSTEM_QUEUE',
                actorIdentifier: 'DeliverSignedWebhookJob',
            );

            return;
        }

        /** @var WebhookSubscription|null $subscription */
        $subscription = WebhookSubscription::withoutGlobalScopes()->find($delivery->webhook_subscription_id);
        if ($subscription === null || ! $subscription->is_active) {
            $delivery->update([
                'status' => 'FAILED_PERMANENT',
                'error_code' => 'WEBHOOK_SUBSCRIPTION_INACTIVE',
                'error_message' => 'Webhook subscription is missing or disabled.',
                'failed_at' => now(),
            ]);

            return;
        }

        $attemptNumber = ((int) $delivery->attempt_count) + 1;

        try {
            $ssrfGuard->assertSafeTargetUrl($subscription->target_url);
        } catch (InvalidArgumentException $e) {
            $delivery->update([
                'attempt_count' => $attemptNumber,
                'status' => 'FAILED_PERMANENT',
                'error_code' => 'WEBHOOK_SSRF_BLOCKED',
                'error_message' => substr($e->getMessage(), 0, 450),
                'failed_at' => now(),
            ]);

            $audit->record(
                module: 'api_platform',
                action: 'webhook.delivery_blocked_ssrf',
                outcome: 'DENIED',
                errorCode: 'WEBHOOK_SSRF_BLOCKED',
                entityType: 'webhook_delivery',
                entityId: (string) $delivery->id,
                metadata: [
                    'subscription_id' => $subscription->id,
                    'target_url' => $subscription->target_url,
                ],
                tenantId: (int) $delivery->tenant_id,
                actorType: 'SYSTEM_QUEUE',
                actorIdentifier: 'DeliverSignedWebhookJob',
            );

            return;
        }

        $rawJson = (string) json_encode($delivery->payload);

        try {
            $response = Http::timeout(5)
                ->connectTimeout(3)
                ->withOptions([
                    'verify' => true,
                    'allow_redirects' => false,
                ])
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'URAP-Webhook-Dispatcher/1.0',
                    'X-URAP-Event' => $delivery->event_type,
                    'X-URAP-Delivery-Id' => $delivery->event_id,
                    'X-URAP-Signature-256' => $delivery->signature_header,
                ])
                ->withBody($rawJson, 'application/json')
                ->post($subscription->target_url);

            $status = $response->status();

            if ($status >= 300 && $status < 400) {
                $delivery->update([
                    'attempt_count' => $attemptNumber,
                    'response_status' => $status,
                    'status' => 'FAILED_PERMANENT',
                    'error_code' => 'WEBHOOK_REDIRECT_PROHIBITED',
                    'error_message' => "Remote endpoint returned redirect status {$status}; redirects are prohibited for SSRF protection.",
                    'failed_at' => now(),
                ]);

                $audit->record(
                    module: 'api_platform',
                    action: 'webhook.delivery_failed',
                    outcome: 'DENIED',
                    errorCode: 'WEBHOOK_REDIRECT_PROHIBITED',
                    entityType: 'webhook_delivery',
                    entityId: (string) $delivery->id,
                    metadata: ['http_status' => $status],
                    tenantId: (int) $delivery->tenant_id,
                    actorType: 'SYSTEM_QUEUE',
                    actorIdentifier: 'DeliverSignedWebhookJob',
                );

                return;
            }

            if ($response->successful()) {
                $delivery->update([
                    'attempt_count' => $attemptNumber,
                    'response_status' => $status,
                    'status' => 'DELIVERED',
                    'error_code' => null,
                    'error_message' => null,
                    'response_body_excerpt' => substr((string) $response->body(), 0, 250),
                    'delivered_at' => now(),
                    'failed_at' => null,
                ]);

                $audit->record(
                    module: 'api_platform',
                    action: 'webhook.delivered',
                    outcome: 'SUCCESS',
                    entityType: 'webhook_delivery',
                    entityId: (string) $delivery->id,
                    metadata: [
                        'event_id' => $delivery->event_id,
                        'http_status' => $status,
                        'attempt_count' => $attemptNumber,
                    ],
                    tenantId: (int) $delivery->tenant_id,
                    actorType: 'SYSTEM_QUEUE',
                    actorIdentifier: 'DeliverSignedWebhookJob',
                );

                return;
            }

            $isRetryable = $status >= 500 || $status === 429;
            $delivery->update([
                'attempt_count' => $attemptNumber,
                'response_status' => $status,
                'status' => $isRetryable ? 'FAILED_RETRYABLE' : 'FAILED_PERMANENT',
                'error_code' => 'WEBHOOK_HTTP_ERROR',
                'error_message' => "Remote endpoint returned HTTP {$status}.",
                'response_body_excerpt' => substr((string) $response->body(), 0, 250),
                'failed_at' => now(),
            ]);

            $audit->record(
                module: 'api_platform',
                action: 'webhook.delivery_failed',
                outcome: 'FAILURE',
                errorCode: 'WEBHOOK_HTTP_ERROR',
                entityType: 'webhook_delivery',
                entityId: (string) $delivery->id,
                metadata: [
                    'event_id' => $delivery->event_id,
                    'http_status' => $status,
                    'retryable' => $isRetryable,
                ],
                tenantId: (int) $delivery->tenant_id,
                actorType: 'SYSTEM_QUEUE',
                actorIdentifier: 'DeliverSignedWebhookJob',
            );
        } catch (Throwable $e) {
            $delivery->update([
                'attempt_count' => $attemptNumber,
                'response_status' => null,
                'status' => 'FAILED_RETRYABLE',
                'error_code' => 'WEBHOOK_NETWORK_TIMEOUT',
                'error_message' => substr($e->getMessage(), 0, 450),
                'failed_at' => now(),
            ]);

            $audit->record(
                module: 'api_platform',
                action: 'webhook.delivery_failed',
                outcome: 'FAILURE',
                errorCode: 'WEBHOOK_NETWORK_TIMEOUT',
                entityType: 'webhook_delivery',
                entityId: (string) $delivery->id,
                metadata: [
                    'event_id' => $delivery->event_id,
                    'exception' => class_basename($e),
                ],
                tenantId: (int) $delivery->tenant_id,
                actorType: 'SYSTEM_QUEUE',
                actorIdentifier: 'DeliverSignedWebhookJob',
            );
        }
    }
}
