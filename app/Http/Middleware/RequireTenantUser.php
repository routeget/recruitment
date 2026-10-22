<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\TenantContextService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireTenantUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        $context = app(TenantContextService::class);

        if (! ($user instanceof User) || ! $user->isActiveAccount()) {
            abort(403, 'TENANT_AUTHENTICATION_REQUIRED');
        }

        if ($user->isActivePlatformAdmin()) {
            abort(403, 'TENANT_NAMESPACE_FORBIDDEN: Platform administrators must use the platform administration namespace.');
        }

        $tenantId = $context->getActiveTenantId();
        if (! $tenantId || ! $user->isMemberOfTenant((int) $tenantId)) {
            abort(403, 'TENANT_CONTEXT_REQUIRED: An active tenant membership is required.');
        }

        return $next($request);
    }
}
