<?php

namespace App\Http\Middleware;

use App\Services\AuditService;
use App\Services\TenantContextService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = Auth::user();
        $context = app(TenantContextService::class);
        $tenantId = $context->getActiveTenantId();
        $permissionLabel = implode(' | ', $permissions);

        if (! $user || ! $tenantId || ! $user->hasAnyPermission($permissions, $tenantId)) {
            app(AuditService::class)->record(
                module: 'security',
                action: 'permission_denied',
                outcome: 'DENIED',
                errorCode: 'PERMISSION_DENIED',
                metadata: [
                    'required_permission' => $permissionLabel,
                    'path' => $request->path(),
                    'method' => $request->method(),
                ]
            );

            if ($request->expectsJson() || $request->is('api/*') || $request->is('mcp/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'PERMISSION_DENIED',
                        'message' => "Missing required permission: {$permissionLabel}",
                        'correlation_id' => $context->getCorrelationId(),
                        'retryable' => false,
                    ],
                ], 403);
            }

            abort(403, "PERMISSION_DENIED: You do not have the required permission ({$permissionLabel}) in the active tenant.");
        }

        return $next($request);
    }
}
