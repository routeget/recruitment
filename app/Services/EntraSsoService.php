<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\EntraSsoException;
use App\Models\ExternalIdentity;
use App\Models\ExternalIdentityConnection;
use App\Models\Role;
use App\Models\SsoInvitation;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class EntraSsoService
{
    public const SESSION_FLOW_KEY = 'entra_sso_oidc_flow';

    public const PROVIDER_KEY = 'microsoft_entra_id';

    public function __construct(
        private readonly AuditService $audit,
        private readonly TenantContextService $tenantContext,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('entra_sso.enabled', false)
            && trim((string) config('entra_sso.client_id', '')) !== ''
            && trim((string) config('entra_sso.client_secret', '')) !== '';
    }

    /**
     * Initiates the OIDC Authorization Code + PKCE S256 flow with session-bound single-use state and nonce.
     *
     * @return array{authorize_url: string, state: string, nonce: string, code_challenge: string}
     */
    public function initiateAuthorizeRedirect(
        Request $request,
        ?string $invitationToken = null,
        ?string $loginHint = null,
    ): array {
        if (! $this->isEnabled()) {
            $this->audit->record(
                module: 'sso',
                action: 'sso.initiate_blocked_unconfigured',
                outcome: 'DENIED',
                errorCode: 'SSO_NOT_CONFIGURED',
                metadata: [
                    'provider' => self::PROVIDER_KEY,
                    'enabled_flag' => (bool) config('entra_sso.enabled', false),
                ],
            );

            throw new EntraSsoException(
                'SSO_NOT_CONFIGURED',
                'Microsoft Entra ID Single Sign-On is not enabled or client credentials are not configured in this environment.',
                503,
            );
        }

        $codeVerifier = $this->base64UrlEncode(random_bytes(48));
        $codeChallenge = $this->base64UrlEncode(hash('sha256', $codeVerifier, true));
        $state = $this->base64UrlEncode(random_bytes(32));
        $nonce = $this->base64UrlEncode(random_bytes(32));

        $now = now()->timestamp;
        $ttl = (int) config('entra_sso.state_ttl_seconds', 600);

        $request->session()->put(self::SESSION_FLOW_KEY, [
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => $codeVerifier,
            'invitation_token' => $invitationToken ? trim($invitationToken) : null,
            'issued_at' => $now,
            'expires_at' => $now + $ttl,
        ]);

        $authorityHost = rtrim((string) config('entra_sso.authority_host', 'https://login.microsoftonline.com'), '/');
        $tenantMode = (string) config('entra_sso.tenant_mode', 'organizations');
        $authorizeEndpoint = "{$authorityHost}/{$tenantMode}/oauth2/v2.0/authorize";

        $redirectUri = $this->resolveRedirectUri();
        $scopes = implode(' ', (array) config('entra_sso.scopes', ['openid', 'profile', 'email']));

        $queryParams = [
            'client_id' => (string) config('entra_sso.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => $scopes,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ];

        if ($loginHint !== null && trim($loginHint) !== '') {
            $queryParams['login_hint'] = trim($loginHint);
        }

        $authorizeUrl = $authorizeEndpoint.'?'.http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);

        $this->audit->record(
            module: 'sso',
            action: 'sso.authorize_initiated',
            outcome: 'SUCCESS',
            metadata: [
                'provider' => self::PROVIDER_KEY,
                'tenant_mode' => $tenantMode,
                'pkce_method' => 'S256',
                'has_invitation_token' => $invitationToken !== null && $invitationToken !== '',
            ],
        );

        return [
            'authorize_url' => $authorizeUrl,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
        ];
    }

    /**
     * Handles the OIDC authorization-code callback, enforcing single-use state/nonce, PKCE S256 verification,
     * RS256 JWKS signature validation, issuer/audience/time/tenant claims, and durable tid+oid identity mapping.
     */
    public function handleCallback(Request $request): User
    {
        if (! $this->isEnabled()) {
            throw new EntraSsoException(
                'SSO_NOT_CONFIGURED',
                'Microsoft Entra ID Single Sign-On is not enabled.',
                503,
            );
        }

        // Pull flow state immediately so state, nonce, and code_verifier are strictly single-use
        /** @var array<string, mixed>|null $flow */
        $flow = $request->session()->pull(self::SESSION_FLOW_KEY);

        if ($request->filled('error')) {
            $idpError = (string) $request->query('error', 'unknown_error');
            $this->recordFailure('sso.callback_idp_error', 'SSO_IDP_ERROR', [
                'idp_error' => $idpError,
            ]);

            throw new EntraSsoException(
                'SSO_IDP_ERROR',
                'Microsoft Entra ID returned an authentication error or sign-in was cancelled.',
                401,
            );
        }

        if (! is_array($flow) || empty($flow['state']) || empty($flow['nonce']) || empty($flow['code_verifier'])) {
            $this->recordFailure('sso.callback_failed', 'SSO_STATE_MISSING_OR_REPLAYED', []);

            throw new EntraSsoException(
                'SSO_STATE_MISSING_OR_REPLAYED',
                'OIDC session state is missing or has already been consumed.',
                400,
            );
        }

        if (now()->timestamp > (int) ($flow['expires_at'] ?? 0)) {
            $this->recordFailure('sso.callback_failed', 'SSO_STATE_EXPIRED', []);

            throw new EntraSsoException(
                'SSO_STATE_EXPIRED',
                'OIDC login session state has expired. Please initiate sign-in again.',
                400,
            );
        }

        $returnedState = (string) $request->query('state', '');
        if ($returnedState === '' || ! hash_equals((string) $flow['state'], $returnedState)) {
            $this->recordFailure('sso.callback_failed', 'SSO_STATE_MISMATCH', []);

            throw new EntraSsoException(
                'SSO_STATE_MISMATCH',
                'OIDC state parameter validation failed.',
                400,
            );
        }

        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            $this->recordFailure('sso.callback_failed', 'SSO_MISSING_AUTH_CODE', []);

            throw new EntraSsoException(
                'SSO_MISSING_AUTH_CODE',
                'Authorization code is missing from callback request.',
                400,
            );
        }

        $tokenResponse = $this->exchangeCodeForTokens($code, (string) $flow['code_verifier']);
        $idToken = (string) ($tokenResponse['id_token'] ?? '');
        if ($idToken === '') {
            $this->recordFailure('sso.callback_failed', 'SSO_MISSING_ID_TOKEN', []);

            throw new EntraSsoException(
                'SSO_MISSING_ID_TOKEN',
                'Microsoft Entra ID token response did not include an id_token.',
                401,
            );
        }

        $claims = $this->validateIdToken($idToken, (string) $flow['nonce']);

        $user = $this->resolveAndAuthenticateUser(
            $request,
            $claims,
            isset($flow['invitation_token']) ? (string) $flow['invitation_token'] : null,
        );

        return $user;
    }

    /**
     * Checks whether a given user attempting local password login is blocked because their tenant requires Microsoft Entra SSO.
     */
    public function isLocalPasswordLoginBlockedForUser(User $user): bool
    {
        if ($user->is_platform_superadmin) {
            return false;
        }

        $tenantIds = $user->memberships()
            ->where('status', 'ACTIVE')
            ->pluck('tenant_id')
            ->all();

        if ($user->current_tenant_id) {
            $tenantIds[] = (int) $user->current_tenant_id;
        }

        $tenantIds = array_values(array_unique(array_filter($tenantIds)));
        if (empty($tenantIds)) {
            return false;
        }

        $tenants = Tenant::query()->whereIn('id', $tenantIds)->get();
        foreach ($tenants as $tenant) {
            if ($tenant->require_sso && ! $tenant->allow_local_fallback) {
                return true;
            }
        }

        $enforcedConnectionExists = ExternalIdentityConnection::withoutGlobalScopes()
            ->whereIn('tenant_id', $tenantIds)
            ->where('is_enabled', true)
            ->where('require_sso_for_tenant', true)
            ->where('allow_emergency_local_login', false)
            ->exists();

        return $enforcedConnectionExists;
    }

    /**
     * Creates a tenant-scoped invitation for explicit Microsoft Entra SSO linking.
     *
     * @return array{invitation: SsoInvitation, raw_token: string}
     */
    public function createSsoInvitation(
        ExternalIdentityConnection $connection,
        string $email,
        Role $role,
        User $actor,
        ?string $expectedEntraObjectId = null,
    ): array {
        $normalizedEmail = strtolower(trim($email));
        $this->assertEmailDomainAllowed($connection, $normalizedEmail);

        $rawToken = 'sso_inv_'.Str::random(40);

        $invitation = SsoInvitation::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $connection->tenant_id,
            'connection_id' => $connection->id,
            'email' => $normalizedEmail,
            'expected_entra_object_id' => $expectedEntraObjectId ? trim($expectedEntraObjectId) : null,
            'role_id' => $role->id,
            'token_hash' => hash('sha256', $rawToken),
            'status' => SsoInvitation::STATUS_PENDING,
            'invited_by_user_id' => $actor->id,
            'expires_at' => now()->addDays(7),
        ]);

        $this->audit->record(
            module: 'sso',
            action: 'sso.invitation_created',
            outcome: 'SUCCESS',
            entityType: 'SsoInvitation',
            entityId: (string) $invitation->id,
            metadata: [
                'email' => $invitation->email,
                'role_slug' => $role->slug,
                'connection_id' => $connection->id,
            ],
            tenantId: (int) $connection->tenant_id,
            actorUserId: (int) $actor->id,
        );

        return ['invitation' => $invitation, 'raw_token' => $rawToken];
    }

    private function assertEmailDomainAllowed(ExternalIdentityConnection $connection, string $email): void
    {
        $allowedDomains = is_array($connection->allowed_domains)
            ? array_values(array_filter(array_map(static fn ($d): string => strtolower(trim((string) $d)), $connection->allowed_domains)))
            : [];

        if (empty($allowedDomains)) {
            return;
        }

        $atPos = strrpos($email, '@');
        $domain = $atPos !== false ? strtolower(trim(substr($email, $atPos + 1))) : '';

        if ($domain === '' || ! in_array($domain, $allowedDomains, true)) {
            $this->recordFailure('sso.login_denied', 'SSO_EMAIL_DOMAIN_NOT_ALLOWED', [
                'email_domain' => $domain,
                'allowed_domains' => $allowedDomains,
                'connection_id' => $connection->id,
            ], (int) $connection->tenant_id);

            throw new EntraSsoException(
                'SSO_EMAIL_DOMAIN_NOT_ALLOWED',
                "Email domain '{$domain}' is not permitted by this Microsoft Entra connection's allowed domains policy.",
                403,
            );
        }
    }

    /**
     * Explicitly links an existing tenant user to a Microsoft Entra (tid + oid) identity by an authorized administrator.
     */
    public function linkUserExternalIdentity(
        ExternalIdentityConnection $connection,
        User $targetUser,
        string $entraObjectId,
        string $subject,
        User $actor,
        ?string $preferredUsername = null,
    ): ExternalIdentity {
        $tid = strtolower(trim($connection->entra_tenant_id));
        $oid = strtolower(trim($entraObjectId));

        $existingByOid = ExternalIdentity::withoutGlobalScopes()
            ->where('provider', self::PROVIDER_KEY)
            ->where('entra_tenant_id', $tid)
            ->where('entra_object_id', $oid)
            ->first();

        if ($existingByOid !== null && (int) $existingByOid->user_id !== (int) $targetUser->id) {
            throw new EntraSsoException(
                'SSO_CONFLICTING_EXTERNAL_IDENTITY',
                'This Microsoft Entra Object ID (oid) is already linked to a different user.',
                409,
            );
        }

        $existingForUser = ExternalIdentity::withoutGlobalScopes()
            ->where('connection_id', $connection->id)
            ->where('user_id', $targetUser->id)
            ->first();

        if ($existingForUser !== null && ! hash_equals(strtolower($existingForUser->entra_object_id), $oid)) {
            throw new EntraSsoException(
                'SSO_CONFLICTING_EXTERNAL_IDENTITY',
                'User already has a different Microsoft Entra Object ID linked for this directory connection.',
                409,
            );
        }

        $identity = ExternalIdentity::withoutGlobalScopes()->updateOrCreate(
            [
                'connection_id' => $connection->id,
                'user_id' => $targetUser->id,
            ],
            [
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $connection->tenant_id,
                'provider' => self::PROVIDER_KEY,
                'entra_tenant_id' => $tid,
                'entra_object_id' => $oid,
                'subject' => trim($subject),
                'preferred_username' => $preferredUsername ?? $targetUser->email,
                'email_claim' => $targetUser->email,
                'display_name_claim' => $targetUser->name,
                'status' => ExternalIdentity::STATUS_ACTIVE,
                'linked_by_user_id' => $actor->id,
                'linked_at' => now(),
            ]
        );

        $this->audit->record(
            module: 'sso',
            action: 'sso.identity_linked_by_admin',
            outcome: 'SUCCESS',
            entityType: 'ExternalIdentity',
            entityId: (string) $identity->id,
            metadata: [
                'target_user_id' => $targetUser->id,
                'entra_tenant_id' => $tid,
                'entra_object_id' => $oid,
            ],
            tenantId: (int) $connection->tenant_id,
            actorUserId: (int) $actor->id,
        );

        return $identity;
    }

    /**
     * Converts a JWK (with RSA modulus `n` and exponent `e`, or `x5c`) into a PEM-encoded public key.
     *
     * @param  array<string, mixed>  $jwk
     */
    public function jwkToPem(array $jwk): string
    {
        if (! empty($jwk['x5c'][0]) && is_string($jwk['x5c'][0])) {
            $certBody = chunk_split($jwk['x5c'][0], 64, "\n");

            return "-----BEGIN CERTIFICATE-----\n{$certBody}-----END CERTIFICATE-----\n";
        }

        if (empty($jwk['n']) || empty($jwk['e'])) {
            throw new EntraSsoException('SSO_INVALID_JWK', 'JWK is missing RSA modulus (n) or exponent (e).', 401);
        }

        $modulus = $this->base64UrlDecode((string) $jwk['n']);
        $exponent = $this->base64UrlDecode((string) $jwk['e']);

        $modulusAsn1 = $this->encodeAsn1Integer($modulus);
        $exponentAsn1 = $this->encodeAsn1Integer($exponent);

        $rsaPublicKeySeq = $this->encodeAsn1Sequence($modulusAsn1.$exponentAsn1);
        $bitString = "\x03".$this->encodeAsn1Length(strlen($rsaPublicKeySeq) + 1)."\x00".$rsaPublicKeySeq;

        // rsaEncryption OID: 1.2.840.113549.1.1.1 + NULL
        $algoIdentifier = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $subjectPublicKeyInfo = $this->encodeAsn1Sequence($algoIdentifier.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeCodeForTokens(string $code, string $codeVerifier): array
    {
        $authorityHost = rtrim((string) config('entra_sso.authority_host', 'https://login.microsoftonline.com'), '/');
        $tenantMode = (string) config('entra_sso.tenant_mode', 'organizations');
        $tokenEndpoint = "{$authorityHost}/{$tenantMode}/oauth2/v2.0/token";

        $response = Http::asForm()
            ->timeout(10)
            ->connectTimeout(5)
            ->withOptions(['allow_redirects' => false])
            ->post($tokenEndpoint, [
                'client_id' => (string) config('entra_sso.client_id'),
                'client_secret' => (string) config('entra_sso.client_secret'),
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->resolveRedirectUri(),
                'code_verifier' => $codeVerifier,
                'scope' => implode(' ', (array) config('entra_sso.scopes', ['openid', 'profile', 'email'])),
            ]);

        if (! $response->successful()) {
            $this->recordFailure('sso.token_exchange_failed', 'SSO_TOKEN_EXCHANGE_FAILED', [
                'http_status' => $response->status(),
                'oauth_error' => $response->json('error') ?? 'token_endpoint_rejected',
            ]);

            throw new EntraSsoException(
                'SSO_TOKEN_EXCHANGE_FAILED',
                'Failed to exchange authorization code with Microsoft Entra ID token endpoint.',
                401,
            );
        }

        return (array) $response->json();
    }

    /**
     * Validates JWT header, RS256 signature against Microsoft Entra JWKS, and all mandatory OIDC claims.
     *
     * @return array<string, mixed>
     */
    public function validateIdToken(string $idToken, string $expectedNonce): array
    {
        $segments = explode('.', $idToken);
        if (count($segments) !== 3) {
            $this->recordFailure('sso.token_validation_failed', 'SSO_MALFORMED_ID_TOKEN', []);
            throw new EntraSsoException('SSO_MALFORMED_ID_TOKEN', 'ID token is not a valid 3-segment JWT.', 401);
        }

        [$headerB64, $payloadB64, $signatureB64] = $segments;

        $header = json_decode($this->base64UrlDecode($headerB64), true);
        $claims = json_decode($this->base64UrlDecode($payloadB64), true);
        $signature = $this->base64UrlDecode($signatureB64);

        if (! is_array($header) || ! is_array($claims)) {
            $this->recordFailure('sso.token_validation_failed', 'SSO_MALFORMED_ID_TOKEN', []);
            throw new EntraSsoException('SSO_MALFORMED_ID_TOKEN', 'ID token header or payload JSON is invalid.', 401);
        }

        $alg = (string) ($header['alg'] ?? '');
        if ($alg !== 'RS256') {
            $this->recordFailure('sso.token_validation_failed', 'SSO_INVALID_SIGNATURE_ALG', ['alg' => $alg]);
            throw new EntraSsoException('SSO_INVALID_SIGNATURE_ALG', 'ID token must be signed with RS256.', 401);
        }

        $kid = (string) ($header['kid'] ?? '');
        if ($kid === '') {
            $this->recordFailure('sso.token_validation_failed', 'SSO_MISSING_KEY_ID', []);
            throw new EntraSsoException('SSO_MISSING_KEY_ID', 'ID token header is missing signing key ID (kid).', 401);
        }

        $jwk = $this->findSigningKeyByKid($kid);
        $pem = $this->jwkToPem($jwk);

        $signingInput = $headerB64.'.'.$payloadB64;
        $verified = openssl_verify($signingInput, $signature, $pem, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            $this->recordFailure('sso.token_validation_failed', 'SSO_INVALID_SIGNATURE', ['kid' => $kid]);
            throw new EntraSsoException('SSO_INVALID_SIGNATURE', 'ID token cryptographic signature verification failed.', 401);
        }

        $tid = strtolower(trim((string) ($claims['tid'] ?? '')));
        $oid = strtolower(trim((string) ($claims['oid'] ?? '')));
        $sub = trim((string) ($claims['sub'] ?? ''));

        if ($tid === '' || $oid === '' || $sub === '') {
            $this->recordFailure('sso.token_validation_failed', 'SSO_MISSING_REQUIRED_CLAIMS', [
                'has_tid' => $tid !== '',
                'has_oid' => $oid !== '',
                'has_sub' => $sub !== '',
            ]);
            throw new EntraSsoException(
                'SSO_MISSING_REQUIRED_CLAIMS',
                'ID token is missing mandatory Microsoft Entra claims (tid, oid, or sub).',
                401,
            );
        }

        // Reject personal Microsoft accounts (MSA consumer tenant) unless explicitly allowed
        $consumerTenantId = strtolower((string) config('entra_sso.consumer_tenant_id', '9188040d-6c67-4c5b-b112-36a304b66dad'));
        if ($tid === $consumerTenantId && ! (bool) config('entra_sso.allow_personal_msa', false)) {
            $this->recordFailure('sso.token_validation_failed', 'SSO_CONSUMER_ACCOUNT_REJECTED', ['tid' => $tid]);
            throw new EntraSsoException(
                'SSO_CONSUMER_ACCOUNT_REJECTED',
                'Personal Microsoft consumer accounts are not permitted. Please sign in with an organizational work or school account.',
                403,
            );
        }

        $authorityHost = rtrim((string) config('entra_sso.authority_host', 'https://login.microsoftonline.com'), '/');
        $expectedIssuer = "{$authorityHost}/{$tid}/v2.0";
        $actualIssuer = (string) ($claims['iss'] ?? '');

        if (! hash_equals($expectedIssuer, $actualIssuer)) {
            $this->recordFailure('sso.token_validation_failed', 'SSO_INVALID_ISSUER', [
                'expected_issuer' => $expectedIssuer,
                'actual_issuer' => $actualIssuer,
            ]);
            throw new EntraSsoException('SSO_INVALID_ISSUER', 'ID token issuer (iss) does not match expected Microsoft Entra tenant issuer.', 401);
        }

        $expectedAudience = (string) config('entra_sso.client_id');
        $actualAudience = $claims['aud'] ?? '';
        $audMatches = is_array($actualAudience)
            ? in_array($expectedAudience, $actualAudience, true)
            : hash_equals($expectedAudience, (string) $actualAudience);

        if (! $audMatches) {
            $this->recordFailure('sso.token_validation_failed', 'SSO_INVALID_AUDIENCE', [
                'actual_aud' => $actualAudience,
            ]);
            throw new EntraSsoException('SSO_INVALID_AUDIENCE', 'ID token audience (aud) does not match configured client_id.', 401);
        }

        $now = now()->timestamp;
        $skew = (int) config('entra_sso.clock_skew_seconds', 60);

        $exp = isset($claims['exp']) ? (int) $claims['exp'] : 0;
        if ($exp <= 0 || ($exp + $skew) < $now) {
            $this->recordFailure('sso.token_validation_failed', 'SSO_TOKEN_EXPIRED', ['exp' => $exp]);
            throw new EntraSsoException('SSO_TOKEN_EXPIRED', 'ID token has expired (exp).', 401);
        }

        if (isset($claims['nbf']) && ((int) $claims['nbf'] - $skew) > $now) {
            $this->recordFailure('sso.token_validation_failed', 'SSO_TOKEN_NOT_YET_VALID', ['nbf' => $claims['nbf']]);
            throw new EntraSsoException('SSO_TOKEN_NOT_YET_VALID', 'ID token is not yet valid (nbf).', 401);
        }

        $tokenNonce = (string) ($claims['nonce'] ?? '');
        if ($tokenNonce === '' || ! hash_equals($expectedNonce, $tokenNonce)) {
            $this->recordFailure('sso.token_validation_failed', 'SSO_NONCE_MISMATCH', []);
            throw new EntraSsoException('SSO_NONCE_MISMATCH', 'ID token nonce does not match session nonce.', 401);
        }

        return $claims;
    }

    /**
     * Resolves the recruitment tenant connection and user identity from verified Entra claims.
     * Enforces:
     * - Unmapped Entra `tid` is rejected
     * - Raw Entra `groups` / `roles` never grant or elevate local RBAC roles
     * - Existing local accounts are NEVER silently linked by email alone
     * - Suspended / unlinked external identities or inactive memberships are rejected
     *
     * @param  array<string, mixed>  $claims
     */
    private function resolveAndAuthenticateUser(
        Request $request,
        array $claims,
        ?string $invitationToken,
    ): User {
        $tid = strtolower(trim((string) $claims['tid']));
        $oid = strtolower(trim((string) $claims['oid']));
        $sub = trim((string) $claims['sub']);
        $emailClaim = strtolower(trim((string) ($claims['email'] ?? $claims['preferred_username'] ?? '')));
        $preferredUsername = strtolower(trim((string) ($claims['preferred_username'] ?? $emailClaim)));
        $displayName = trim((string) ($claims['name'] ?? $emailClaim ?: 'Microsoft Entra User'));

        /** @var ExternalIdentityConnection|null $connection */
        $connection = ExternalIdentityConnection::withoutGlobalScopes()
            ->where('provider', self::PROVIDER_KEY)
            ->where('entra_tenant_id', $tid)
            ->first();

        if ($connection === null) {
            $this->recordFailure('sso.login_denied', 'SSO_UNMAPPED_ENTRA_TENANT', [
                'entra_tenant_id' => $tid,
                'entra_object_id' => $oid,
            ]);

            throw new EntraSsoException(
                'SSO_UNMAPPED_ENTRA_TENANT',
                'Your organization Microsoft Entra directory (tid) is not registered for Single Sign-On in this platform.',
                403,
            );
        }

        if (! $connection->is_enabled) {
            $this->recordFailure('sso.login_denied', 'SSO_CONNECTION_DISABLED', [
                'entra_tenant_id' => $tid,
                'connection_id' => $connection->id,
            ], (int) $connection->tenant_id);

            throw new EntraSsoException(
                'SSO_CONNECTION_DISABLED',
                'Microsoft Entra ID Single Sign-On connection is currently disabled for this tenant.',
                403,
            );
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->find($connection->tenant_id);
        if ($tenant === null || $tenant->status !== 'ACTIVE') {
            $this->recordFailure('sso.login_denied', 'SSO_TENANT_INACTIVE', [
                'entra_tenant_id' => $tid,
            ], (int) $connection->tenant_id);

            throw new EntraSsoException(
                'SSO_TENANT_INACTIVE',
                'Target recruitment tenant is not active.',
                403,
            );
        }

        if ($emailClaim !== '') {
            $this->assertEmailDomainAllowed($connection, $emailClaim);
        }

        $safeClaimsSnapshot = [
            'tid' => $tid,
            'oid' => $oid,
            'sub' => $sub,
            'preferred_username' => $preferredUsername,
            'email' => $emailClaim,
            'name' => $displayName,
            'idp_groups_ignored_for_rbac' => isset($claims['groups']) || isset($claims['roles']),
        ];

        $user = DB::transaction(function () use (
            $connection,
            $tenant,
            $tid,
            $oid,
            $sub,
            $emailClaim,
            $preferredUsername,
            $displayName,
            $invitationToken,
            $safeClaimsSnapshot
        ): User {
            /** @var ExternalIdentity|null $externalIdentity */
            $externalIdentity = ExternalIdentity::withoutGlobalScopes()
                ->where('provider', self::PROVIDER_KEY)
                ->where('entra_tenant_id', $tid)
                ->where('entra_object_id', $oid)
                ->lockForUpdate()
                ->first();

            if ($externalIdentity !== null) {
                if ((int) $externalIdentity->tenant_id !== (int) $tenant->id || (int) $externalIdentity->connection_id !== (int) $connection->id) {
                    $this->recordFailure('sso.login_denied', 'SSO_TENANT_BOUNDARY_MISMATCH', [
                        'entra_tenant_id' => $tid,
                        'entra_object_id' => $oid,
                    ], (int) $tenant->id);

                    throw new EntraSsoException(
                        'SSO_TENANT_BOUNDARY_MISMATCH',
                        'External identity belongs to a different tenant connection.',
                        403,
                    );
                }

                if ($externalIdentity->status !== ExternalIdentity::STATUS_ACTIVE) {
                    $this->recordFailure('sso.login_denied', 'SSO_IDENTITY_SUSPENDED_OR_UNLINKED', [
                        'entra_tenant_id' => $tid,
                        'entra_object_id' => $oid,
                        'identity_status' => $externalIdentity->status,
                    ], (int) $tenant->id, (int) $externalIdentity->user_id);

                    throw new EntraSsoException(
                        'SSO_IDENTITY_SUSPENDED_OR_UNLINKED',
                        'Your Microsoft Entra external identity link has been suspended or unlinked by an administrator.',
                        403,
                    );
                }

                /** @var User|null $linkedUser */
                $linkedUser = User::query()->find($externalIdentity->user_id);
                if ($linkedUser === null || ! $linkedUser->isMemberOfTenant((int) $tenant->id)) {
                    $this->recordFailure('sso.login_denied', 'SSO_MEMBERSHIP_INACTIVE', [
                        'entra_tenant_id' => $tid,
                        'entra_object_id' => $oid,
                    ], (int) $tenant->id, $linkedUser?->id);

                    throw new EntraSsoException(
                        'SSO_MEMBERSHIP_INACTIVE',
                        'User does not have an active tenant membership in the target tenant.',
                        403,
                    );
                }

                $externalIdentity->update([
                    'subject' => $sub,
                    'preferred_username' => $preferredUsername,
                    'email_claim' => $emailClaim,
                    'display_name_claim' => $displayName,
                    'last_authenticated_at' => now(),
                    'last_claims_snapshot' => $safeClaimsSnapshot,
                ]);

                return $linkedUser;
            }

            // No existing ExternalIdentity for (tid, oid). Check explicit SsoInvitation first.
            if ($invitationToken !== null && $invitationToken !== '') {
                return $this->redeemSsoInvitation(
                    $connection,
                    $tenant,
                    $invitationToken,
                    $tid,
                    $oid,
                    $sub,
                    $emailClaim,
                    $preferredUsername,
                    $displayName,
                    $safeClaimsSnapshot,
                );
            }

            // Never silently link an existing local user account by email claim alone!
            if ($emailClaim !== '' && User::query()->where('email', $emailClaim)->exists()) {
                $this->recordFailure('sso.login_denied', 'SSO_UNLINKED_EXISTING_ACCOUNT', [
                    'entra_tenant_id' => $tid,
                    'entra_object_id' => $oid,
                    'email_claim' => $emailClaim,
                ], (int) $tenant->id);

                throw new EntraSsoException(
                    'SSO_UNLINKED_EXISTING_ACCOUNT',
                    'An existing local account matches this email address, but silent email-based linking is prohibited. An administrator must explicitly link your Microsoft Entra identity or issue an SSO invitation.',
                    403,
                );
            }

            if (! $connection->jit_provisioning_enabled) {
                $this->recordFailure('sso.login_denied', 'SSO_USER_NOT_PROVISIONED', [
                    'entra_tenant_id' => $tid,
                    'entra_object_id' => $oid,
                ], (int) $tenant->id);

                throw new EntraSsoException(
                    'SSO_USER_NOT_PROVISIONED',
                    'Your Microsoft Entra account is not provisioned in this recruitment tenant. Please request an invitation from your Tenant Administrator.',
                    403,
                );
            }

            if ($emailClaim === '') {
                throw new EntraSsoException(
                    'SSO_MISSING_EMAIL_FOR_JIT',
                    'Email or preferred_username claim is required for JIT provisioning.',
                    403,
                );
            }

            // Safe JIT provisioning with non-elevated default role (never platform_superadmin or tenant_admin)
            $safeRoleSlug = in_array($connection->default_jit_role_slug, ['platform_superadmin', 'tenant_admin'], true)
                ? 'hiring_manager'
                : $connection->default_jit_role_slug;

            $role = Role::query()->where('slug', $safeRoleSlug)->first()
                ?? Role::query()->where('slug', 'hiring_manager')->firstOrFail();

            $newUser = User::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => $displayName,
                'email' => $emailClaim,
                'password' => Hash::make(Str::random(40)),
                'current_tenant_id' => $tenant->id,
                'is_platform_superadmin' => false,
                'mfa_enabled' => true,
            ]);

            TenantMembership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $newUser->id,
                'role_id' => $role->id,
                'status' => 'ACTIVE',
                'abac_scope' => ['scope_mode' => 'tenant_scoped', 'provisioned_via' => 'entra_jit'],
            ]);

            ExternalIdentity::withoutGlobalScopes()->create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'user_id' => $newUser->id,
                'connection_id' => $connection->id,
                'provider' => self::PROVIDER_KEY,
                'entra_tenant_id' => $tid,
                'entra_object_id' => $oid,
                'subject' => $sub,
                'preferred_username' => $preferredUsername,
                'email_claim' => $emailClaim,
                'display_name_claim' => $displayName,
                'status' => ExternalIdentity::STATUS_ACTIVE,
                'linked_at' => now(),
                'last_authenticated_at' => now(),
                'last_claims_snapshot' => $safeClaimsSnapshot,
            ]);

            return $newUser;
        });

        // Regenerate session ID on login to prevent session fixation
        $request->session()->regenerate();
        Auth::login($user);

        $user->forceFill([
            'current_tenant_id' => $tenant->id,
            'last_login_at' => now(),
        ])->save();

        $request->session()->put('active_tenant_id', $tenant->id);
        $request->session()->put('auth_method', 'microsoft_entra_sso');
        $this->tenantContext->setActiveTenant($tenant);

        $this->audit->record(
            module: 'sso',
            action: 'sso.login_succeeded',
            outcome: 'SUCCESS',
            entityType: 'User',
            entityId: (string) $user->id,
            metadata: [
                'provider' => self::PROVIDER_KEY,
                'entra_tenant_id' => $tid,
                'entra_object_id' => $oid,
                'connection_id' => $connection->id,
                'idp_groups_ignored_for_rbac' => true,
            ],
            tenantId: (int) $tenant->id,
            actorUserId: (int) $user->id,
        );

        return $user;
    }

    /**
     * @param  array<string, mixed>  $safeClaimsSnapshot
     */
    private function redeemSsoInvitation(
        ExternalIdentityConnection $connection,
        Tenant $tenant,
        string $invitationToken,
        string $tid,
        string $oid,
        string $sub,
        string $emailClaim,
        string $preferredUsername,
        string $displayName,
        array $safeClaimsSnapshot,
    ): User {
        $tokenHash = hash('sha256', trim($invitationToken));

        /** @var SsoInvitation|null $invitation */
        $invitation = SsoInvitation::withoutGlobalScopes()
            ->where('connection_id', $connection->id)
            ->where('token_hash', $tokenHash)
            ->lockForUpdate()
            ->first();

        if ($invitation === null || $invitation->status !== SsoInvitation::STATUS_PENDING || $invitation->expires_at->isPast()) {
            $this->recordFailure('sso.login_denied', 'SSO_INVITATION_INVALID_OR_EXPIRED', [
                'entra_tenant_id' => $tid,
                'entra_object_id' => $oid,
            ], (int) $tenant->id);

            throw new EntraSsoException(
                'SSO_INVITATION_INVALID_OR_EXPIRED',
                'SSO invitation token is invalid, already used, or expired.',
                403,
            );
        }

        if ($invitation->expected_entra_object_id !== null && ! hash_equals(strtolower($invitation->expected_entra_object_id), $oid)) {
            $this->recordFailure('sso.login_denied', 'SSO_INVITATION_OID_MISMATCH', [
                'entra_tenant_id' => $tid,
                'entra_object_id' => $oid,
            ], (int) $tenant->id);

            throw new EntraSsoException(
                'SSO_INVITATION_OID_MISMATCH',
                'Authenticated Microsoft Entra Object ID does not match the invitation target.',
                403,
            );
        }

        if ($emailClaim === '' || ! hash_equals(strtolower($invitation->email), $emailClaim)) {
            $this->recordFailure('sso.login_denied', 'SSO_INVITATION_EMAIL_MISMATCH', [
                'entra_tenant_id' => $tid,
                'entra_object_id' => $oid,
            ], (int) $tenant->id);

            throw new EntraSsoException(
                'SSO_INVITATION_EMAIL_MISMATCH',
                'Authenticated Microsoft Entra email claim does not match the invited email address.',
                403,
            );
        }

        $user = User::query()->firstOrCreate(
            ['email' => $emailClaim],
            [
                'uuid' => (string) Str::uuid(),
                'name' => $displayName,
                'password' => Hash::make(Str::random(40)),
                'current_tenant_id' => $tenant->id,
                'is_platform_superadmin' => false,
                'mfa_enabled' => true,
            ]
        );

        $existingIdentityForUser = ExternalIdentity::withoutGlobalScopes()
            ->where('connection_id', $connection->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existingIdentityForUser !== null && ! hash_equals(strtolower($existingIdentityForUser->entra_object_id), $oid)) {
            $this->recordFailure('sso.login_denied', 'SSO_CONFLICTING_EXTERNAL_IDENTITY', [
                'entra_tenant_id' => $tid,
                'entra_object_id' => $oid,
                'user_id' => $user->id,
            ], (int) $tenant->id, (int) $user->id);

            throw new EntraSsoException(
                'SSO_CONFLICTING_EXTERNAL_IDENTITY',
                'User already has a different Microsoft Entra identity linked for this tenant connection.',
                409,
            );
        }

        TenantMembership::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role_id' => $invitation->role_id,
            ],
            [
                'status' => 'ACTIVE',
                'abac_scope' => ['scope_mode' => 'tenant_scoped', 'provisioned_via' => 'sso_invitation'],
            ]
        );

        ExternalIdentity::withoutGlobalScopes()->updateOrCreate(
            [
                'connection_id' => $connection->id,
                'user_id' => $user->id,
            ],
            [
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'provider' => self::PROVIDER_KEY,
                'entra_tenant_id' => $tid,
                'entra_object_id' => $oid,
                'subject' => $sub,
                'preferred_username' => $preferredUsername,
                'email_claim' => $emailClaim,
                'display_name_claim' => $displayName,
                'status' => ExternalIdentity::STATUS_ACTIVE,
                'linked_by_user_id' => $invitation->invited_by_user_id,
                'linked_at' => now(),
                'last_authenticated_at' => now(),
                'last_claims_snapshot' => $safeClaimsSnapshot,
            ]
        );

        $invitation->update([
            'status' => SsoInvitation::STATUS_ACCEPTED,
            'accepted_by_user_id' => $user->id,
            'accepted_at' => now(),
        ]);

        return $user;
    }

    /**
     * Fetches JWKS signing keys from Microsoft Entra discovery keys endpoint and finds the key matching `kid`.
     *
     * @return array<string, mixed>
     */
    private function findSigningKeyByKid(string $kid): array
    {
        $authorityHost = rtrim((string) config('entra_sso.authority_host', 'https://login.microsoftonline.com'), '/');
        $tenantMode = (string) config('entra_sso.tenant_mode', 'organizations');
        $jwksUri = "{$authorityHost}/{$tenantMode}/discovery/v2.0/keys";

        $cacheKey = 'entra_sso_jwks_'.md5($jwksUri);
        $jwks = Cache::remember($cacheKey, (int) config('entra_sso.jwks_cache_ttl_seconds', 3600), function () use ($jwksUri): array {
            $resp = Http::timeout(8)->connectTimeout(4)->get($jwksUri);
            if (! $resp->successful()) {
                throw new EntraSsoException('SSO_JWKS_FETCH_FAILED', 'Unable to retrieve Microsoft Entra JWKS signing keys.', 503);
            }

            return (array) $resp->json();
        });

        foreach ((array) ($jwks['keys'] ?? []) as $key) {
            if (is_array($key) && isset($key['kid']) && hash_equals((string) $key['kid'], $kid)) {
                return $key;
            }
        }

        // Refresh once on cache miss in case of key rotation
        Cache::forget($cacheKey);
        $freshResp = Http::timeout(8)->connectTimeout(4)->get($jwksUri);
        if ($freshResp->successful()) {
            $freshJwks = (array) $freshResp->json();
            foreach ((array) ($freshJwks['keys'] ?? []) as $key) {
                if (is_array($key) && isset($key['kid']) && hash_equals((string) $key['kid'], $kid)) {
                    return $key;
                }
            }
        }

        $this->recordFailure('sso.token_validation_failed', 'SSO_UNKNOWN_SIGNING_KEY', ['kid' => $kid]);

        throw new EntraSsoException(
            'SSO_UNKNOWN_SIGNING_KEY',
            'ID token signing key ID (kid) was not found in Microsoft Entra JWKS metadata.',
            401,
        );
    }

    private function resolveRedirectUri(): string
    {
        $configured = trim((string) config('entra_sso.redirect_uri', '/auth/entra/callback'));
        if (str_starts_with($configured, 'https://') || str_starts_with($configured, 'http://')) {
            return $configured;
        }

        return url($configured);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordFailure(
        string $action,
        string $errorCode,
        array $metadata,
        ?int $tenantId = null,
        ?int $actorUserId = null,
    ): void {
        $this->audit->record(
            module: 'sso',
            action: $action,
            outcome: 'DENIED',
            errorCode: $errorCode,
            metadata: array_merge(['provider' => self::PROVIDER_KEY], $metadata),
            tenantId: $tenantId,
            actorUserId: $actorUserId,
        );
    }

    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder > 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($input, '-_', '+/'));
    }

    private function encodeAsn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $temp = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($temp)).$temp;
    }

    private function encodeAsn1Integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".$this->encodeAsn1Length(strlen($bytes)).$bytes;
    }

    private function encodeAsn1Sequence(string $bytes): string
    {
        return "\x30".$this->encodeAsn1Length(strlen($bytes)).$bytes;
    }
}
