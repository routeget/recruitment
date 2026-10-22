<?php

use App\Exceptions\HrHandoverGateNotSatisfiedException;
use App\Http\Middleware\EnforceTenantAndSecurityContext;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequirePlatformSuperAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->validateCsrfTokens(except: [
            'mcp/*',
        ]);
        $middleware->web(append: [
            EnforceTenantAndSecurityContext::class,
        ]);
        $middleware->api(append: [
            EnforceTenantAndSecurityContext::class,
        ]);
        $middleware->alias([
            'tenant.context' => EnforceTenantAndSecurityContext::class,
            'permission' => RequirePermission::class,
            'platform.superadmin' => RequirePlatformSuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('mcp/*') || $request->expectsJson(),
        );
        $exceptions->render(function (HrHandoverGateNotSatisfiedException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*') || $request->is('mcp/*')) {
                return response()->json([
                    'error' => [
                        'code' => $e->errorCode,
                        'message' => $e->getMessage(),
                    ],
                ], 409);
            }

            return response($e->errorCode.': '.$e->getMessage(), 409);
        });
    })->create();
