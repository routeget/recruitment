<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\ExternalIdentity;
use App\Models\ExternalIdentityConnection;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SsoInvitation;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EntraSsoService;
use App\Services\TenantContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AuditGovernanceController extends Controller
{
    public function auditIndex(Request $request, TenantContextService $context): View
    {
        $tenantId = $context->getActiveTenantId();

        $query = AuditEvent::where('tenant_id', $tenantId)->orderByDesc('id');

        if ($request->filled('module')) {
            $query->where('module', $request->string('module')->toString());
        }
        if ($request->filled('outcome')) {
            $query->where('outcome', $request->string('outcome')->toString());
        }
        if ($request->filled('q')) {
            $q = $request->string('q')->toString();
            $query->where(function ($sub) use ($q) {
                $sub->where('action', 'like', "%{$q}%")
                    ->orWhere('actor_identifier', 'like', "%{$q}%")
                    ->orWhere('correlation_id', 'like', "%{$q}%");
            });
        }

        $events = $query->paginate(20)->withQueryString();

        return view('admin.audit_events', [
            'events' => $events,
        ]);
    }

    public function iamIndex(TenantContextService $context, EntraSsoService $entraSso): View
    {
        $tenant = $context->getActiveTenant();
        $memberships = TenantMembership::where('tenant_id', $tenant->id)
            ->with(['user', 'role.permissions'])
            ->orderBy('id')
            ->get();

        $roles = Role::whereNull('tenant_id')
            ->orWhere('tenant_id', $tenant->id)
            ->with('permissions')
            ->orderBy('id')
            ->get();

        $permissions = Permission::orderBy('module')->orderBy('slug')->get();
        $allTenants = Auth::user()->is_platform_superadmin ? Tenant::orderBy('id')->get() : collect([$tenant]);

        $ssoConnections = ExternalIdentityConnection::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('id')
            ->get();

        $externalIdentities = ExternalIdentity::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->with(['user', 'connection'])
            ->orderByDesc('id')
            ->get();

        $ssoInvitations = SsoInvitation::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->with(['role', 'connection'])
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        return view('admin.tenants_iam', [
            'tenant' => $tenant,
            'memberships' => $memberships,
            'roles' => $roles,
            'permissions' => $permissions,
            'allTenants' => $allTenants,
            'ssoConnections' => $ssoConnections,
            'externalIdentities' => $externalIdentities,
            'ssoInvitations' => $ssoInvitations,
            'entraSsoEnabled' => $entraSso->isEnabled(),
        ]);
    }

    public function storeTenantMember(Request $request, TenantContextService $context, AuditService $audit): RedirectResponse
    {
        $tenant = $context->getActiveTenant();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190'],
            'role_slug' => ['required', 'string', 'exists:roles,slug'],
            'password' => ['nullable', 'string', 'min:10'],
        ]);

        if ($validated['role_slug'] === 'platform_superadmin' && ! Auth::user()->is_platform_superadmin) {
            abort(403, 'PERMISSION_DENIED: Only a Platform Superadmin may grant platform_superadmin.');
        }

        $role = Role::where('slug', $validated['role_slug'])->firstOrFail();

        $user = User::firstOrCreate(
            ['email' => strtolower($validated['email'])],
            [
                'name' => $validated['name'],
                'password' => Hash::make($validated['password'] ?? 'TenantUser#2026!'),
                'current_tenant_id' => $tenant->id,
                'mfa_enabled' => true,
            ]
        );

        $membership = TenantMembership::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role_id' => $role->id,
            ],
            [
                'status' => 'ACTIVE',
                'abac_scope' => ['scope_mode' => 'tenant_scoped'],
            ]
        );

        $audit->record(
            module: 'iam',
            action: 'tenant_member_provisioned',
            outcome: 'SUCCESS',
            entityType: 'TenantMembership',
            entityId: (string) $membership->id,
            metadata: [
                'member_email' => $user->email,
                'assigned_role' => $role->slug,
            ]
        );

        return redirect()->route('admin.iam.index')
            ->with('status', "Assigned role '{$role->name}' to {$user->email} in {$tenant->name}.");
    }

    public function grantPlatformSupportContext(Request $request, AuditService $audit, TenantContextService $context): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();
        if (! $user->is_platform_superadmin) {
            abort(403, 'PERMISSION_DENIED: Platform Superadmin privilege required.');
        }

        $validated = $request->validate([
            'target_tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'support_ticket_reference' => ['required', 'string', 'min:5', 'max:80'],
            'justification' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $targetTenant = Tenant::findOrFail((int) $validated['target_tenant_id']);
        $superRole = Role::where('slug', 'platform_superadmin')->firstOrFail();

        TenantMembership::updateOrCreate(
            [
                'tenant_id' => $targetTenant->id,
                'user_id' => $user->id,
                'role_id' => $superRole->id,
            ],
            [
                'status' => 'ACTIVE',
                'abac_scope' => [
                    'scope_mode' => 'audited_platform_support',
                    'ticket_ref' => $validated['support_ticket_reference'],
                    'candidate_pii_browsing_prohibited' => true,
                ],
            ]
        );

        $user->forceFill(['current_tenant_id' => $targetTenant->id])->save();
        $context->resolveForAuthenticatedUser($user);

        $audit->record(
            module: 'security',
            action: 'platform_support_access_activated',
            outcome: 'SUCCESS',
            entityType: 'Tenant',
            entityId: (string) $targetTenant->id,
            metadata: [
                'support_ticket_reference' => $validated['support_ticket_reference'],
                'justification' => $validated['justification'],
                'candidate_pii_browsing_prohibited' => true,
            ],
            tenantId: $targetTenant->id
        );

        return redirect()->route('admin.iam.index')
            ->with('status', "Audited platform support context activated for {$targetTenant->name} (Ticket: {$validated['support_ticket_reference']}).");
    }
}
