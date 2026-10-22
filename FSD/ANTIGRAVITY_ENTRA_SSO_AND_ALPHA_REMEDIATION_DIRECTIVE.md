# Antigravity Execution Directive: Microsoft Entra SSO and Alpha Remediation

**Repository:** routeget/recruitment  
**Target branch:** create a fresh feature branch from current main  
**Authorized Alpha environment:** https://career.tringers.com  
**Application path:** /home/career/htdocs/career.tringers.com  
**Primary objective:** Implement enterprise Microsoft work/school SSO and continue the independent Alpha remediation cycle with verified tests and deployment after each application fix.

## Mandatory first steps

1. Fetch the latest main and confirm current open PRs and CI status. Do not use a stale branch as the base.
2. Read FSD/MICROSOFT_ENTRA_ID_ENTERPRISE_SSO_LEVEL_2_SPECIFICATION.md, FSD/ALPHA_INDEPENDENT_REMEDIATION_AUDIT_2026-10-22.md if available after its PR merges, FSD/ALPHA_TEST_AND_RELEASE_REPORT.md, FSD/ALPHA_DEPLOYMENT_RUNBOOK.md, and FSD/ALPHA_REQUIREMENTS_TRACEABILITY.md.
3. Confirm the current deployed SHA, runtime status, migration state, backup state, and authorized deployment procedure. Preserve all existing .env values and all Alpha data.
4. Keep all substantive documentation under FSD. Use reviewable branches and commits.
5. Do not claim live browser verification unless it was performed against the live Alpha URL. If access or Entra test credentials are missing, report the precise blocker and keep SSO disabled.

## Priority 0: Implement Microsoft Entra ID enterprise SSO

The requirement is mandatory for enterprise adoption. Enterprise users must be able to sign in with their official Microsoft 365 work or school identity.

Follow the full specification in FSD/MICROSOFT_ENTRA_ID_ENTERPRISE_SSO_LEVEL_2_SPECIFICATION.md. Key non-negotiable controls:

- Use Microsoft Entra ID via OIDC, OAuth 2.0 authorization-code flow, PKCE S256, state, nonce, signature/key validation, exact HTTPS redirect URI, issuer/audience/expiry validation, and tenant claim validation.
- Intended default audience: multitenant organizational work/school accounts only. Do not enable consumer Microsoft accounts by default.
- Authentication does not grant tenant membership or roles. Resolve an approved Entra connection by validated tid/issuer, then require active user and application-tenant membership or an explicit invitation/provisioning policy.
- Never use email, UPN, domain, display name, or preferred_username as the external identity's unique key. Never silently link an identity because the email matches.
- Never grant tenant_admin, platform_superadmin, HR approver, or other privileged roles based on Microsoft directory role or group claims without explicit, validated, admin-controlled mapping.
- Keep API-client/service identities separate from human identities.
- Do not add Microsoft Graph permissions unless a separately specified feature requires them. OIDC login alone does not imply Graph or SCIM provisioning.
- Store client secrets/certificates only in environment/secret manager; never in plaintext MariaDB, browser JavaScript, HTML, logs, or documentation.
- Feature-flag SSO off until valid Entra registration, exact callback URI, and secrets are configured. Do not invent credentials or simulate real successful authentication.
- Preserve local login/recovery according to the tenant's SSO policy and documented break-glass process. A tenant that requires SSO must not have an ordinary local-password bypass.
- Additive migrations only; inspect current schema before writing migrations. Do not reset, truncate, or roll back the shared Alpha database.
- Add the Microsoft sign-in button, callback, identity mapping, tenant-specific connection configuration/approval, invitation policy, safe logout, audit events, session rotation, throttling, and admin diagnostics.
- Run security tests for state/nonce/PKCE replay, bad signature/issuer/audience/expiry/tid, unapproved tenants, missing invitations, duplicate mappings, account-linking attacks, privilege escalation, tenant crossing, disabled identities, and token/secret leakage.
- Test with a dedicated non-production Entra application and test tenant. If these are unavailable, finish safe code/configuration and automated mocked tests but leave the feature disabled and report live Entra validation as pending.
- Deploy the tested code to the authorized Alpha server and verify the full flow in a real browser with the test Entra tenant before marking SSO complete.

## Continue the Alpha remediation findings

After the SSO implementation is independently tested, proceed through the findings recorded in the audit. Do not ignore them because SSO is a new requirement.

### Priority 1: API-client principal and MCP permission inheritance

Remove the arbitrary User lookup and global first-user fallback in ApiV1AndMcpController::resolveApiAuthContext(). Do not impersonate a human user for a machine client. Implement a tenant-bound machine principal/service authorization context, explicit tool-level scopes, and accurate audit attribution. A generic mcp.use scope must not authorize all tools. Add negative tests for candidate.search, application.get, tenant mismatch, disabled/revoked clients, and missing permissions.

### Priority 2: Idempotency concurrency

Fix ApiAndMcpPlatformService::executeIdempotent() so concurrent requests cannot both execute a side effect before the idempotency record is stored. Same key/same payload must return one result; same key/different payload must return deterministic 409; business mutations and idempotency outcomes must be atomic where they share a database. Add concurrency and rollback tests.

### Priority 3: Actual webhook delivery

Fix dispatchSignedWebhook() so it does not record a delivery as DELIVERED without an outbound request. Implement queued delivery, real response classification, retries, timeouts, TLS verification, safe secret handling, and SSRF defenses for target URLs, DNS, IP ranges, and redirects. If delivery cannot be made safe and complete, disable it and label it SIMULATED or NOT CONFIGURED, never DELIVERED.

### Priority 4: /setup/status contract

Resolve the mismatch between AuthController::setupStatus() returning 403 and the release report claiming 200. Define a safe contract, add tests, deploy the tested behavior, and align documentation with the observed live response.

### Priority 5: Database-safe rollback

Correct FSD/ALPHA_DEPLOYMENT_RUNBOOK.md so shared Alpha database rollback is never suggested casually when a migration down() method drops tables. Prefer forward corrective migrations and application-code rollback without schema destruction. Never perform destructive rollback without owner approval and a verified restore plan.

### Priority 6: Health/readiness semantics

Make health/readiness responses truthful about required Redis/cache/queue dependencies. Separate liveness from readiness if appropriate. Test dependency failures, avoid secret/stack-trace disclosure, deploy the tested change, and verify live endpoints.

### Priority 7: MariaDB version compatibility

Review the difference between the CI MariaDB version and the reported Alpha server version. Test migrations and relied-upon database behavior against the supported runtime version. CI must never connect to the live Alpha database.

## Required execution discipline

For each individual application/runtime fix:

1. Reproduce the defect and add a regression test before or alongside the fix.
2. Implement the root-cause correction. Do not weaken tests or remove assertions to obtain a green build.
3. Run relevant targeted tests, the full PHPUnit suite against MariaDB, Pint, Composer audit, and affected tenant-isolation tests.
4. Review the diff for tenant isolation, least privilege, PII/secrets, audit correctness, concurrency, migration safety, and unrelated changes.
5. Commit to a reviewable branch and verify GitHub Actions CI.
6. Back up safely and deploy the exact tested commit to https://career.tringers.com using the existing runbook. Preserve .env and all data. Never run a database reset or destructive rollback.
7. Verify deployed SHA, health/readiness, affected routes, unauthenticated/unauthorized behavior, and the affected workflow in a real browser.
8. Update FSD/ALPHA_REQUIREMENTS_TRACEABILITY.md and FSD/ALPHA_TEST_AND_RELEASE_REPORT.md with finding ID, commit, CI URL/result, tests, migration status, deployment time/SHA, browser evidence, and residual risk. Never include secret values or candidate PII.
9. Only then close the finding and move to the next one. If code is fixed but not deployed or browser-verified, mark it REMEDIATED IN CODE, DEPLOYMENT PENDING.

## Development-only credential exception

The project owner has instructed that weak-password and development personal-access-token policy findings may be marked PASS/accepted for the live development environment. Apply this only to the intentional synthetic development setup. It does not waive authentication bypass, leaked real credentials, privilege escalation, cross-tenant access, or exposure of real candidate/employee data.

## Final acceptance

The Alpha remediation cycle is complete only when:
- Microsoft work/school SSO is implemented, securely configured, and browser-verified with a test Entra tenant, or its external-credential blocker is explicitly documented and the feature remains safely disabled.
- High-severity authorization, idempotency, and webhook findings are fixed and verified.
- Full CI and regression tests pass.
- Every application/runtime fix has been deployed and verified on the authorized Alpha server.
- Documentation accurately distinguishes implemented, simulated, unconfigured, and pending features.
- No claim of production readiness is made without a separate production-readiness review.
