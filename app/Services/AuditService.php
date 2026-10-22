<?php

namespace App\Services;

use App\Models\AuditEvent;
use Illuminate\Support\Facades\Auth;

class AuditService
{
    private const REDACTED_KEYS = [
        'password',
        'password_confirmation',
        'secret',
        'client_secret',
        'signing_secret',
        'token',
        'api_key',
        'access_token',
        'ssn',
        'national_id',
        'tax_id',
        'raw_resume_text',
    ];

    public function record(
        string $module,
        string $action,
        string $outcome = 'SUCCESS',
        ?string $errorCode = null,
        ?string $entityType = null,
        ?string $entityId = null,
        array $metadata = [],
        ?int $tenantId = null,
        ?int $actorUserId = null,
        string $actorType = 'USER',
        ?string $actorIdentifier = null
    ): AuditEvent {
        $context = app(TenantContextService::class);
        $user = Auth::user();

        $resolvedTenantId = $tenantId ?? $context->getActiveTenantId() ?? $user?->current_tenant_id;
        $resolvedActorUserId = $actorUserId ?? $user?->id;
        $resolvedActorIdentifier = $actorIdentifier ?? $user?->email ?? 'system';

        $request = request();

        return AuditEvent::create([
            'correlation_id' => $context->getCorrelationId(),
            'tenant_id' => $resolvedTenantId,
            'actor_user_id' => $resolvedActorUserId,
            'actor_type' => $actorType,
            'actor_identifier' => $resolvedActorIdentifier,
            'module' => $module,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'outcome' => $outcome,
            'error_code' => $errorCode,
            'ip_address' => $request?->ip(),
            'user_agent' => substr((string) $request?->userAgent(), 0, 250),
            'metadata' => $this->scrubMetadata($metadata),
            'created_at' => now(),
        ]);
    }

    private function scrubMetadata(array $data): array
    {
        $scrubbed = [];
        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            $shouldRedact = false;
            foreach (self::REDACTED_KEYS as $sensitive) {
                if (str_contains($normalizedKey, $sensitive)) {
                    $shouldRedact = true;
                    break;
                }
            }

            if ($shouldRedact) {
                $scrubbed[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $scrubbed[$key] = $this->scrubMetadata($value);
            } else {
                $scrubbed[$key] = $value;
            }
        }

        return $scrubbed;
    }
}
