<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuditService;
use App\Services\PlatformConfigService;
use App\Services\TenantContextService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequirePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        $context = app(TenantContextService::class);
        $audit = app(AuditService::class);

        if (! ($user instanceof User) || ! $user->isActivePlatformAdmin()) {
            $audit->record(
                module: 'platform_admin',
                action: 'platform_admin.access_denied',
                outcome: 'DENIED',
                errorCode: 'PLATFORM_SUPERADMIN_REQUIRED',
                metadata: [
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'active_tenant_id' => $context->getActiveTenantId(),
                ]
            );

            if ($request->expectsJson() || $request->is('api/*') || $request->is('mcp/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'PLATFORM_SUPERADMIN_REQUIRED',
                        'message' => 'Active Platform Admin or Platform Super Admin authority is required for this endpoint.',
                        'correlation_id' => $context->getCorrelationId(),
                        'retryable' => false,
                    ],
                ], 403);
            }

            abort(403, 'PLATFORM_ADMIN_REQUIRED: Active Platform Admin or Platform Super Admin authority is required.');
        }

        $configService = app(PlatformConfigService::class);
        if ($configService->getBool('security.require_mfa_for_platform_admins') && ! $user->mfa_enabled) {
            $audit->record(
                module: 'platform_admin',
                action: 'platform_admin.mfa_required_denied',
                outcome: 'DENIED',
                errorCode: 'PLATFORM_MFA_REQUIRED',
                entityType: 'User',
                entityId: (string) $user->id,
                metadata: ['path' => $request->path()],
                actorUserId: (int) $user->id,
            );

            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'PLATFORM_MFA_REQUIRED',
                        'message' => 'MFA enrollment is required for Platform Super Admin access.',
                        'correlation_id' => $context->getCorrelationId(),
                        'retryable' => false,
                    ],
                ], 403);
            }

            abort(403, 'PLATFORM_MFA_REQUIRED: Multi-Factor Authentication is required for Platform Super Admin access.');
        }

        if ((bool) ($user->must_rotate_password ?? false)
            && ! $request->isMethodSafe()
            && ! $request->routeIs('platform-admin.security.rotate-password')
        ) {
            $audit->record(
                module: 'platform_admin',
                action: 'platform_admin.mutation_blocked_password_rotation_required',
                outcome: 'DENIED',
                errorCode: 'PASSWORD_ROTATION_REQUIRED',
                entityType: 'User',
                entityId: (string) $user->id,
                metadata: ['path' => $request->path()],
                actorUserId: (int) $user->id,
            );

            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'PASSWORD_ROTATION_REQUIRED',
                        'message' => 'You must rotate your password before performing platform mutations.',
                        'correlation_id' => $context->getCorrelationId(),
                        'retryable' => false,
                    ],
                ], 403);
            }

            abort(403, 'PASSWORD_ROTATION_REQUIRED: You must rotate your password before performing platform mutations.');
        }

        return $next($request);
    }
}
