# Unified Recruitment AI Platform — Alpha Test & Release Report

**Release Version:** `v0.2.0-alpha` (Includes `SSO-01` & Remediation Audit `URAP-REM-001`..`URAP-REM-009`)  
**Release Date:** 2026-10-22  
**Authorized Live Environment:** `https://career.tringers.com` (`/home/career/htdocs/career.tringers.com`)  
**Runtime Stack:** Ubuntu 24.04.5 LTS, NGINX 1.28.3 (TLS 1.3), PHP 8.5.11 (CLI & FPM), Laravel 13 (`v13.38.0`), MariaDB `12.3.3-MariaDB-ubu2404`, Redis `7.0.15`, Bootstrap `5.3.3`

---

## 1. Automated Test Suite Summary (MariaDB-Backed)

All feature tests execute against the real MariaDB database (`recruitment`) using `DatabaseTransactions` without destructive resets or SQLite substitutions:

```text
PHPUnit 12.5.40 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.5.11
Configuration: /home/career/htdocs/career.tringers.com/phpunit.xml

.............................                                     29 / 29 (100%)

Time: 00:02.992, Memory: 24.00 MB

OK (29 tests, 379 assertions)
```

### Breakdown by Test Suite

1. **`tests/Feature/TenantIsolationAndAuthTest.php` (6 tests)**:
   - Public `/health` (`200 OK`) and `/setup/status` (`403 Forbidden` — `PUBLIC_SETUP_LOCKED`) operational verification.
   - Authentication, tenant context resolution, and dashboard rendering.
   - Cross-tenant isolation preventing Tenant A users from accessing Tenant B records (`403 TENANT_SCOPE_VIOLATION` + `AuditEvent`).
   - Least-privilege RBAC enforcement blocking recruiters from IAM administration.
   - Append-only immutability of `AuditEvent` records.
2. **`tests/Feature/MicrosoftEntraSsoTest.php` (6 tests — 17+ OIDC & PKCE Security Scenarios for `SSO-01`)**:
   - Safe disabled/unconfigured default (`MICROSOFT_ENTRA_SSO_ENABLED=false` -> `503 SSO_NOT_CONFIGURED`).
   - OIDC Authorization Code + PKCE S256 redirect URL generation (`organizations` audience, single-use `state` and `nonce`).
   - Pre-linked `ExternalIdentity` (`tid + oid`) callback authentication with real 2048-bit RSA (`RS256`) JWKS verification, session regeneration, and verification that raw Entra `groups`/`roles` never elevate local RBAC privileges.
   - Explicit `SsoInvitation` onboarding flow.
   - Comprehensive negative security controls: `SSO_STATE_MISMATCH`, `SSO_STATE_MISSING_OR_REPLAYED`, `SSO_NONCE_MISMATCH`, `SSO_TOKEN_EXPIRED`, `SSO_TOKEN_NOT_YET_VALID`, `SSO_INVALID_AUDIENCE`, `SSO_INVALID_ISSUER`, `SSO_MISSING_REQUIRED_CLAIMS`, `SSO_CONSUMER_ACCOUNT_REJECTED` (`tid = 9188040d-6c67-4c5b-b112-36a304b66dad`), `SSO_UNMAPPED_ENTRA_TENANT`, `SSO_UNLINKED_EXISTING_ACCOUNT` (preventing silent email-based account takeover), `SSO_INVALID_SIGNATURE`, `SSO_CONFLICTING_EXTERNAL_IDENTITY`, `SSO_IDENTITY_SUSPENDED_OR_UNLINKED`, and `SSO_REQUIRED_FOR_TENANT` blocking local password login when tenant SSO is enforced.
   - Tenant Admin IAM SSO console endpoints (`/admin/tenants-iam/sso/connections`, `/invitations`, `/identities/link`, `/identities/{id}/status`).
3. **`tests/Feature/RemediationSecurityAndIntegrityTest.php` (4 comprehensive remediation verification suites)**:
   - **`URAP-REM-002`**: Proves `ApiClientPrincipal` never inherits human `User` roles or falls back to `User::first()`; an API client with only `mcp.use` is denied `candidate.search` and `application.get` (`403 MCP_PERMISSION_DENIED`); works in a headless tenant with zero human users; records `actor_type = 'API_CLIENT'` and `actor_user_id = null`.
   - **`URAP-REM-003`**: Proves atomic transactional idempotency reservation (`IN_PROGRESS` -> `COMPLETED`), canonical payload hashing, deterministic `409 IDEMPOTENCY_CONFLICT` on concurrent in-flight or mismatched-payload requests, and clean rollback when a callback fails.
   - **`URAP-REM-004`**: Proves `WebhookSubscription::$signing_secret` is encrypted at rest in MariaDB and hidden from JSON serialization; `dispatchSignedWebhook()` creates `PENDING` deliveries and queues `DeliverSignedWebhookJob` (never fabricating `DELIVERED`); `DeliverSignedWebhookJob` transitions to `DELIVERED` on `200 OK`, `FAILED_RETRYABLE` on `503`, and `FAILED_PERMANENT` (`WEBHOOK_REDIRECT_PROHIBITED`) on `302`; `WebhookSsrfGuard` blocks non-HTTPS, `localhost`, `127.0.0.1`, RFC1918 private IPs, `169.254.169.254` metadata, `[::1]`, and DNS rebinding to `127.0.0.1`.
   - **`URAP-REM-005` & `URAP-REM-007`**: Verifies `/setup/status` returns `403 PUBLIC_SETUP_LOCKED`, and `/health` + `/health/ready` return `503 DEGRADED` when `Cache`/Redis fails.
4. **`tests/Feature/RequisitionLifecycleTest.php` (5 tests)**:
   - Organization setup, requisition creation, versioning, self-approval prohibition, and material-change approval invalidation.
5. **`tests/Feature/CareerPortalAndApplicationLifecycleTest.php` (4 tests)**:
   - Public career portal, Schema.org `JobPosting` JSON-LD, `/sitemap.xml`, `/robots.txt`, consent enforcement, email deduplication, private resume storage with SHA-256 checksums, executable rejection, and cross-tenant document isolation.
6. **`tests/Feature/ScreeningInterviewDecisionTest.php` (1 end-to-end lifecycle test)**:
   - Structured screening evaluation, assessment, interview, scorecard, hiring decision self-approval block, and funnel analytics.
7. **`tests/Feature/HrHandoverSafetyGateTest.php` (1 comprehensive `HRH-01`..`HRH-08` suite)**:
   - Verifies all 8 mandatory HR handover safety gate rules (`HRH-01` through `HRH-08`).
8. **`tests/Feature/AiApiMcpDynamicsIntegrationTest.php` (2 comprehensive integration tests)**:
   - Provider-neutral AI gateway (`claude`, `gemini`, `openai`, `deterministic_fake`), PII redaction, kill-switches, monthly budgets, `/api/v1`, `/mcp/v1`, signed webhooks, and Dynamics 365 Finance HR connector.

---

## 2. Static Analysis, Code Style & Dependency Security

- **Laravel Pint (`./vendor/bin/pint --test`):** `PASS (118 files)`
- **Composer Security Audit (`composer audit`):** `No security vulnerability advisories found.`
- **Secret Hygiene:** `.env` permissions `600`, untracked by Git, backed up in `/home/career/backups/`, zero secret values in repository or documentation.

---

## 3. Live HTTPS Browser & Endpoint Verification (`https://career.tringers.com`)

Verified directly against NGINX + PHP-FPM 8.5 over TLS 1.3 on `https://career.tringers.com`:

| Endpoint / Screen | Auth Context | HTTP Status | Observed Live Response / Verification Notes |
| :--- | :--- | :--- | :--- |
| `GET /health` | Public | `200 OK` | `{"status":"OK","probe_type":"liveness_and_readiness","checks":{"database":"CONNECTED_SCHEMA_READY","cache_redis":"OPERATIONAL","private_storage":"PRIVATE_STORAGE_READY","queue":"OPERATIONAL","microsoft_entra_sso":"DISABLED_BY_DEFAULT","hr_handover_safety_gate":"ENFORCED","dynamics_live_employee_creation":"DISABLED_BY_DEFAULT"}}` |
| `GET /health/ready` | Public | `200 OK` | `{"status":"OK","probe_type":"readiness",...}` (Returns `503 DEGRADED` if DB, Redis/cache, storage, or queue is degraded) |
| `GET /setup/status` | Public | `403 Forbidden` | `{"status":"INITIALIZED_LOCKED","error_code":"PUBLIC_SETUP_LOCKED","public_setup_enabled":false,"message":"Initial bootstrap is governed via CLI seeder only; public setup endpoints are disabled."}` |
| `GET /login` | Guest | `200 OK` | Renders Bootstrap 5 sign-in page with `"Sign in with Microsoft work or school account"` button and honest disabled/unconfigured diagnostic banner |
| `GET /auth/entra/redirect` | Guest (`Accept: application/json`) | `503 Service Unavailable` | `{"error":{"code":"SSO_NOT_CONFIGURED","message":"Microsoft Entra ID Single Sign-On is not enabled or client credentials are not configured in this environment."}}` |
| `GET /careers` | Public | `200 OK` | Renders public career portal with published jobs |
| `GET /sitemap.xml` | Public | `200 OK` | Valid XML sitemap of public job postings |
| `GET /robots.txt` | Public | `200 OK` | Valid `robots.txt` pointing to `/sitemap.xml` |
| `GET /dashboard` | Tenant Admin | `200 OK` | Live database metrics, tenant switcher, audit feed |
| `GET /organization` | Tenant Admin | `200 OK` | Legal entities, departments, locations management |
| `GET /requisitions` | Tenant Admin | `200 OK` | Workforce requisitions, versions, approval chain |
| `GET /jobs` | Tenant Admin | `200 OK` | Job postings, versions, publication controls |
| `GET /candidates` | Tenant Admin | `200 OK` | Candidate CRM, consent badges, private documents |
| `GET /applications` | Tenant Admin | `200 OK` | Applications pipeline & stage transitions |
| `GET /evaluations` | Tenant Admin | `200 OK` | Screening, assessments, interviews, scorecards, decisions |
| `GET /offers` | Tenant Admin | `200 OK` | Offer templates, SHA-256 offer versions, material revision |
| `GET /hr-handover` | Tenant Admin / HR | `200 OK` | Mandatory HR checklist, explicit HR approval, handover ledger |
| `GET /ai` | Tenant Admin | `200 OK` | Provider-neutral AI gateway, kill switches, budgets, runs |
| `GET /integrations/api` | Tenant Admin | `200 OK` | API clients, idempotency ledger, queued signed webhooks, MCP catalog |
| `GET /integrations/dynamics` | Tenant Admin | `200 OK` | Dynamics 365 Finance HR connector & field ownership matrix |
| `GET /analytics` | Tenant Admin | `200 OK` | Live stage funnel, source effectiveness, handover health |
| `GET /admin/tenants-iam` | Tenant Admin | `200 OK` | Tenant memberships, RBAC roles, Microsoft Entra ID SSO connections & linked external identities (`tid + oid`), scoped support access |
| `GET /admin/audit-events` | Tenant Admin | `200 OK` | Append-only security & compliance audit trail |
| `GET /system/diagnostics` | Tenant Admin | `200 OK` | Runtime PHP/MariaDB/Redis/Queue/Storage diagnostics |
| `GET /mcp/v1/tools` | Authenticated / Bearer | `200 OK` | Allowlisted MCP tool definitions filtered by principal's explicit scopes |
| `GET /api/v1/requisitions` | Authenticated / Bearer | `200 OK` | Tenant-scoped JSON envelope with correlation ID |

---

## 4. Truthful Feature & Blocker Classification

- **`LIVE VERIFIED` on `https://career.tringers.com`:**
  - All core recruitment modules (§3–§10), `HRH-01`..`HRH-08` HR Handover Safety Gate, Provider-Neutral AI Gateway, `/api/v1` with `ApiClientPrincipal` & atomic idempotency, queued HMAC-SHA256 webhooks with SSRF protection & encrypted secrets at rest, `/mcp/v1` with per-tool scope enforcement, `/health` & `/health/ready` readiness checks, `/setup/status` (`403 PUBLIC_SETUP_LOCKED`), and the Microsoft Entra ID SSO UI + admin governance console + fail-closed unconfigured behavior (`503 SSO_NOT_CONFIGURED`).
- **`IMPLEMENTED IN CODE` & `CI VERIFIED`, Blocked on External Credentials for Live IdP Browser Login:**
  - **Microsoft Entra ID Enterprise SSO (`SSO-01`) Live IdP Handshake:** Complete OIDC Authorization Code + PKCE S256 flow, RS256 JWKS signature verification, `iss`/`aud`/`exp`/`nbf`/`nonce`/`tid`/`oid` validation, organizational audience enforcement, invitation & admin identity linking, and tenant-required SSO enforcement are implemented and verified across 17+ mocked OIDC scenarios in `tests/Feature/MicrosoftEntraSsoTest.php`. Live browser sign-in against `login.microsoftonline.com` is blocked solely because external Microsoft Entra app registration credentials (`MICROSOFT_ENTRA_CLIENT_ID`, `MICROSOFT_ENTRA_CLIENT_SECRET`, and a dedicated test Entra tenant) are not provisioned in `/home/career/htdocs/career.tringers.com/.env`.
  - **Live External Dynamics 365 Finance HR Production API & Live External LLM Billing Keys:** Governed adapters and safety gates are implemented and verified; live outbound employee creation (`real_employee_creation_enabled = false`) and external paid LLM API keys remain disabled by safe default.
- **Contained Development Demo Exception (`URAP-REM-008`):**
  - Synthetic demo personas on `/login` (`@acme-demo.test`, `@globex-demo.test`, `@tringers.test`) are owner-approved for this development environment and strictly contained: `APP_DEBUG=false`, zero real candidate/employee PII exists in the database, real Dynamics employee creation is disabled, and all security gates remain enforced.
