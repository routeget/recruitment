<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TenantContextService
{
    private ?Tenant $activeTenant = null;

    private string $correlationId = '';

    private array $activeApiScopes = [];

    public function setCorrelationId(string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    public function getCorrelationId(): string
    {
        if ($this->correlationId === '') {
            $this->correlationId = 'corr-'.bin2hex(random_bytes(8));
        }

        return $this->correlationId;
    }

    public function setActiveTenant(?Tenant $tenant): void
    {
        $this->activeTenant = $tenant;
    }

    public function setActiveTenantById(?int $tenantId): void
    {
        $this->activeTenant = $tenantId ? Tenant::find($tenantId) : null;
    }

    public function hasActiveTenant(): bool
    {
        return $this->activeTenant !== null;
    }

    public function getActiveTenant(): ?Tenant
    {
        return $this->activeTenant;
    }

    public function getActiveTenantId(): ?int
    {
        return $this->activeTenant?->id;
    }

    public function setActiveApiScopes(array $scopes): void
    {
        $this->activeApiScopes = $scopes;
    }

    public function hasApiScope(string $scope): bool
    {
        return in_array('*', $this->activeApiScopes, true) || in_array($scope, $this->activeApiScopes, true);
    }

    /**
     * Resolve trusted tenant context from the authenticated user's verified membership.
     * Never trusts unverified request parameters for tenant context.
     */
    public function resolveForAuthenticatedUser(?User $user): ?Tenant
    {
        if (! $user) {
            $this->activeTenant = null;

            return null;
        }

        if ($user->current_tenant_id && $user->isMemberOfTenant($user->current_tenant_id)) {
            $tenant = Tenant::where('id', $user->current_tenant_id)->where('status', 'ACTIVE')->first();
            if ($tenant) {
                $this->activeTenant = $tenant;

                return $tenant;
            }
        }

        // Fall back to first active membership if current_tenant_id is unset or no longer active
        $firstMembership = $user->memberships()->where('status', 'ACTIVE')->first();
        if ($firstMembership) {
            $tenant = Tenant::where('id', $firstMembership->tenant_id)->where('status', 'ACTIVE')->first();
            if ($tenant) {
                $user->forceFill(['current_tenant_id' => $tenant->id])->saveQuietly();
                $this->activeTenant = $tenant;

                return $tenant;
            }
        }

        $this->activeTenant = null;

        return null;
    }

    /**
     * Strictly assert that a model belongs to the active tenant context.
     */
    public function assertModelBelongsToActiveTenant(Model $model, string $entityName = 'Record'): void
    {
        $activeTenantId = $this->getActiveTenantId();
        $modelTenantId = $model->getAttribute('tenant_id');

        if (! $activeTenantId || (int) $modelTenantId !== (int) $activeTenantId) {
            app(AuditService::class)->record(
                module: 'security',
                action: 'tenant_scope_violation',
                outcome: 'DENIED',
                errorCode: 'TENANT_SCOPE_VIOLATION',
                entityType: $entityName,
                entityId: (string) $model->getKey(),
                metadata: [
                    'active_tenant_id' => $activeTenantId,
                    'target_tenant_id' => $modelTenantId,
                ]
            );

            throw new AccessDeniedHttpException('TENANT_SCOPE_VIOLATION: Cross-tenant access is strictly prohibited.');
        }
    }
}
