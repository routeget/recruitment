# Master Antigravity Prompt: Unified Recruitment AI Alpha Implementation and Remediation

**Repository:** `routeget/recruitment`  
**Base branch:** `main` — fetch latest before starting  
**Authorized Alpha environment:** https://career.tringers.com  
**Deployment path:** `/home/career/htdocs/career.tringers.com`  
**Mode:** Execute implementation, remediation, testing, deployment, and evidence collection—not documentation-only work.  
**Date:** 2026-10-22

---

## 0. Mission and operating contract

Act as the senior Laravel engineer, application security engineer, QA lead, and release engineer responsible for finishing the Unified Recruitment AI Platform Alpha.

Your job is to implement the mandatory Microsoft Entra ID enterprise SSO requirement and remediate the independently identified application defects. Work directly in the repository, write production-quality code, create regression tests, run the checks, prepare reviewable commits/PRs, and deploy every approved application/runtime fix to the authorized Alpha server using the existing deployment runbook.

**Do not stop after writing a plan, specification, mock-up, or passing unit tests.** Continue through implementation and verification wherever credentials and authorized access exist. If a capability is blocked, finish all safe work possible, record the exact blocker and evidence, and continue with independent work that is not blocked.

### Non-negotiable truthfulness

- Never report a feature as implemented because a specification or configuration placeholder exists.
- Never report a test, browser journey, deployment, backup, migration, or live check as completed unless you actually performed it and captured evidence.
- Distinguish: `NOT STARTED`, `IN PROGRESS`, `IMPLEMENTED IN CODE`, `CI VERIFIED`, `DEPLOYMENT PENDING`, `DEPLOYED`, and `LIVE VERIFIED`.
- A code fix is not release-complete until the tested commit is deployed and the affected live journey is verified.
- Do not simulate successful Entra login or webhook delivery to make a screen appear functional.
- If you cannot access the host, browser, Entra app registration, or test tenant, state the exact missing access/credential. Do not invent secrets or bypass the requirement.

## 1. Read before changing anything

Fetch current `main`; inspect open PRs, branch status, recent CI results, and the deployed commit. Then read:

1. `FSD/UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md`
2. `FSD/MICROSOFT_ENTRA_ID_ENTERPRISE_SSO_LEVEL_2_SPECIFICATION.md`
3. `FSD/ANTIGRAVITY_ENTRA_SSO_AND_ALPHA_REMEDIATION_DIRECTIVE.md`
4. `FSD/ALPHA_INDEPENDENT_REMEDIATION_AUDIT_2026-10-22.md`
5. `FSD/ALPHA_REQUIREMENTS_TRACEABILITY.md`
6. `FSD/ALPHA_TEST_AND_RELEASE_REPORT.md`
7. `FSD/ALPHA_DEPLOYMENT_RUNBOOK.md`
8. Relevant controllers, services, models, migrations, routes, middleware, views, factories/seeders, tests, and `.github/workflows/ci.yml`.

Do not assume earlier documentation accurately reflects current code or live behavior. Confirm it. Preserve the current `.env`, private uploads, candidate data, tenant records, and audit records. Never copy production secrets into GitHub, test output, prompts, screenshots, or logs.

## 2. Execution sequence and gates

Run these workstreams in order, but do independent safe tasks in parallel only when that does not create conflicting migrations or branches.

### Gate A — Establish a reliable baseline

- Record current `main` SHA, deployed SHA, PHP/Laravel/MariaDB/Redis versions, migration status, queue configuration, and CI result.
- Confirm the documented backup procedure and the last successful backup; verify a restore in a safe non-live environment if possible.
- Run the existing CI and full tests without changing assertions. Record pre-existing failures.
- Inspect the deployment runbook against the actual host process, release directories, service manager, queue workers, and cache configuration.
- Verify the deployed application is using synthetic development data only. The owner-approved weak-password/demo-token exception applies only to intentionally synthetic development setup. It never accepts real secrets, auth bypass, privilege escalation, cross-tenant access, or exposure of real candidate/employee information.

**Gate A exit:** reproducible baseline and explicit list of existing failures. No live data changes.

### Gate B — Priority 0: Microsoft Entra ID enterprise SSO (SSO-01)

Implement the full specification in `FSD/MICROSOFT_ENTRA_ID_ENTERPRISE_SSO_LEVEL_2_SPECIFICATION.md`. Do not downgrade this to documentation-only.

#### Required implementation

- Microsoft Entra ID OIDC, authorization-code flow, PKCE S256, state, nonce, trusted discovery metadata, signing-key/signature validation, and issuer/audience/expiry/not-before checks.
- Default audience: **organizational Microsoft work/school accounts across Entra directories only**. Do not enable personal Microsoft consumer accounts unless the owner explicitly changes the requirement.
- Exact HTTPS callback URI and strict validation of tenant ID (`tid`), issuer, and approved Entra connection. Never accept arbitrary tenant/authority URLs from a user-supplied parameter.
- Durable external identity mapping keyed by validated stable identifiers (for example, issuer/tenant ID plus object ID/subject). Email, UPN, display name, and domain are attributes, not identity keys.
- No silent account linking based on matching email. Require an explicit secure linking flow or admin-approved process.
- Authentication does not automatically grant app tenant membership, roles, access to candidate data, or platform administration. Use invitation/approved membership and existing RBAC/ABAC.
- No privileged role mapping from raw Entra groups or directory roles. Any future mapping must be explicit, admin-controlled, validated, audited, and tested.
- Secure session rotation on login, logout/session invalidation, CSRF protection, throttling, safe error messages, security audit events, and tenant-aware account deactivation.
- Client secrets/certificates only in deployment environment or a proper secret manager. Never persist plaintext secrets in MariaDB, source, logs, HTML, client-side JavaScript, or release artifacts.
- Do not request Microsoft Graph permissions unless separately justified by an approved requirement. OIDC sign-in does not imply Graph access, group synchronization, or SCIM provisioning.
- Feature-flag SSO **off** until the real Entra app registration, exact redirect URI, client ID, secret/certificate, and approved test tenant are configured. Missing configuration must fail closed with a safe operator diagnostic, not a fake login.
- Define recovery/break-glass behavior. A tenant configured to require SSO must not be bypassable by an ordinary local-password login. Preserve a separately secured recovery procedure.
- Additive migrations only. Inspect the current schema and data before writing migrations; do not reset or truncate any database.

#### Mandatory SSO tests

Test successful callback handling with mocked OIDC responses and negative cases for invalid/missing state, nonce, PKCE verifier, signature, issuer, audience, expiry, not-before, tenant ID, replay, unapproved tenant, disabled connection/user, missing invitation, duplicate identity mapping, email-based account-linking attack, role escalation, tenant crossover, session fixation, logout, and secret/token leakage.

Use a dedicated non-production Entra app and test tenant for end-to-end validation. If credentials or app registration are unavailable, complete safe code and automated tests but leave SSO disabled and report live validation as blocked. Never claim SSO is complete until a real browser flow is verified.

**Gate B exit:** implementation and tests pass; config is safe by default; live Entra sign-in verified or exact external blocker recorded.

### Gate C — URAP-REM-002: Remove API/MCP human-user privilege inheritance (High)

Inspect `ApiV1AndMcpController::resolveApiAuthContext()`, `ApiAndMcpPlatformService`, `ApiClient`, and every API/MCP authorization call.

- Remove arbitrary user lookup and any global “first user” fallback.
- Never impersonate a human user for a machine client.
- Introduce an explicit tenant-bound service principal/authorization context, or an equivalent design that carries API client identity, tenant, and explicit granted scopes without fabricating a human identity.
- Enforce each tool’s own scope/permission on every invocation. Generic `mcp.use` must not grant access to candidate search, applications, HR data, or other privileged tools.
- Validate active/revoked status, tenant consistency, allowed scopes, and resource tenant on every request.
- Audit records must distinguish the human actor from the API client/service principal. Do not attribute a machine request to an unrelated tenant user.
- Preserve the existing prohibited-tool denylist; do not enable raw SQL, shell, filesystem, secrets, or unapproved HR employee creation.

**Regression tests:** `mcp.use`-only client denied for `candidate.search`, `application.get`, and every tool requiring a different scope; correct least-privilege client allowed; tenant mismatch denied; disabled/revoked/unknown client denied; no human user exists; no cross-tenant data returned; audit actor is the service principal/client.

**Gate C exit:** tests prove no inherited human privileges and no cross-tenant access.

### Gate D — URAP-REM-003: Make idempotency concurrency-safe (High)

Inspect `ApiAndMcpPlatformService::executeIdempotent()`, relevant tables/indexes, and all side-effecting callers.

- Fix the read → execute side effect → insert sequence that permits concurrent duplicate mutations.
- Use a database-backed reservation/locking/unique-key strategy with a clear transaction boundary. The business mutation and authoritative idempotency outcome must commit atomically where they share the database.
- Same tenant + same key + same canonical payload must produce one business side effect and replay the committed result.
- Same tenant + same key + different payload must deterministically return `409 IDEMPOTENCY_CONFLICT` without executing another side effect.
- Handle concurrent unique-key races by loading and validating the winner. Do not return an unexplained 500.
- Define pending/in-progress behavior, failed transaction cleanup, retry behavior, payload hashing/canonicalization, and record retention. Never remove a live reservation just because a caller timed out.
- Keep tenant scope in the idempotency uniqueness boundary.

**Regression tests:** deterministic concurrency tests using the actual supported database engine, same-payload race, different-payload race, rollback after reservation, retry after exception, parallel candidate/requisition creation, and exactly-one durable side effect. Do not rely only on sequential tests or SQLite behavior if the live engine is MariaDB.

**Gate D exit:** demonstrated exactly-once business effect for the supported idempotency contract.

### Gate E — URAP-REM-004: Real or explicitly disabled webhook delivery (High)

Inspect `dispatchSignedWebhook()`, `WebhookSubscription`, `WebhookDelivery`, queue configuration, and the UI’s verification-ping response.

- Never mark a delivery `DELIVERED`, set HTTP 200, or populate `delivered_at` without an actual successful outbound HTTP response.
- Implement queued outbound delivery with TLS certificate verification, strict connect/read timeouts, payload limits, bounded retries/backoff, response classification, attempt history, and auditable status transitions.
- Add SSRF protections: HTTPS by default; reject loopback, private, link-local, multicast, reserved and metadata-service addresses; validate DNS answers at connection time; mitigate DNS rebinding; validate redirects at every hop or disable redirects; enforce destination allowlists where practical. Prevent redirect or DNS tricks from bypassing the policy.
- Sign the exact transmitted payload with a documented HMAC scheme and stable timestamp/replay window. Never log the signing secret or authorization headers.
- Encrypt webhook secrets at rest using the app’s configured encryption facility; redact in UI and logs; support safe rotation.
- If secure real delivery cannot be finished, disable outbound sending and clearly label the feature `NOT CONFIGURED` or `SIMULATED`; use `PENDING`/failure status and never show “delivered.”

**Regression tests:** controlled mock receiver; successful 2xx; non-2xx; timeout; invalid TLS; exhausted retries; bad signature; private/loopback target; DNS change/rebinding; redirect to private IP; payload/timeout limits; secret redaction. Tests must not send requests to arbitrary public endpoints.

**Gate E exit:** delivery status reflects real outcomes or feature is safely disabled and transparently labeled.

### Gate F — URAP-REM-005: Align `/setup/status` contract and evidence

- Inspect `AuthController::setupStatus()`, routes, middleware, and release report.
- Decide and document the safe contract: either a minimal public status response or intentional 403. Do not expose tenant counts, configuration secrets, stack traces, bootstrap controls, or sensitive diagnostics.
- Add tests for initialized/uninitialized application states and prove this route cannot trigger setup or bootstrap.
- Verify the same behavior against the deployed URL and align all documentation with observed results.

### Gate G — URAP-REM-006: Protect live data from rollback

- Correct `FSD/ALPHA_DEPLOYMENT_RUNBOOK.md` to prohibit casual `migrate:rollback` on the shared Alpha database when `down()` may drop populated tables.
- Prefer forward corrective migrations and application-code rollback without destructive schema changes.
- Before any schema change, inspect migration SQL and data impact, take a verified backup, and document the recovery procedure.
- Never run `migrate:fresh`, database reset, table truncation, or destructive rollback against Alpha.
- A destructive operation requires explicit owner approval after a data-loss analysis and verified restore path. Do not interpret general deployment approval as approval to destroy data.

### Gate H — URAP-REM-007: Truthful liveness and readiness

- Inspect `HealthController::jsonHealth()` and dependencies used by core workflows.
- Define liveness (process is running) separately from readiness (required dependencies available) when appropriate.
- Report DB, private storage, Redis/cache, and queue status accurately. A dependency may be non-critical only if the architecture explicitly supports graceful degradation.
- Use appropriate status codes and stable machine-readable fields. Do not expose secrets, internal paths, stack traces, or PII.
- Test dependency failures and verify the deployed health/readiness endpoints.

### Gate I — URAP-REM-009: CI/runtime database compatibility

- Confirm the actually supported MariaDB version from server evidence and project constraints.
- Align CI to test the deployed/supported version or a deliberate compatibility matrix. Keep CI databases isolated and disposable; never connect CI to the live Alpha database.
- Run migrations, indexes/constraints, transactions, idempotency/concurrency tests, and relevant feature tests on the supported engine.
- Document justified version differences and verify dependency/runtime requirements.

### Gate J — URAP-REM-008: Confirm development-only exception containment

- Confirm demo accounts and visible demo credentials use synthetic identities and data only.
- Confirm `APP_DEBUG=false`, no real candidate/employee records are exposed to demo personas, production integrations remain disabled, and no real secrets are committed or printed.
- Keep the explicitly accepted weak-password/development-token exception scoped to this synthetic development environment. Do not use it to waive any security issue involving real secrets, authorization bypass, privilege escalation, cross-tenant leakage, or real candidate data.
- Record evidence without including credentials or PII.

## 3. Required engineering standards

- Use the repository’s existing Laravel conventions, dependency constraints, formatting, and architecture. Avoid introducing unnecessary packages.
- Use explicit authorization policies/services; do not rely on UI hiding as access control.
- Validate inputs server-side, normalize tenant context centrally, and apply least privilege.
- Never trust tenant IDs, roles, identity claims, webhook URLs, or authorization scopes supplied by the browser.
- Protect candidate PII, consent records, documents, HR data, and audit history. Avoid logging request bodies that contain sensitive data.
- Use additive, reversible-in-practice migrations; review each migration’s `up()` and `down()` behavior against populated data.
- Add tests that fail on the defect before the fix where practical. Never weaken or delete assertions to obtain a green run.
- Do not bundle unrelated refactors with security fixes. One finding/workstream per reviewable PR is preferred.
- Never commit `.env`, Entra credentials, webhook secrets, bearer tokens, private keys, real candidate data, database dumps, or unredacted screenshots.

## 4. Per-workstream verification checklist

For each code change:

1. Reproduce the issue and capture a minimal redacted baseline.
2. Add a targeted regression test.
3. Implement the root-cause fix.
4. Run targeted PHPUnit tests.
5. Run the full PHPUnit suite against the supported MariaDB version.
6. Run Laravel Pint, static analysis if configured, Composer audit, migrations on a disposable database, and the full GitHub Actions workflow.
7. Review the diff for authorization, tenant isolation, PII/secrets, audit attribution, concurrency, SSRF, and data-loss risks.
8. Commit to a dedicated branch; open a PR with finding IDs, test evidence, migration impact, and rollback/recovery plan.
9. Wait for CI; inspect actual results and fix failures without weakening tests.
10. Before deployment, verify backup and the exact approved deployment procedure.
11. Deploy the exact reviewed/tested commit to `https://career.tringers.com`, preserving the existing `.env` and all data.
12. Verify deployed SHA, health/readiness, relevant HTTP codes, authorization-denial cases, and the end-to-end affected journey in a real browser.
13. Inspect logs for errors without exposing secrets/PII.
14. Update traceability, release report, and audit finding status with commit SHA, CI run, test results, migration status, backup evidence, deployment timestamp/SHA, browser evidence, and residual risk.

If the host is inaccessible, do not pretend deployment occurred. Mark the code remediation as pending deployment and provide the exact command/step/access needed to proceed. If Entra credentials are missing, do not block unrelated remediation.

## 5. Deployment and data-safety rules

- The only authorized Alpha target is `https://career.tringers.com` at `/home/career/htdocs/career.tringers.com`.
- Do not deploy to or infer an alternative host.
- Never overwrite or regenerate the server’s existing `.env`.
- Never reset/truncate Alpha data, delete candidate documents, or run destructive migration rollback.
- Use maintenance mode only when required by the existing runbook and ensure it is restored on failure using a trap/finally-style recovery procedure.
- Restart/reload only the services required by the release; verify queue workers and scheduled jobs if changed.
- After deployment, run smoke tests and at least one negative authorization test for security changes.
- If deployment tooling is unavailable, prepare the exact tested commit and a safe, explicit operator procedure; report that deployment is not yet complete.

## 6. Required documentation updates

Keep these records accurate and synchronized:

- `FSD/ALPHA_REQUIREMENTS_TRACEABILITY.md`: requirement/finding, code path, tests, CI evidence, live verification, and status.
- `FSD/ALPHA_TEST_AND_RELEASE_REPORT.md`: baseline, targeted/full test totals, runtime/database version, CI URL, deployed SHA, smoke/browser evidence, and residual risks.
- `FSD/ALPHA_INDEPENDENT_REMEDIATION_AUDIT_2026-10-22.md`: update each finding’s status only when its acceptance criteria are met.
- `FSD/ALPHA_DEPLOYMENT_RUNBOOK.md`: safe deploy, backup, restore, migration, and recovery steps.
- SSO spec/operations documentation: required configuration keys by name only, callback URI, tenant approval, rotation, diagnostics, recovery, and known limitations. Never include secret values.

Use a finding status vocabulary consistently:
- `OPEN`
- `IN PROGRESS`
- `IMPLEMENTED IN CODE`
- `CI VERIFIED`
- `DEPLOYMENT PENDING`
- `DEPLOYED — LIVE VERIFICATION PENDING`
- `VERIFIED IN ALPHA`
- `ACCEPTED EXCEPTION` (only for the explicitly approved synthetic development credential policy exception)

## 7. Final release acceptance criteria

Do not declare the remediation cycle complete until all applicable criteria are met:

- [ ] Entra SSO is implemented, securely configured, and real-browser-verified using an approved non-production Entra app/test tenant; or remains disabled with the precise external blocker recorded.
- [ ] API/MCP calls use explicit machine identity and least-privilege tool scopes, with negative authorization and tenant-isolation tests.
- [ ] Concurrent idempotent requests cannot create duplicate business side effects.
- [ ] Webhook state is based on real delivery outcomes, or the feature is disabled and clearly marked unconfigured/simulated.
- [ ] Setup-status contract and documentation agree.
- [ ] Rollback instructions cannot casually destroy Alpha data.
- [ ] Health/readiness accurately reflects required dependencies.
- [ ] CI tests the supported database behavior and never touches the live database.
- [ ] Development-only demo credentials are contained to synthetic data.
- [ ] Full CI, relevant security regression tests, and runtime-compatible database tests pass.
- [ ] Every application/runtime fix is deployed to the authorized Alpha environment and verified there.
- [ ] Traceability and release documentation are evidence-backed and contain no secrets or candidate PII.
- [ ] No production-readiness claim is made without a separate production-readiness review.

**Start now:** fetch the latest `main`, inventory current code/CI/deployment state, and begin Gate A. Then execute Gates B–J, keeping each finding independently testable and reviewable. Do not stop at this prompt.
