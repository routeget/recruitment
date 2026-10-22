<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\EntraSsoException;
use App\Models\AuditEvent;
use App\Models\ExternalIdentity;
use App\Models\ExternalIdentityConnection;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EntraSsoService;
use Database\Seeders\AlphaSafeBootstrapSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

class MicrosoftEntraSsoTest extends TestCase
{
    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $adminA;

    private ExternalIdentityConnection $connectionA;

    private OpenSSLAsymmetricKey $rsaPrivateKey;

    /** @var array<string, mixed> */
    private array $jwkPublicKey;

    private string $keyId = 'test-entra-signing-kid-2026';

    private string $entraTenantIdA = '11111111-2222-3333-4444-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AlphaSafeBootstrapSeeder::class);

        $this->tenantA = Tenant::query()->where('slug', 'acme-demo')->firstOrFail();
        $this->tenantB = Tenant::query()->where('slug', 'globex-demo')->firstOrFail();
        $this->adminA = User::query()->where('email', 'admin@acme-demo.test')->firstOrFail();

        $this->connectionA = ExternalIdentityConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'provider' => EntraSsoService::PROVIDER_KEY,
            'display_name' => 'Acme Corp Microsoft Entra ID',
            'entra_tenant_id' => $this->entraTenantIdA,
            'allowed_domains' => ['acme-demo.test'],
            'jit_provisioning_enabled' => false,
            'default_jit_role_slug' => 'hiring_manager',
            'require_sso_for_tenant' => false,
            'allow_emergency_local_login' => true,
            'is_enabled' => true,
        ]);

        $keyResource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($keyResource);
        $this->rsaPrivateKey = $keyResource;

        $details = openssl_pkey_get_details($this->rsaPrivateKey);
        $this->assertIsArray($details);

        $this->jwkPublicKey = [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $this->keyId,
            'n' => $this->base64UrlEncode($details['rsa']['n']),
            'e' => $this->base64UrlEncode($details['rsa']['e']),
        ];
    }

    public function test_unconfigured_or_disabled_sso_fails_closed_and_shows_safe_diagnostic(): void
    {
        config([
            'entra_sso.enabled' => false,
            'entra_sso.client_id' => '',
            'entra_sso.client_secret' => '',
        ]);

        $this->get('/login')
            ->assertStatus(200)
            ->assertSee('Sign in with Microsoft work or school account')
            ->assertSee('Microsoft Entra ID SSO is managed by your organization');

        $this->getJson('/auth/entra/redirect')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'SSO_NOT_CONFIGURED');
    }

    public function test_authorize_redirect_and_prelinked_user_oidc_pkce_login_succeeds_and_ignores_entra_group_elevation(): void
    {
        $this->enableEntraConfig();

        $recruiter = User::query()->where('email', 'recruiter@acme-demo.test')->firstOrFail();
        $entraOid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0001';

        app(EntraSsoService::class)->linkUserExternalIdentity(
            $this->connectionA,
            $recruiter,
            $entraOid,
            'sub-recruiter-0001',
            $this->adminA,
        );

        $redirectResp = $this->getJson('/auth/entra/redirect');
        $redirectResp->assertStatus(200);

        $authorizeUrl = (string) $redirectResp->json('data.authorize_url');
        $state = (string) $redirectResp->json('data.state');
        $codeChallenge = (string) $redirectResp->json('data.code_challenge');

        $this->assertStringContainsString('https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize', $authorizeUrl);
        $this->assertStringContainsString('code_challenge_method=S256', $authorizeUrl);
        $this->assertNotEmpty($state);
        $this->assertNotEmpty($codeChallenge);

        $flow = session(EntraSsoService::SESSION_FLOW_KEY);
        $this->assertIsArray($flow);
        $nonce = (string) $flow['nonce'];

        // Include raw Entra 'roles' and 'groups' attempting privilege escalation to platform_superadmin/tenant_admin
        $idToken = $this->buildSignedIdToken([
            'iss' => "https://login.microsoftonline.com/{$this->entraTenantIdA}/v2.0",
            'aud' => 'test-entra-client-id',
            'tid' => $this->entraTenantIdA,
            'oid' => $entraOid,
            'sub' => 'sub-recruiter-0001',
            'email' => $recruiter->email,
            'preferred_username' => $recruiter->email,
            'name' => $recruiter->name,
            'nonce' => $nonce,
            'iat' => now()->timestamp - 10,
            'nbf' => now()->timestamp - 10,
            'exp' => now()->timestamp + 3600,
            'roles' => ['platform_superadmin', 'tenant_admin'],
            'groups' => ['00000000-0000-0000-0000-000000000099'],
        ]);

        $this->fakeEntraTokenAndJwksEndpoints($idToken);

        $callbackResp = $this->getJson('/auth/entra/callback?'.http_build_query([
            'code' => 'valid-auth-code-123',
            'state' => $state,
        ]));

        $callbackResp->assertStatus(200)
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.user_id', $recruiter->id)
            ->assertJsonPath('data.tenant_id', $this->tenantA->id);

        $this->assertAuthenticatedAs($recruiter);

        // Verify raw Entra groups/roles did NOT elevate local RBAC roles
        $recruiter->refresh();
        $this->assertFalse($recruiter->is_platform_superadmin);
        $this->assertFalse($recruiter->hasRole('tenant_admin', $this->tenantA->id));
        $this->assertTrue($recruiter->hasRole('recruiter', $this->tenantA->id));

        // Verify replay of the same callback fails because state & nonce are single-use
        $replayResp = $this->getJson('/auth/entra/callback?'.http_build_query([
            'code' => 'valid-auth-code-123',
            'state' => $state,
        ]));
        $replayResp->assertStatus(400)
            ->assertJsonPath('error.code', 'SSO_STATE_MISSING_OR_REPLAYED');
    }

    public function test_invitation_based_first_time_sso_linking_succeeds(): void
    {
        $this->enableEntraConfig();

        $hmRole = Role::query()->where('slug', 'hiring_manager')->firstOrFail();
        $invitedEmail = 'new.sso.leader@acme-demo.test';
        $expectedOid = 'bbbbbbbb-cccc-dddd-eeee-ffffffff0002';

        $created = app(EntraSsoService::class)->createSsoInvitation(
            $this->connectionA,
            $invitedEmail,
            $hmRole,
            $this->adminA,
            $expectedOid,
        );

        $redirectResp = $this->getJson('/auth/entra/redirect?'.http_build_query([
            'invitation_token' => $created['raw_token'],
        ]));
        $redirectResp->assertStatus(200);

        $state = (string) $redirectResp->json('data.state');
        $nonce = (string) session(EntraSsoService::SESSION_FLOW_KEY)['nonce'];

        $idToken = $this->buildSignedIdToken([
            'iss' => "https://login.microsoftonline.com/{$this->entraTenantIdA}/v2.0",
            'aud' => 'test-entra-client-id',
            'tid' => $this->entraTenantIdA,
            'oid' => $expectedOid,
            'sub' => 'sub-invited-0002',
            'email' => $invitedEmail,
            'preferred_username' => $invitedEmail,
            'name' => 'Invited Hiring Leader',
            'nonce' => $nonce,
            'iat' => now()->timestamp - 5,
            'nbf' => now()->timestamp - 5,
            'exp' => now()->timestamp + 3600,
        ]);

        $this->fakeEntraTokenAndJwksEndpoints($idToken);

        $callbackResp = $this->getJson('/auth/entra/callback?'.http_build_query([
            'code' => 'invite-auth-code-456',
            'state' => $state,
        ]));

        $callbackResp->assertStatus(200)
            ->assertJsonPath('data.email', $invitedEmail)
            ->assertJsonPath('data.tenant_id', $this->tenantA->id);

        $created['invitation']->refresh();
        $this->assertSame('ACCEPTED', $created['invitation']->status);

        $linkedIdentity = ExternalIdentity::withoutGlobalScopes()
            ->where('entra_tenant_id', $this->entraTenantIdA)
            ->where('entra_object_id', $expectedOid)
            ->firstOrFail();
        $this->assertSame('ACTIVE', $linkedIdentity->status);
    }

    public function test_oidc_negative_security_controls_reject_invalid_tokens_and_prevent_silent_email_takeover(): void
    {
        $this->enableEntraConfig();

        // 1. State mismatch
        $this->getJson('/auth/entra/redirect')->assertStatus(200);
        $this->getJson('/auth/entra/callback?code=abc&state=tampered-state')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'SSO_STATE_MISMATCH');

        // 2. Nonce mismatch
        $this->assertCallbackRejectedWithClaimOverrides(
            ['nonce' => 'wrong-nonce-value'],
            'SSO_NONCE_MISMATCH',
            401,
        );

        // 3. Expired token (exp in past beyond skew)
        $this->assertCallbackRejectedWithClaimOverrides(
            ['exp' => now()->timestamp - 3600],
            'SSO_TOKEN_EXPIRED',
            401,
        );

        // 4. Not-before in future (nbf in future beyond skew)
        $this->assertCallbackRejectedWithClaimOverrides(
            ['nbf' => now()->timestamp + 3600, 'exp' => now()->timestamp + 7200],
            'SSO_TOKEN_NOT_YET_VALID',
            401,
        );

        // 5. Wrong audience (aud)
        $this->assertCallbackRejectedWithClaimOverrides(
            ['aud' => 'wrong-client-id'],
            'SSO_INVALID_AUDIENCE',
            401,
        );

        // 6. Wrong issuer (iss)
        $this->assertCallbackRejectedWithClaimOverrides(
            ['iss' => 'https://login.microsoftonline.com/99999999-9999-9999-9999-999999999999/v2.0'],
            'SSO_INVALID_ISSUER',
            401,
        );

        // 7. Missing required claims (oid missing)
        $this->assertCallbackRejectedWithClaimOverrides(
            ['oid' => ''],
            'SSO_MISSING_REQUIRED_CLAIMS',
            401,
        );

        // 8. Personal Microsoft consumer account (MSA tid = 9188040d-6c67-4c5b-b112-36a304b66dad) rejected
        $msaTid = '9188040d-6c67-4c5b-b112-36a304b66dad';
        $this->assertCallbackRejectedWithClaimOverrides(
            [
                'tid' => $msaTid,
                'iss' => "https://login.microsoftonline.com/{$msaTid}/v2.0",
            ],
            'SSO_CONSUMER_ACCOUNT_REJECTED',
            403,
        );

        // 9. Unmapped Entra directory tenant ID (tid) rejected
        $unmappedTid = '77777777-8888-9999-aaaa-bbbbbbbbbbbb';
        $this->assertCallbackRejectedWithClaimOverrides(
            [
                'tid' => $unmappedTid,
                'iss' => "https://login.microsoftonline.com/{$unmappedTid}/v2.0",
            ],
            'SSO_UNMAPPED_ENTRA_TENANT',
            403,
        );

        // 10. Existing local account (admin@acme-demo.test) with NO explicit ExternalIdentity or SsoInvitation -> rejected (no silent email linking!)
        $this->assertCallbackRejectedWithClaimOverrides(
            [
                'oid' => 'cccccccc-dddd-eeee-ffff-000000000099',
                'email' => 'admin@acme-demo.test',
                'preferred_username' => 'admin@acme-demo.test',
            ],
            'SSO_UNLINKED_EXISTING_ACCOUNT',
            403,
        );

        // 11. Tampered signature rejected
        $redirect = $this->getJson('/auth/entra/redirect');
        $state = (string) $redirect->json('data.state');
        $nonce = (string) session(EntraSsoService::SESSION_FLOW_KEY)['nonce'];
        $validToken = $this->buildSignedIdToken([
            'iss' => "https://login.microsoftonline.com/{$this->entraTenantIdA}/v2.0",
            'aud' => 'test-entra-client-id',
            'tid' => $this->entraTenantIdA,
            'oid' => 'cccccccc-dddd-eeee-ffff-000000000099',
            'sub' => 'sub-99',
            'email' => 'user99@acme-demo.test',
            'nonce' => $nonce,
            'exp' => now()->timestamp + 3600,
        ]);
        $parts = explode('.', $validToken);
        $tamperedToken = $parts[0].'.'.$parts[1].'.'.$this->base64UrlEncode('invalid-signature-bytes');
        $this->fakeEntraTokenAndJwksEndpoints($tamperedToken);

        $this->getJson('/auth/entra/callback?'.http_build_query(['code' => 'code-sig', 'state' => $state]))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'SSO_INVALID_SIGNATURE');
    }

    public function test_suspended_identity_conflicting_link_and_tenant_required_sso_enforcement(): void
    {
        $this->enableEntraConfig();

        $auditor = User::query()->where('email', 'auditor@acme-demo.test')->firstOrFail();
        $manager = User::query()->where('email', 'manager@acme-demo.test')->firstOrFail();
        $oid1 = 'dddddddd-eeee-ffff-0000-111111111111';
        $oid2 = 'eeeeeeee-ffff-0000-1111-222222222222';

        $identity = app(EntraSsoService::class)->linkUserExternalIdentity(
            $this->connectionA,
            $auditor,
            $oid1,
            'sub-auditor-1',
            $this->adminA,
        );

        // Conflicting link: cannot link same oid1 to a different user
        try {
            app(EntraSsoService::class)->linkUserExternalIdentity(
                $this->connectionA,
                $manager,
                $oid1,
                'sub-manager-conflict',
                $this->adminA,
            );
            $this->fail('Expected EntraSsoException for conflicting OID.');
        } catch (EntraSsoException $e) {
            $this->assertSame('SSO_CONFLICTING_EXTERNAL_IDENTITY', $e->errorCode);
        }

        // Conflicting link: cannot link a second different oid2 to the same user on the same connection
        try {
            app(EntraSsoService::class)->linkUserExternalIdentity(
                $this->connectionA,
                $auditor,
                $oid2,
                'sub-auditor-2',
                $this->adminA,
            );
            $this->fail('Expected EntraSsoException for conflicting user identity.');
        } catch (EntraSsoException $e) {
            $this->assertSame('SSO_CONFLICTING_EXTERNAL_IDENTITY', $e->errorCode);
        }

        // Suspend identity -> SSO login immediately rejected with SSO_IDENTITY_SUSPENDED_OR_UNLINKED
        $identity->update(['status' => ExternalIdentity::STATUS_SUSPENDED]);

        $this->assertCallbackRejectedWithClaimOverrides(
            [
                'oid' => $oid1,
                'sub' => 'sub-auditor-1',
                'email' => $auditor->email,
            ],
            'SSO_IDENTITY_SUSPENDED_OR_UNLINKED',
            403,
        );

        // Enforce tenant-required SSO (require_sso = true, allow_local_fallback = false) -> blocks local password login
        $this->tenantA->update([
            'require_sso' => true,
            'allow_local_fallback' => false,
        ]);

        $loginResp = $this->from('/login')->post('/login', [
            'email' => 'admin@acme-demo.test',
            'password' => 'AlphaDemo#2026!',
        ]);
        $loginResp->assertRedirect('/login');
        $loginResp->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->assertTrue(
            AuditEvent::withoutGlobalScopes()
                ->where('action', 'local_login_blocked_sso_required')
                ->where('error_code', 'SSO_REQUIRED_FOR_TENANT')
                ->exists()
        );
    }

    public function test_tenant_admin_can_configure_entra_connection_invitations_and_identity_lifecycle_via_iam_console(): void
    {
        $newEntraTid = '22222222-3333-4444-5555-666666666666';

        // 1. Store Entra directory connection via IAM console
        $this->actingAs($this->adminA)
            ->withSession(['active_tenant_id' => $this->tenantA->id])
            ->post('/admin/tenants-iam/sso/connections', [
                'display_name' => 'Acme Secondary Entra Directory',
                'entra_tenant_id' => $newEntraTid,
                'allowed_domains' => 'acme-demo.test, sub.acme-demo.test',
                'is_enabled' => true,
                'require_sso_for_tenant' => false,
                'allow_emergency_local_login' => true,
            ])
            ->assertRedirect(route('admin.iam.index'));

        $conn = ExternalIdentityConnection::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('entra_tenant_id', $newEntraTid)
            ->firstOrFail();

        // 2. Issue SSO invitation via IAM console
        $this->actingAs($this->adminA)
            ->withSession(['active_tenant_id' => $this->tenantA->id])
            ->post('/admin/tenants-iam/sso/invitations', [
                'connection_id' => $conn->id,
                'email' => 'invited.director@acme-demo.test',
                'role_slug' => 'hiring_manager',
            ])
            ->assertRedirect(route('admin.iam.index'));

        // 3. Explicitly link an existing tenant member via IAM console
        $validator = User::query()->where('email', 'hr.validator@acme-demo.test')->firstOrFail();
        $linkedOid = 'ffffffff-1111-2222-3333-444444444444';

        $this->actingAs($this->adminA)
            ->withSession(['active_tenant_id' => $this->tenantA->id])
            ->post('/admin/tenants-iam/sso/identities/link', [
                'connection_id' => $conn->id,
                'user_id' => $validator->id,
                'entra_object_id' => $linkedOid,
            ])
            ->assertRedirect(route('admin.iam.index'));

        $extId = ExternalIdentity::withoutGlobalScopes()
            ->where('connection_id', $conn->id)
            ->where('user_id', $validator->id)
            ->firstOrFail();
        $this->assertSame('ACTIVE', $extId->status);

        // 4. Suspend and unlink identity via IAM console
        $this->actingAs($this->adminA)
            ->withSession(['active_tenant_id' => $this->tenantA->id])
            ->post("/admin/tenants-iam/sso/identities/{$extId->id}/status", [
                'status' => 'UNLINKED',
            ])
            ->assertRedirect(route('admin.iam.index'));

        $this->assertSame('UNLINKED', $extId->fresh()->status);

        // 5. Render IAM page and verify Entra connection & linked identity appear
        $this->actingAs($this->adminA)
            ->withSession(['active_tenant_id' => $this->tenantA->id])
            ->get('/admin/tenants-iam')
            ->assertStatus(200)
            ->assertSee('Acme Secondary Entra Directory')
            ->assertSee($linkedOid);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function assertCallbackRejectedWithClaimOverrides(
        array $overrides,
        string $expectedErrorCode,
        int $expectedStatus,
    ): void {
        $redirect = $this->getJson('/auth/entra/redirect');
        $state = (string) $redirect->json('data.state');
        $nonce = (string) session(EntraSsoService::SESSION_FLOW_KEY)['nonce'];

        $defaultClaims = [
            'iss' => "https://login.microsoftonline.com/{$this->entraTenantIdA}/v2.0",
            'aud' => 'test-entra-client-id',
            'tid' => $this->entraTenantIdA,
            'oid' => '12345678-1234-1234-1234-1234567890ab',
            'sub' => 'sub-default-test',
            'email' => 'unlinked.person@acme-demo.test',
            'preferred_username' => 'unlinked.person@acme-demo.test',
            'name' => 'Test Entra User',
            'nonce' => $nonce,
            'iat' => now()->timestamp - 10,
            'nbf' => now()->timestamp - 10,
            'exp' => now()->timestamp + 3600,
        ];

        $idToken = $this->buildSignedIdToken(array_merge($defaultClaims, $overrides));
        $this->fakeEntraTokenAndJwksEndpoints($idToken);

        $this->getJson('/auth/entra/callback?'.http_build_query([
            'code' => 'test-code-999',
            'state' => $state,
        ]))
            ->assertStatus($expectedStatus)
            ->assertJsonPath('error.code', $expectedErrorCode);
    }

    private function enableEntraConfig(): void
    {
        config([
            'entra_sso.enabled' => true,
            'entra_sso.client_id' => 'test-entra-client-id',
            'entra_sso.client_secret' => 'test-entra-client-secret-value',
            'entra_sso.tenant_mode' => 'organizations',
            'entra_sso.allow_personal_msa' => false,
            'entra_sso.authority_host' => 'https://login.microsoftonline.com',
        ]);
    }

    private string $currentFakeIdToken = '';

    private bool $httpFakeRegistered = false;

    private function fakeEntraTokenAndJwksEndpoints(string $idToken): void
    {
        $this->currentFakeIdToken = $idToken;

        if (! $this->httpFakeRegistered) {
            $this->httpFakeRegistered = true;
            Http::fake(function (Request $request) {
                if (str_contains($request->url(), '/oauth2/v2.0/token')) {
                    return Http::response([
                        'token_type' => 'Bearer',
                        'scope' => 'openid profile email',
                        'expires_in' => 3600,
                        'access_token' => 'mock-entra-access-token',
                        'id_token' => $this->currentFakeIdToken,
                    ], 200);
                }

                if (str_contains($request->url(), '/discovery/v2.0/keys')) {
                    return Http::response([
                        'keys' => [$this->jwkPublicKey],
                    ], 200);
                }

                return Http::response(['error' => 'not_found'], 404);
            });
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function buildSignedIdToken(array $claims, string $alg = 'RS256', ?string $kid = null): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => $alg,
            'kid' => $kid ?? $this->keyId,
        ];

        $signingInput = $this->base64UrlEncode((string) json_encode($header))
            .'.'
            .$this->base64UrlEncode((string) json_encode($claims));

        $signature = '';
        openssl_sign($signingInput, $signature, $this->rsaPrivateKey, OPENSSL_ALGO_SHA256);

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
