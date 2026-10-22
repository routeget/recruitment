<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ApiClient;

/**
 * Dedicated tenant-bound machine principal for /api/v1 and /mcp/v1 requests.
 * Never impersonates or inherits privileges from any human User record.
 */
final readonly class ApiClientPrincipal
{
    public function __construct(
        public ApiClient $client,
    ) {}

    public function getTenantId(): int
    {
        return (int) $this->client->tenant_id;
    }

    public function getClientId(): string
    {
        return (string) $this->client->client_id;
    }

    public function isActive(): bool
    {
        return (bool) $this->client->is_active;
    }

    public function hasScope(string $scope): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return in_array($scope, $this->client->scopes ?? [], true);
    }

    public function hasPermission(string $permissionSlug, ?int $tenantId = null): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if ($tenantId !== null && (int) $tenantId !== (int) $this->client->tenant_id) {
            return false;
        }

        return in_array($permissionSlug, $this->client->scopes ?? [], true);
    }

    /**
     * Machine principals never hold human RBAC roles such as tenant_admin or platform_superadmin.
     *
     * @param  string|array<int, string>  $roles
     */
    public function hasRole(string|array $roles, ?int $tenantId = null): bool
    {
        return false;
    }

    public function getActorUserId(): ?int
    {
        return null;
    }

    public function getActorType(): string
    {
        return 'API_CLIENT';
    }

    public function getActorIdentifier(): string
    {
        return $this->getClientId();
    }
}
