<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditService;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        $demoPersonas = [];
        if (app()->environment(['local', 'testing'])) {
            $demoPersonas = User::with(['currentTenant', 'memberships.role'])
                ->whereIn('email', [
                    'admin@acme-demo.test',
                    'recruiter@acme-demo.test',
                    'manager@acme-demo.test',
                    'hr.validator@acme-demo.test',
                    'hr.approver@acme-demo.test',
                    'auditor@acme-demo.test',
                    'admin@globex-demo.test',
                    'platform.superadmin@tringers.test',
                ])
                ->get();
        }

        return view('auth.login', [
            'demoPersonas' => $demoPersonas,
        ]);
    }

    public function login(Request $request, AuditService $audit, TenantContextService $context): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = 'login:'.strtolower($credentials['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 8)) {
            $audit->record(
                module: 'auth',
                action: 'login_rate_limited',
                outcome: 'DENIED',
                errorCode: 'RATE_LIMITED',
                actorIdentifier: $credentials['email']
            );

            return back()->withErrors([
                'email' => 'RATE_LIMITED: Too many login attempts. Please wait before retrying.',
            ])->onlyInput('email');
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 60);

            $audit->record(
                module: 'auth',
                action: 'login_failed',
                outcome: 'DENIED',
                errorCode: 'AUTHENTICATION_REQUIRED',
                actorIdentifier: $credentials['email']
            );

            return back()->withErrors([
                'email' => 'Invalid credentials provided.',
            ])->onlyInput('email');
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $tenant = $context->resolveForAuthenticatedUser($user);

        $audit->record(
            module: 'auth',
            action: 'login_succeeded',
            outcome: 'SUCCESS',
            entityType: 'User',
            entityId: (string) $user->id,
            metadata: [
                'resolved_tenant_id' => $tenant?->id,
                'resolved_tenant_slug' => $tenant?->slug,
                'roles' => $user->roleSlugsForTenant($tenant?->id),
            ],
            tenantId: $tenant?->id,
            actorUserId: $user->id
        );

        return redirect()->intended(route('dashboard'))
            ->with('status', 'Authenticated into '.$tenant?->name.'.');
    }

    public function logout(Request $request, AuditService $audit): RedirectResponse
    {
        if (Auth::check()) {
            $audit->record(
                module: 'auth',
                action: 'logout',
                outcome: 'SUCCESS',
                entityType: 'User',
                entityId: (string) Auth::id()
            );
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You have been signed out.');
    }

    public function switchTenant(Request $request, AuditService $audit, TenantContextService $context): RedirectResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
        ]);

        /** @var User $user */
        $user = Auth::user();
        $targetTenantId = (int) $validated['tenant_id'];

        if (! $user->isMemberOfTenant($targetTenantId)) {
            $audit->record(
                module: 'security',
                action: 'unauthorized_tenant_switch',
                outcome: 'DENIED',
                errorCode: 'TENANT_SCOPE_VIOLATION',
                entityType: 'Tenant',
                entityId: (string) $targetTenantId,
                metadata: ['requested_tenant_id' => $targetTenantId]
            );

            abort(403, 'TENANT_SCOPE_VIOLATION: You do not hold an active membership in the requested tenant.');
        }

        $user->forceFill(['current_tenant_id' => $targetTenantId])->save();
        $tenant = $context->resolveForAuthenticatedUser($user);

        $audit->record(
            module: 'auth',
            action: 'tenant_switched',
            outcome: 'SUCCESS',
            entityType: 'Tenant',
            entityId: (string) $targetTenantId,
            metadata: ['tenant_slug' => $tenant?->slug],
            tenantId: $targetTenantId
        );

        return redirect()->route('dashboard')
            ->with('status', 'Switched active tenant context to '.$tenant?->name.'.');
    }

    public function setupStatus(): JsonResponse
    {
        $initialized = Tenant::count() > 0 && Role::count() > 0;

        return response()->json([
            'status' => $initialized ? 'INITIALIZED_LOCKED' : 'PENDING_BOOTSTRAP',
            'public_setup_enabled' => false,
            'message' => 'Initial bootstrap is governed via CLI seeder only; public setup endpoints are disabled.',
        ], 403);
    }
}
