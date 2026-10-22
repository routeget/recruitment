<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\PlatformConfigService;
use App\Services\TenantContextService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class EnforceTenantAndSecurityContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContextService::class);
        $platformConfig = app(PlatformConfigService::class);

        $incomingCorrId = $request->header('X-Correlation-Id');
        if (is_string($incomingCorrId) && preg_match('/^[A-Za-z0-9\-_]{6,64}$/', $incomingCorrId)) {
            $context->setCorrelationId($incomingCorrId);
        } else {
            $context->setCorrelationId('corr-'.bin2hex(random_bytes(8)));
        }

        if (Auth::check()) {
            $user = Auth::user();
            if ($user instanceof User && ! $user->isActiveAccount()) {
                Auth::logout();
                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }
                abort(403, 'ACCOUNT_SUSPENDED: Your account has been suspended.');
            }

            $tenant = $context->resolveForAuthenticatedUser($user);
            View::share('activeTenant', $tenant);
            View::share('activeUserRoles', $user->roleSlugsForTenant($tenant?->id));
            View::share('activeUserPermissions', $user->permissionSlugsForTenant($tenant?->id));
        } else {
            View::share('activeTenant', null);
            View::share('activeUserRoles', []);
            View::share('activeUserPermissions', []);
        }

        View::share('platformBranding', [
            'display_name' => $platformConfig->getString('platform.display_name'),
            'brand_code' => $platformConfig->getString('platform.brand_code'),
            'support_email' => $platformConfig->getString('platform.support_email'),
            'career_portal_banner' => $platformConfig->getString('platform.career_portal_banner'),
            'theme_tokens' => $platformConfig->getThemeTokens(),
        ]);
        View::share('correlationId', $context->getCorrelationId());

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Correlation-Id', $context->getCorrelationId());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
