# Microsoft Entra ID Enterprise Single Sign-On (SSO)
## Level 2 Functional and Technical Specification

**Project:** Unified Recruitment AI Platform  
**Document status:** Approved requirement for implementation planning  
**Priority:** P0 enterprise capability  
**Target stack:** PHP 8.5, Laravel 13, Bootstrap 5, MariaDB, Redis, NGINX  
**Authentication protocol:** OpenID Connect (OIDC) over OAuth 2.0 authorization code flow, with PKCE  
**Provider:** Microsoft Entra ID, formerly Azure Active Directory

## 1. Business requirement

The platform MUST provide enterprise single sign-on using organizational Microsoft work or school identities. Most enterprise customers use Microsoft 365 identities, and users must be able to sign in using their organization's official account without creating or remembering a separate platform password when the organization has enabled SSO.

This is an authentication capability, not automatic authorization. A successful Microsoft sign-in proves an external identity; it MUST NOT by itself grant platform access, tenant membership, a role, elevated permissions, or access to candidate data.

## 2. Required account audience and SaaS model

The product is a multi-tenant SaaS application serving multiple organizations. The intended Microsoft Entra app registration audience is **Accounts in any organizational directory (multitenant work or school accounts only)**, corresponding to AzureADMultipleOrgs. Do not enable personal Microsoft accounts (Outlook.com, Hotmail, Xbox, Skype, or consumer MSA) by default. Keep the audience configurable only through an explicitly reviewed release/configuration change.

Each customer organization connects its Microsoft Entra tenant to exactly one approved application tenant. Multiple application tenants may use different Microsoft Entra tenant IDs. A single user may be a member of more than one application tenant only through explicit invitations/membership records and must switch tenant context deliberately.

## 3. User experience

1. The login page displays a clearly labeled **Sign in with Microsoft work or school account** button.
2. Selecting it starts a server-side OIDC authorization-code flow and redirects to Microsoft.
3. The user authenticates using their organization-managed Microsoft 365 identity and is subject to the organization's Conditional Access, MFA, and sign-in policies.
4. Microsoft redirects back to the exact registered HTTPS callback. The application validates the response and resolves the identity against the configured Entra connection.
5. The application maps the identity to an existing user and approved tenant membership, or follows a tenant's explicitly enabled invitation/JIT provisioning policy.
6. The application establishes a normal Laravel session with session-ID rotation, active tenant context, and standard RBAC/ABAC enforcement.
7. Existing local authentication may remain as a separately configured recovery/admin path. Its visibility and availability must be configurable per environment and customer policy.
8. Logout invalidates the local session and application tokens. If Microsoft sign-out is initiated, it must use the documented Entra end-session behavior and a safe post-logout redirect. The UI must not claim that local logout revokes the user's Microsoft session globally.

## 4. Security architecture

### 4.1 OIDC flow

Use a maintained, security-reviewed OIDC client implementation compatible with Laravel/PHP. Follow Microsoft identity platform OIDC documentation. Use authorization code flow, PKCE, and a confidential server-side client. Do not use implicit flow, resource-owner password credentials, password collection, or client secrets in browser JavaScript.

Required controls:
- Cryptographically random, single-use state, bound to the initiating session and validated on callback.
- OIDC nonce, validated against the ID token.
- PKCE S256 challenge/verifier.
- Exact redirect URI allowlisting. No open redirects or caller-controlled callback URLs.
- TLS verification enabled for all identity-provider endpoints.
- Validate token signature using trusted Microsoft signing keys and rotate keys safely.
- Validate iss, aud, exp, nbf, iat where applicable, nonce, and expected tenant/audience semantics. Validate tid against the connection/allowlist and ensure issuer and tenant claims are mutually consistent.
- Reject malformed, unsigned, wrong-audience, expired, not-yet-valid, replayed, or wrong-tenant tokens.
- Use discovery metadata from the correct Microsoft identity platform authority. Do not construct a trust decision from unverified claims.
- Do not accept arbitrary issuer/authority values supplied in a login request.
- Apply request throttling, generic user-facing errors, correlation IDs, and secret/PII-safe audit logging.
- Rotate Laravel session IDs after authentication and tenant switching. Enforce secure, HttpOnly, SameSite cookie settings appropriate to the deployment.
- Do not store ID/access tokens in localStorage or expose client secrets to the browser. Store server-side token material only when a downstream API integration explicitly requires it, encrypt at rest, scope minimally, and define retention/revocation.

### 4.2 Identity keys and account linking

- Persist the provider identity using stable Microsoft tenant and object identifiers, typically the validated tid + oid pair, along with issuer/subject data required by the chosen OIDC library.
- Never use email address, UPN, display name, or email domain as the unique authentication identity key. These values can change and are not sufficient proof of identity.
- Email/UPN may be stored as profile attributes after validation and updated under a defined synchronization policy. Treat them as untrusted for authorization decisions.
- Never silently link a Microsoft identity to a local account solely because the email strings match. Require an authenticated account-linking flow or an approved tenant-admin invitation/mapping process.
- Prevent duplicate external-identity mappings and enforce uniqueness at the database level.
- Audit linking, unlinking, first login, denied login, and identity mapping changes without recording raw tokens or authorization codes.

## 5. Tenant enrollment, access and provisioning

### 5.1 Connection model

Create a configurable Entra SSO connection associated with an application tenant. It should include:
- Application tenant ID and connection UUID.
- Microsoft Entra tenant ID (tid) and verified display metadata.
- Connection status: DRAFT, PENDING_ADMIN_CONSENT, ACTIVE, SUSPENDED, ERROR, DISABLED.
- Login policy: invited users only by default; optional JIT provisioning with explicit enablement.
- Allowed issuer/authority mode, client ID, redirect URI, logout redirect allowlist, required-claims policy, and timestamps for validation/last successful sign-in.
- Secret/certificate reference held in environment configuration or a managed secret store. Do not store plaintext client secrets in MariaDB or show them in admin pages.
- Created/approved by, consent evidence/status, and immutable audit events.

Do not assume that a customer tenant is authorized merely because a user signs in successfully. A tenant connection must be established through an explicit platform-superadmin/customer-admin onboarding workflow, with appropriate proof of tenant control and any required admin consent. Never associate a tenant using only an email suffix or the preferred_username claim.

### 5.2 Access decision

After cryptographic token validation:
1. Resolve the approved Entra connection by validated tid/issuer.
2. Resolve the external identity by stable provider identifiers.
3. Confirm the connection is ACTIVE and not suspended.
4. Confirm the user is active and belongs to the mapped application tenant through an active membership/invitation.
5. Apply tenant access policy, role permissions, ABAC scopes, account status, and any platform-level suspension.
6. Establish session and active tenant only after all checks succeed.

If no identity mapping or invitation exists, deny access with a safe message. Do not automatically grant tenant_admin, platform_superadmin, HR approver, or any other role. JIT provisioning, if enabled, creates only a minimally privileged pending user/membership that requires the configured approval process.

### 5.3 Provisioning and deprovisioning

For the initial release, support invitation-based provisioning and optional controlled JIT provisioning. Microsoft Graph group synchronization and SCIM provisioning are separate integrations and MUST NOT be implied by basic OIDC sign-in. They may be added later through explicit permission, consent, mapping, and lifecycle specifications.

Provide an admin workflow to suspend an SSO connection, remove a user's tenant membership, unlink an identity, and revoke local sessions. A disabled Entra account or removed group membership is not guaranteed to terminate an already active application session immediately; the product must document and implement appropriate session lifetime/revalidation controls. Do not request Microsoft Graph permissions merely to obtain profile data available in validated OIDC claims.

## 6. Role and permission boundaries

- SSO establishes authentication only. Application authorization remains in the existing RBAC/ABAC layer.
- Entra directory roles and app roles must not map automatically to privileged platform roles without an explicit administrator-controlled mapping and validation.
- An Entra administrator's status in Microsoft 365 does not make that user a platform superadmin or tenant admin.
- Tenant selection must be server-side validated against active memberships. Never trust a posted tenant_id as proof of access.
- API client/service identities remain separate from interactive human identities.
- Break-glass access is separate, explicitly audited, and subject to the existing ticket/reason policy.

## 7. Configuration and operations

Configuration must be managed through environment/secret store and tenant administration UI without exposing secrets. At minimum:
- MICROSOFT_ENTRA_SSO_ENABLED
- MICROSOFT_ENTRA_CLIENT_ID
- MICROSOFT_ENTRA_CLIENT_SECRET or a certificate/client-assertion secret reference
- MICROSOFT_ENTRA_TENANT_MODE with allowed values such as organizations or a validated tenant-specific authority; never accept arbitrary user-supplied values
- MICROSOFT_ENTRA_REDIRECT_URI
- MICROSOFT_ENTRA_POST_LOGOUT_REDIRECT_URI
- OIDC scopes kept minimal, normally openid profile email when needed; no Microsoft Graph API permissions unless a separate feature requires them
- Connection health/metadata validation status and key-rotation diagnostics that do not expose credentials

Use a secret manager where available. For the existing server, preserve .env and merge only approved keys safely; never replace the file or print its contents. Document required Entra app registration settings and exact redirect URIs in /FSD without real secrets.

## 8. Suggested data model

Implement migration naming consistent with the current repository and verify actual existing schema before writing migrations. Proposed entities:

- external_identity_connections: tenant-scoped Entra configuration, status, authority mode, client identifier, secret reference, policy, and approval metadata.
- external_identities: user ID, connection ID, validated issuer, Entra tenant ID, Entra object ID, subject, safe profile snapshot, last authenticated timestamp, and revocation/link state.
- Unique constraint on stable Entra identity tuple and unique constraint preventing duplicate mapping for a connection.
- Audit events for connection configuration, approval, sign-in outcomes, account linking, denial reasons, suspension, and revocation.

Do not store access tokens or ID tokens in identity profile rows. Add indexes and foreign keys, and design all user and connection queries to preserve tenant isolation. Migration must be additive and forward-safe. Do not add destructive rollback instructions for shared Alpha data.

## 9. Login and account lifecycle behavior

- SSO is configurable globally and per tenant; the UI must hide or disable the button when unavailable.
- A tenant may require SSO for its users. If so, local-password login must not become a bypass for that tenant, except for a separately controlled break-glass account policy.
- Existing local accounts require an explicit secure linking or migration process. Do not auto-link by email.
- Handle consent-required, tenant-not-allowed, user-not-assigned, Conditional Access, MFA, expired authorization code, invalid state/nonce, token validation failure, disabled connection, and user/membership suspension without leaking sensitive details.
- Prevent account enumeration through uniform public errors.
- Define session idle and absolute lifetime, reauthentication requirements for sensitive actions, and forced reauthentication after high-risk identity changes.
- SSO failures must not disable local recovery access for all administrators accidentally; retain a documented break-glass recovery process.

## 10. Test requirements

Automated tests MUST include:
- Successful OIDC callback using controlled test fixtures/mocked discovery and token validation.
- Invalid state, nonce, PKCE verifier, signature, issuer, audience, expiry, nbf, tenant ID, and redirect URI.
- Replay of authorization callback and reused state.
- Disabled/suspended connection, unapproved Entra tenant, missing invitation, disabled user, revoked membership, and duplicate identity mapping.
- Same email but different Entra tid/oid must not link accounts.
- Same Entra identity cannot map to two users in one prohibited scope.
- No role elevation from Entra directory role, group name, UPN, or email domain.
- Tenant A's identity cannot enter Tenant B without a valid membership.
- Session ID rotates after login and tenant switch; logout invalidates local session.
- Tenant-required-SSO policy cannot be bypassed by local password login.
- Secrets/tokens/codes never appear in logs, audit metadata, error pages, or HTML.
- Login throttling, error handling, and security headers.
- Browser tests for the login button, Microsoft redirect/callback, first-time invitation, denial, session, and logout.
- Test against a dedicated non-production Entra app registration and test tenant when credentials are available. Do not use a customer's production directory for destructive or unapproved testing.

## 11. Acceptance criteria

This requirement is complete only when:
1. Enterprise users can sign in using their Microsoft work/school account via validated OIDC.
2. The supported account audience excludes consumer Microsoft accounts by default.
3. Connection onboarding and tenant mapping are explicit and auditable.
4. No identity is linked by email alone and no sign-in automatically grants privileged roles.
5. Tenant isolation and all existing RBAC/ABAC rules remain intact.
6. Automated positive and negative security tests pass.
7. The feature is deployed to the authorized Alpha server and browser-verified using a dedicated test Entra tenant.
8. Configuration and recovery runbooks are committed under /FSD.
9. The release report records the tested commit, callback URL, tenant audience, evidence, and remaining limitations without recording secrets.

## 12. Implementation sequence

1. Inspect current authentication, user, tenant membership, session, migration, and audit implementations.
2. Design an additive schema and migration; verify compatibility with existing users and tenants.
3. Add the OIDC integration and configuration validation with a feature flag disabled by default until credentials and callback URI are configured.
4. Add the Microsoft sign-in button and callback, identity mapping, tenant access policy, account linking, logout, and admin connection management.
5. Add security tests before enabling SSO for any tenant.
6. Configure a dedicated non-production Entra application registration. Do not invent client IDs, secrets, tenant IDs, or callback URLs.
7. Run the full regression suite, Pint, dependency audit, tenant-isolation tests, and the existing HR handover safety tests.
8. Review the changes, deploy the tested commit to https://career.tringers.com, and verify with a real browser and test Entra tenant.
9. Keep SSO disabled and clearly marked not configured if the required Entra registration or credentials are unavailable. Do not simulate a real successful login in release evidence.

## 13. Official references

- Microsoft Entra single- and multitenant applications: https://learn.microsoft.com/en-us/entra/identity-platform/single-and-multi-tenant-apps
- Microsoft identity platform authorization code flow: https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow
- OpenID Connect on the Microsoft identity platform: https://learn.microsoft.com/en-us/entra/identity-platform/v2-protocols-oidc
- Identity and supported account types: https://learn.microsoft.com/en-us/security/zero-trust/develop/identity-supported-account-types
