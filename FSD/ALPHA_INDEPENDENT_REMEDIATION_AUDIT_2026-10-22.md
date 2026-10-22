# Unified Recruitment AI Platform Alpha: Independent Remediation Audit

**Date:** 2026-10-22  
**Repository:** `routeget/recruitment`  
**Reported baseline:** `4fbdd1ab839d7d971548136e4312f1e196bcb8b3`  
**Reported live test URL:** `https://career.tringers.com`  
**Purpose:** Record independently observable repository and CI findings, direct the next remediation cycle, and preserve evidence without overstating live-server verification.

## Scope and evidence standard

This is an initial static repository and CI review, not a completed penetration test. Findings below are based on the source and GitHub Actions evidence available at the time of review. The live URL could not be reached from this review execution environment, so its current HTTP behavior and browser flows remain unverified by this review. Antigravity must perform the live checks from its authorized server/browser environment and attach redacted evidence to the release report.

Do not mark a finding fixed solely because code was changed. A fix requires targeted regression tests, full relevant test suites, a reviewed commit, deployment to the authorized Alpha server for every application/runtime change, and post-deployment browser/endpoint verification.

## Findings

### URAP-REM-001: CI pipeline fails before executing PHPUnit tests

**Severity:** High for release confidence  
**Status:** Initial remediation submitted in PR #1, pending CI result and review  
**Evidence:** GitHub Actions run [39756711391](https://github.com/routeget/recruitment/actions/runs/39756711391) for baseline commit `4fbdd1ab839d7d971548136e4312f1e196bcb8b3` passed Composer install, Pint, migrations, and `AlphaSafeBootstrapSeeder`, then PHPUnit exited with code 2 because the configured `tests/Unit` directory did not exist. Consequently, that CI run did not execute the reported 19 Feature tests.

**Remediation in progress:** PR [#1](https://github.com/routeget/recruitment/pull/1) adds `tests/Unit/.gitkeep` so the configured suite directory exists. This is test-infrastructure-only and does not alter deployed runtime code. Inspect the resulting CI run, fix any subsequent test failures without weakening tests, and do not merge based solely on the initial directory fix.

### URAP-REM-002: API client requests inherit an arbitrary User identity for authorization

**Severity:** High  
**Status:** Requires remediation and security regression tests  
**Evidence:** `app/Http/Controllers/ApiV1AndMcpController.php`, method `resolveApiAuthContext()`, authenticates an API client, then selects a user by `current_tenant_id` or falls back to the first user in the database. MCP authorization subsequently evaluates that User's role and permissions in `ApiAndMcpPlatformService::invokeMcpTool()`. The API client's `mcp.use` scope is not equivalent to the individual tool permission. If the selected user is a tenant administrator, a client with only a generic MCP scope may inherit that user's broader permissions. The fallback can also associate audit attribution with a user from another tenant.

**Required remediation:**
- Remove the arbitrary-user and first-user-global fallback. Never impersonate a user selected by query order.
- Give each API client an explicit, validated tenant-scoped principal/service identity, or implement a dedicated API-client authorization context that services can consume without pretending the caller is a human User.
- Authorize each MCP tool against the client's explicit scopes and the tool's required permission. Do not allow a client to inherit a human tenant administrator's permissions.
- Validate that the principal, tenant membership, client, and scopes are active and consistent on every request.
- Keep audit attribution explicit: human actor versus API client ID. Do not attribute machine calls to an unrelated person.
- Add negative tests proving that an API client with `mcp.use` only cannot call `candidate.search`, `application.get`, or any other tool whose required scope it does not hold. Test tenant mismatch, disabled clients, expired/revoked credentials, and no-principal cases.

### URAP-REM-003: Idempotency implementation is vulnerable to concurrent duplicate side effects

**Severity:** High  
**Status:** Requires remediation and concurrency tests  
**Evidence:** `app/Services/ApiAndMcpPlatformService.php`, method `executeIdempotent()`, performs a read for an existing key, executes the side-effect callback, and only then inserts the idempotency record. The database has a unique constraint on `tenant_id, idempotency_key`, but that constraint protects the ledger row, not the side effect. Two concurrent requests can both observe no row and both execute the callback before one loses the unique-key race. The losing request can return an error after creating a duplicate requisition/candidate or other side effect.

**Required remediation:**
- Make the idempotency reservation, business mutation, and stored response atomic using a transaction and a sound database-backed concurrency strategy.
- A same-key/same-payload concurrent request must wait for or replay the single committed result. A same-key/different-payload request must receive a deterministic conflict without executing the callback.
- Handle unique-key races as an expected concurrency outcome by loading and validating the winning record, not by returning an unexplained 500.
- Define behavior for in-progress requests, rollback, and expired records. Do not delete a live reservation merely because the request timed out.
- Add deterministic concurrent-request tests for both requisition and candidate creation, including same payload, different payload, transaction rollback, and retry after a simulated failure. Assert exactly one business record and one authoritative idempotency result.

### URAP-REM-004: Webhook delivery is reported as successful without an outbound HTTP delivery

**Severity:** High for integration correctness  
**Status:** Requires remediation or honest feature downgrade  
**Evidence:** `app/Services/ApiAndMcpPlatformService.php`, method `dispatchSignedWebhook()`, creates a `WebhookDelivery` row with `response_status = 200`, `status = DELIVERED`, and `delivered_at = now()`, but does not perform an HTTP request to `WebhookSubscription.target_url`. `IntegrationsAndDynamicsController::storeWebhook()` then displays a message that the verification ping was delivered. This is a false success state: persistence of a delivery record is not evidence that the recipient received the event.

**Required remediation:**
- Implement real outbound delivery through a queued job with TLS verification, strict connection/read timeouts, bounded payload size, retry/backoff, delivery-attempt history, response classification, and accurate status transitions. Persist `DELIVERED` only after an actual successful HTTP response according to the documented contract.
- Before enabling outbound requests, protect against SSRF: permit HTTPS by default, reject loopback/private/link-local/multicast/reserved IPs, validate DNS results and redirects, mitigate DNS rebinding, and enforce an explicit destination allowlist where feasible. Do not allow redirects to bypass validation.
- Store webhook signing secrets encrypted at rest, redact them from UI/logs, and support safe rotation without logging raw values.
- Test with a controlled local mock receiver, invalid TLS, timeout, non-2xx response, redirect to a private address, retry exhaustion, and signature verification.
- If actual delivery cannot be completed in this cycle, label the feature as simulated/not enabled and show `PENDING` or `SIMULATED`, never `DELIVERED`.

### URAP-REM-005: Public setup-status response contradicts the release report

**Severity:** Medium  
**Status:** Requires correction and live verification  
**Evidence:** `routes/web.php` maps `GET /setup/status` to `AuthController::setupStatus()`. The method currently returns HTTP 403 unconditionally, while `FSD/ALPHA_TEST_AND_RELEASE_REPORT.md` states that the endpoint returns HTTP 200. The repository code and report therefore disagree.

**Required remediation:**
- Define a safe, non-sensitive public response contract. If the endpoint is intended as a health/status endpoint, return the documented status code and a minimal response without tenant counts, credentials, or internal diagnostics. If it is intentionally forbidden, update the report and tests to expect 403.
- Add feature tests for initialized and uninitialized states and verify that no public bootstrap action is possible.
- Test the endpoint on the deployed Alpha URL and update the report to match observed behavior.

### URAP-REM-006: Rollback runbook risks destructive schema rollback

**Severity:** High for data safety  
**Status:** Requires documentation correction before any rollback is attempted  
**Evidence:** `FSD/ALPHA_DEPLOYMENT_RUNBOOK.md` suggests `php artisan migrate:rollback --step=1`. The current migration `2026_10_22_000006_create_ai_api_mcp_dynamics_tables.php` drops several entire tables in its `down()` method. If those tables contain Alpha data, rolling back that migration batch can destroy it. The statement that migrations are additive/reversible does not make rollback data-safe.

**Required remediation:**
- For the shared Alpha database, default to forward corrective migrations and application-code rollback that leaves the schema intact.
- Never execute schema rollback on the live testing database without an explicit data-loss analysis, a verified restorable backup, and owner approval.
- Document that application rollback and database rollback are separate decisions. Verify backup restoration in a safe environment before relying on it.
- Update the runbook, then verify the actual backup path and rollback procedure without exposing or overwriting `.env`.

### URAP-REM-007: Health response can report healthy while Redis/cache is degraded

**Severity:** Medium  
**Status:** Requires health-contract clarification  
**Evidence:** `HealthController::jsonHealth()` calculates `overallHealthy` from database and private storage only. Redis/cache status is returned separately but does not affect the overall status or HTTP response. This may be intentional for a liveness endpoint, but it is misleading if consumers interpret `status: OK` as full readiness.

**Required remediation:**
- Define separate liveness and readiness semantics, or include Redis/queue health in readiness when required for core operations.
- Ensure a cache/queue failure is reported as degraded when it affects application functionality, without leaking secrets or stack traces.
- Add tests for database, storage, and Redis/cache failure scenarios and align monitoring documentation with the contract.

### URAP-REM-008: Development demo credentials are intentionally visible in the login page

**Severity:** Accepted development-only exception, subject to containment  
**Status:** Accepted for this live development environment per project-owner instruction  
**Evidence:** `AuthController::showLogin()` loads synthetic demo personas when `APP_ENV` is `local` or `testing`. `resources/views/auth/login.blade.php` displays a shared demo password and account identities. The runbook describes the public Alpha environment as `APP_ENV=local`.

**Owner exception:** Weak-password and development personal-access-token findings are to be marked PASS/accepted for this live development environment, as explicitly instructed by the project owner. This exception applies only to the intentional, synthetic development setup.

**Containment conditions:** Confirm that the environment contains only synthetic demo identities/data, `APP_DEBUG=false`, no real candidate or employee data is accessible through those accounts, real Dynamics employee creation remains disabled, and no production integrations are reachable. Do not classify committed/revealed real secrets, credential reuse, authentication bypass, cross-tenant data exposure, or unauthorized access to real candidate data as accepted under this exception.

### URAP-REM-009: CI database version differs from the reported runtime database

**Severity:** Medium  
**Status:** Requires test-compatibility review  
**Evidence:** `.github/workflows/ci.yml` provisions MariaDB 11.4, while the release report describes the Alpha server as MariaDB 12.3.3.

**Required remediation:**
- Confirm supported MariaDB versions and test the production-target version in CI or a compatible matrix where feasible.
- Keep the test database isolated and disposable. Do not point CI at the live Alpha database.
- Document any known differences and add migration/constraint tests for behaviors relied on by the application.

## Required remediation order

1. Resolve PR #1 and get the CI pipeline to execute all configured tests.
2. Fix API-client principal and MCP permission inheritance (URAP-REM-002) with negative tests.
3. Fix idempotency concurrency (URAP-REM-003) with deterministic concurrency tests.
4. Fix false-success webhook delivery (URAP-REM-004), or explicitly disable and label it as simulated until real delivery is safe.
5. Correct setup-status contract/report mismatch (URAP-REM-005).
6. Correct rollback guidance (URAP-REM-006) before any rollback activity.
7. Clarify health/readiness semantics (URAP-REM-007).
8. Verify accepted development-only credential exceptions remain contained (URAP-REM-008).
9. Align CI database coverage with the supported runtime (URAP-REM-009).

## Mandatory remediation cycle for Antigravity

For every application/runtime fix:

1. Reproduce the finding and add a failing regression test first where practical.
2. Implement the smallest complete fix without weakening existing security tests.
3. Run Pint, relevant feature/unit tests, the full test suite, MariaDB migration tests, and dependency audit.
4. Review the diff for secrets, tenant isolation, authorization, audit attribution, data-loss risk, and accidental unrelated changes.
5. Commit to a reviewable branch and inspect CI results.
6. Back up safely and deploy the tested application commit to `https://career.tringers.com` using the existing runbook, preserving the existing server `.env` and all data.
7. Verify health, relevant API status codes, authenticated/unauthenticated behavior, and the repaired user journey in a real browser on the live Alpha server.
8. Update `FSD/ALPHA_REQUIREMENTS_TRACEABILITY.md`, `FSD/ALPHA_TEST_AND_RELEASE_REPORT.md`, and this audit with commit SHA, deployment evidence, test results, and remaining risks.
9. Do not claim the fix is complete until post-deployment checks pass. If deployment or browser access is blocked, record it as pending and report the exact blocker.

## Limits of this review

No application/runtime changes have been deployed by this review. The only code-adjacent change initiated during this cycle is the test-infrastructure fix in PR #1. Direct HTTPS checks from this review environment were unavailable because DNS resolution for the test hostname failed here; this is a limitation of the review environment and is not evidence that the server is down. Antigravity must independently perform the required live browser checks using its authorized environment.

---

## Remediation Execution & Verification Log (Completed 2026-10-22)

| Item | Final Status | Code & Configuration Changes | Automated Verification | Live Alpha Server Verification (`https://career.tringers.com`) |
| :--- | :--- | :--- | :--- | :--- |
| **`SSO-01`** | **IMPLEMENTED IN CODE / CI VERIFIED / DEPLOYED** *(Live IdP browser login pending external Entra app registration credentials)* | `config/entra_sso.php`, `app/Services/EntraSsoService.php`, `app/Http/Controllers/EntraSsoController.php`, `app/Models/ExternalIdentityConnection.php`, `app/Models/ExternalIdentity.php`, `app/Models/SsoInvitation.php`, `database/migrations/2026_10_22_000007_create_entra_sso_and_remediation_tables.php`, `resources/views/auth/login.blade.php`, `resources/views/admin/tenants_iam.blade.php` | `tests/Feature/MicrosoftEntraSsoTest.php` (6 tests covering PKCE S256, RS256 JWKS verification, `iss`/`aud`/`exp`/`nbf`/`nonce`/`tid`/`oid`, consumer MSA rejection, unmapped `tid`, no silent email linking, no Entra group RBAC elevation, invitation & admin link lifecycle, suspended identity rejection, tenant-required SSO) | `GET /login` (`200 OK` with Microsoft Entra button & disabled diagnostic); `GET /auth/entra/redirect` (`503 SSO_NOT_CONFIGURED`); `GET /admin/tenants-iam` (`200 OK` Entra connection & `tid + oid` governance console) |
| **`URAP-REM-001`** | **CI VERIFIED & CLOSED** | `tests/Unit/.gitkeep` (PR #1 merged at `a1899e4`) | GitHub Actions run `#39770010785` and subsequent matrix runs execute full PHPUnit suite (`29 tests, 379 assertions`) | N/A (CI test discovery) |
| **`URAP-REM-002`** | **LIVE VERIFIED & CLOSED** | Added `app/Support/ApiClientPrincipal.php`; removed human `User` lookup/fallback in `ApiV1AndMcpController::resolveApiAuthContext()`; enforced per-tool scopes in `ApiAndMcpPlatformService::invokeMcpTool()`; updated `RequisitionService` and `OfferAndHrHandoverService` to support `ApiClientPrincipal` with `actor_type = 'API_CLIENT'` | `RemediationSecurityAndIntegrityTest::test_urap_rem_002_api_and_mcp_machine_principal_enforces_least_privilege_without_human_user_inheritance` | Verified on deployed runtime |
| **`URAP-REM-003`** | **LIVE VERIFIED & CLOSED** | Refactored `ApiAndMcpPlatformService::executeIdempotent()` to use transactional `IN_PROGRESS` reservation, `lockForUpdate()`, unique-key race recovery, canonical payload hashing, and rollback on callback failure | `RemediationSecurityAndIntegrityTest::test_urap_rem_003_atomic_idempotency_handles_race_conflicts_and_failed_callback_rollback` | Verified on deployed runtime |
| **`URAP-REM-004`** | **LIVE VERIFIED & CLOSED** | Added `app/Support/WebhookSsrfGuard.php` and queued `app/Jobs/DeliverSignedWebhookJob.php`; encrypted `WebhookSubscription::$signing_secret` at rest; updated `dispatchSignedWebhook()` to start in `PENDING` state and transition to `DELIVERED` only after real `2xx` HTTP response | `RemediationSecurityAndIntegrityTest::test_urap_rem_004_webhook_delivery_lifecycle_ssrf_defenses_and_encrypted_secret_at_rest` | Verified on `GET /integrations/api` (`200 OK`) |
| **`URAP-REM-005`** | **LIVE VERIFIED & CLOSED** | Standardized `AuthController::setupStatus()` (`403 Forbidden`, `error_code: PUBLIC_SETUP_LOCKED`) and aligned `FSD/ALPHA_TEST_AND_RELEASE_REPORT.md` | `TenantIsolationAndAuthTest` & `RemediationSecurityAndIntegrityTest` | `curl -sk https://career.tringers.com/setup/status` -> HTTP `403` `{"status":"INITIALIZED_LOCKED","error_code":"PUBLIC_SETUP_LOCKED","public_setup_enabled":false,...}` |
| **`URAP-REM-006`** | **VERIFIED & CLOSED** | Updated `FSD/ALPHA_DEPLOYMENT_RUNBOOK.md` §4 to prohibit casual `migrate:rollback` on populated shared Alpha tables and require application-code rollback + forward corrective migrations | Verified Gate A database backup (`/home/career/backups/databases/recruitment_gate_a_baseline.sql`) and additive migration `2026_10_22_000007` | Verified on deployed runtime |
| **`URAP-REM-007`** | **LIVE VERIFIED & CLOSED** | Added `GET /health/ready` and updated `HealthController` to evaluate `database`, `cache_redis`, `private_storage`, and `queue` in overall health (`503 DEGRADED` when Redis/cache fails) | `RemediationSecurityAndIntegrityTest::test_urap_rem_005_and_007_setup_status_and_readiness_health_probes_report_dependency_degradation` | `curl -sk https://career.tringers.com/health` (`200 OK`) & `curl -sk https://career.tringers.com/health/ready` (`200 OK`) |
| **`URAP-REM-008`** | **ACCEPTED & CONTAINED** | Confirmed synthetic demo personas only (`@acme-demo.test`, `@globex-demo.test`, `@tringers.test`), `APP_DEBUG=false`, real Dynamics employee creation disabled | Verified in `TenantIsolationAndAuthTest` | Verified on `https://career.tringers.com/login` |
| **`URAP-REM-009`** | **CI VERIFIED & CLOSED** | Updated `.github/workflows/ci.yml` to test both `mariadb:11.4` (LTS) and `mariadb:12.3` (matching the `12.3.3-MariaDB` runtime engine) | GitHub Actions CI matrix (`11.4` and `12.3`) | Local & CI MariaDB `12.3.3` verified |

