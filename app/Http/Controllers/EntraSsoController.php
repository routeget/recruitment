<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\EntraSsoException;
use App\Models\ExternalIdentity;
use App\Models\ExternalIdentityConnection;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EntraSsoService;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EntraSsoController extends Controller
{
    public function __construct(
        private readonly EntraSsoService $entraSso,
        private readonly TenantContextService $tenantContext,
        private readonly AuditService $audit,
    ) {}

    public function redirect(Request $request): RedirectResponse|JsonResponse
    {
        try {
            $flow = $this->entraSso->initiateAuthorizeRedirect(
                $request,
                $request->string('invitation_token')->toString() ?: null,
                $request->string('login_hint')->toString() ?: null,
            );

            if ($request->expectsJson()) {
                return response()->json([
                    'data' => [
                        'authorize_url' => $flow['authorize_url'],
                        'state' => $flow['state'],
                        'code_challenge' => $flow['code_challenge'],
                    ],
                ]);
            }

            return redirect()->away($flow['authorize_url']);
        } catch (EntraSsoException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => $e->errorCode,
                        'message' => $e->getMessage(),
                    ],
                ], $e->httpStatus);
            }

            return redirect()
                ->route('login')
                ->withErrors(['email' => $e->getMessage()])
                ->with('sso_error_code', $e->errorCode);
        }
    }

    public function callback(Request $request): RedirectResponse|JsonResponse
    {
        try {
            $user = $this->entraSso->handleCallback($request);

            if ($request->expectsJson()) {
                return response()->json([
                    'data' => [
                        'authenticated' => true,
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'tenant_id' => $user->current_tenant_id,
                    ],
                ]);
            }

            return redirect()
                ->intended(route('dashboard'))
                ->with('status', 'Signed in with Microsoft Entra ID Single Sign-On.');
        } catch (EntraSsoException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => $e->errorCode,
                        'message' => $e->getMessage(),
                    ],
                ], $e->httpStatus);
            }

            return redirect()
                ->route('login')
                ->withErrors(['email' => $e->getMessage()])
                ->with('sso_error_code', $e->errorCode);
        }
    }

    public function storeConnection(Request $request): RedirectResponse
    {
        $tenant = $this->tenantContext->getActiveTenant();
        if ($tenant === null) {
            abort(403, 'Active tenant context required.');
        }

        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:150'],
            'entra_tenant_id' => ['required', 'uuid'],
            'allowed_domains' => ['nullable', 'string', 'max:500'],
            'jit_provisioning_enabled' => ['nullable', 'boolean'],
            'default_jit_role_slug' => ['nullable', 'string', 'in:recruiter,hiring_manager,interviewer,hr_Coordinator,hr_validator,compliance_auditor'],
            'require_sso_for_tenant' => ['nullable', 'boolean'],
            'allow_emergency_local_login' => ['nullable', 'boolean'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        $domains = array_values(array_filter(array_map(
            static fn (string $d): string => strtolower(trim($d)),
            explode(',', (string) ($validated['allowed_domains'] ?? '')),
        )));

        $requireSso = (bool) ($validated['require_sso_for_tenant'] ?? false);
        $allowEmergencyLocal = (bool) ($validated['allow_emergency_local_login'] ?? true);
        $isEnabled = (bool) ($validated['is_enabled'] ?? true);

        $connection = ExternalIdentityConnection::withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'provider' => EntraSsoService::PROVIDER_KEY,
                'entra_tenant_id' => strtolower(trim($validated['entra_tenant_id'])),
            ],
            [
                'display_name' => trim($validated['display_name']),
                'allowed_domains' => $domains,
                'jit_provisioning_enabled' => (bool) ($validated['jit_provisioning_enabled'] ?? false),
                'default_jit_role_slug' => $validated['default_jit_role_slug'] ?? 'hiring_manager',
                'require_sso_for_tenant' => $requireSso,
                'allow_emergency_local_login' => $allowEmergencyLocal,
                'is_enabled' => $isEnabled,
            ]
        );

        $tenant->update([
            'require_sso' => $requireSso,
            'allow_local_fallback' => $allowEmergencyLocal,
        ]);

        $this->audit->record(
            module: 'sso',
            action: 'sso.connection_configured',
            outcome: 'SUCCESS',
            entityType: 'ExternalIdentityConnection',
            entityId: (string) $connection->id,
            metadata: [
                'entra_tenant_id' => $connection->entra_tenant_id,
                'is_enabled' => $connection->is_enabled,
                'require_sso_for_tenant' => $connection->require_sso_for_tenant,
                'jit_provisioning_enabled' => $connection->jit_provisioning_enabled,
            ],
            tenantId: (int) $tenant->id,
            actorUserId: (int) $request->user()->id,
        );

        return redirect()
            ->route('admin.iam.index')
            ->with('status', "Microsoft Entra ID connection '{$connection->display_name}' ({$connection->entra_tenant_id}) saved.");
    }

    public function createInvitation(Request $request): RedirectResponse
    {
        $tenant = $this->tenantContext->getActiveTenant();
        if ($tenant === null) {
            abort(403, 'Active tenant context required.');
        }

        $validated = $request->validate([
            'connection_id' => ['required', 'integer'],
            'email' => ['required', 'email', 'max:190'],
            'role_slug' => ['required', 'string', 'exists:roles,slug'],
            'expected_entra_object_id' => ['nullable', 'uuid'],
        ]);

        if ($validated['role_slug'] === 'platform_superadmin' && ! $request->user()->is_platform_superadmin) {
            abort(403, 'Only Platform Superadmin may invite a platform_superadmin.');
        }

        /** @var ExternalIdentityConnection $connection */
        $connection = ExternalIdentityConnection::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->findOrFail((int) $validated['connection_id']);

        $role = Role::query()->where('slug', $validated['role_slug'])->firstOrFail();

        $created = $this->entraSso->createSsoInvitation(
            $connection,
            $validated['email'],
            $role,
            $request->user(),
            $validated['expected_entra_object_id'] ?? null,
        );

        return redirect()
            ->route('admin.iam.index')
            ->with('status', "SSO Invitation issued for {$created['invitation']->email}. One-time invitation token: {$created['raw_token']}");
    }

    public function linkIdentity(Request $request): RedirectResponse
    {
        $tenant = $this->tenantContext->getActiveTenant();
        if ($tenant === null) {
            abort(403, 'Active tenant context required.');
        }

        $validated = $request->validate([
            'connection_id' => ['required', 'integer'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'entra_object_id' => ['required', 'uuid'],
            'subject' => ['nullable', 'string', 'max:128'],
        ]);

        /** @var ExternalIdentityConnection $connection */
        $connection = ExternalIdentityConnection::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->findOrFail((int) $validated['connection_id']);

        /** @var User $targetUser */
        $targetUser = User::query()->findOrFail((int) $validated['user_id']);
        if (! $targetUser->isMemberOfTenant((int) $tenant->id)) {
            abort(403, 'Target user must be an active member of this tenant before linking an external identity.');
        }

        $subject = ! empty($validated['subject'])
            ? $validated['subject']
            : 'sub-'.$validated['entra_object_id'];

        $identity = $this->entraSso->linkUserExternalIdentity(
            $connection,
            $targetUser,
            $validated['entra_object_id'],
            $subject,
            $request->user(),
        );

        return redirect()
            ->route('admin.iam.index')
            ->with('status', "Linked Microsoft Entra Object ID {$identity->entra_object_id} to {$targetUser->email}.");
    }

    public function updateIdentityStatus(Request $request, string $identityId): RedirectResponse
    {
        $tenant = $this->tenantContext->getActiveTenant();
        if ($tenant === null) {
            abort(403, 'Active tenant context required.');
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:ACTIVE,SUSPENDED,UNLINKED'],
        ]);

        /** @var ExternalIdentity $identity */
        $identity = ExternalIdentity::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->findOrFail((int) $identityId);

        $previousStatus = $identity->status;
        $identity->update([
            'status' => $validated['status'],
        ]);

        $this->audit->record(
            module: 'sso',
            action: 'sso.identity_status_updated',
            outcome: 'SUCCESS',
            entityType: 'ExternalIdentity',
            entityId: (string) $identity->id,
            metadata: [
                'user_id' => $identity->user_id,
                'entra_object_id' => $identity->entra_object_id,
                'from_status' => $previousStatus,
                'to_status' => $validated['status'],
            ],
            tenantId: (int) $tenant->id,
            actorUserId: (int) $request->user()->id,
        );

        return redirect()
            ->route('admin.iam.index')
            ->with('status', "Updated Microsoft Entra identity status for OID {$identity->entra_object_id} to {$validated['status']}.");
    }
}
