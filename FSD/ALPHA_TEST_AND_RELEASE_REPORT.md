# Unified Recruitment AI Platform — Alpha Test & Release Report

**Release Version:** `v0.1.0-alpha`  
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

...................                                               19 / 19 (100%)
OK (19 tests, 211 assertions)
```

### Breakdown by Test Suite

1. **`tests/Feature/TenantIsolationAndAuthTest.php` (6 tests)**:
   - Public `/health` and `/setup/status` operational verification.
   - Authentication, tenant context resolution, and dashboard rendering.
   - Cross-tenant isolation preventing Tenant A users from accessing Tenant B records (`403 TENANT_SCOPE_VIOLATION` + `AuditEvent`).
   - Least-privilege RBAC enforcement blocking recruiters from IAM administration.
   - Platform superadmin break-glass support context requiring explicit ticket number and reason.
   - Sensitive key scrubbing (`password`, `secret`, `token`, `ssn`) in `AuditService`.
2. **`tests/Feature/RequisitionLifecycleTest.php` (5 tests)**:
   - Organization setup (`LegalEntity`, `Department`, `Location`).
   - Workforce requisition creation, versioning (`RequisitionVersion`), and submission.
   - Self-approval prohibition (`SELF_APPROVAL_PROHIBITED`).
   - Material-change approval invalidation (`INVALIDATED_BY_MATERIAL_CHANGE`).
3. **`tests/Feature/CareerPortalAndApplicationLifecycleTest.php` (4 tests)**:
   - Job posting creation, approval, publication, Schema.org `JobPosting` JSON-LD, `/sitemap.xml`, and `/robots.txt`.
   - Public application submission with mandatory privacy consent (`consent_accepted`), candidate email deduplication, and private resume storage (`local` disk outside `public/`) with SHA-256 checksums.
   - Executable file upload rejection (`.php`, `.exe`, `.sh`).
   - Cross-tenant candidate document download blocking (`404`/`403`).
4. **`tests/Feature/ScreeningInterviewDecisionTest.php` (1 end-to-end lifecycle test)**:
   - Structured screening evaluation, technical assessment recording, interview scheduling, competency scorecard submission, hiring decision self-approval block, authorized hiring manager decision, and funnel analytics verification.
5. **`tests/Feature/HrHandoverSafetyGateTest.php` (1 comprehensive `HRH-01`..`HRH-08` suite)**:
   - Verifies all 8 mandatory HR handover safety gate rules (`HRH-01` through `HRH-08`), including `409 HR_HANDOVER_GATE_NOT_SATISFIED`, `403 Forbidden` for recruiters/hiring managers, material-change invalidation, idempotency replay protection, ambiguous timeout reconciliation (`409 HR_HANDOVER_RECONCILIATION_REQUIRED`), `ExternalEmployeeReference` persistence, and `AuditEvent` coverage.
6. **`tests/Feature/AiApiMcpDynamicsIntegrationTest.php` (2 comprehensive integration tests)**:
   - Provider-neutral AI gateway across `claude`, `gemini`, `openai`, and `deterministic_fake` adapters, PII/secret redaction (`[REDACTED_EMAIL]`, `[REDACTED_SSN]`, `[REDACTED_SECRET]`), prohibited task blocking (`AI_PROHIBITED_USE`), kill switch (`409 AI_KILL_SWITCH_ENGAGED`), budget cap (`429 AI_BUDGET_EXCEEDED`), and human review sign-off.
   - `/api/v1` scoped Bearer authentication, `Idempotency-Key` replay and `409 IDEMPOTENCY_CONFLICT` detection, HMAC-SHA256 signed webhooks, `/mcp/v1` allowlisted tool invocation and prohibited tool blocking (`422 MCP_TOOL_PROHIBITED`), and Dynamics 365 Finance HR connector dry-run & field-ownership matrix.

---

## 2. Static Analysis, Code Style & Dependency Security

- **Laravel Pint:** `PASS (105 files)`
- **Composer Security Audit (`composer audit`):** `No security vulnerability advisories found.`
- **Secret Hygiene:** `.env` permissions `600`, untracked by Git, zero secret values in repository or documentation.

---

## 3. Live HTTPS Browser & Endpoint Verification (`https://career.tringers.com`)

Verified directly against NGINX + PHP-FPM 8.5 over TLS 1.3:

| Endpoint / Screen | Auth Context | HTTP Status | Verification Notes |
| :--- | :--- | :--- | :--- |
| `GET /health` | Public | `200 OK` | Returns JSON health (`db: ok`, `cache: ok`, `storage: ok`) |
| `GET /setup/status` | Public | `200 OK` | Confirms bootstrap initialized; public setup disabled |
| `GET /login` | Guest | `200 OK` | Renders Bootstrap 5 sign-in page with CSRF token |
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
| `GET /integrations/api` | Tenant Admin | `200 OK` | API clients, idempotency ledger, signed webhooks, MCP catalog |
| `GET /integrations/dynamics` | Tenant Admin | `200 OK` | Dynamics 365 Finance HR connector & field ownership matrix |
| `GET /analytics` | Tenant Admin | `200 OK` | Live stage funnel, source effectiveness, handover health |
| `GET /admin/tenants-iam` | Tenant Admin | `200 OK` | Tenant memberships, RBAC roles, scoped support access |
| `GET /admin/audit-events` | Tenant Admin | `200 OK` | Append-only security & compliance audit trail |
| `GET /system/diagnostics` | Tenant Admin | `200 OK` | Runtime PHP/MariaDB/Redis/Queue/Storage diagnostics |
| `GET /mcp/v1/tools` | Authenticated / Bearer | `200 OK` | Allowlisted MCP tool definitions |
| `GET /api/v1/requisitions` | Authenticated / Bearer | `200 OK` | Tenant-scoped JSON envelope with correlation ID |

---

## 4. Explicit Feature Classification (No Fake Completion)

- **Implemented & Verified Live:**
  - Multi-tenant IAM, RBAC/ABAC, break-glass support access, append-only audit trail.
  - Organization setup (`LegalEntity`, `Department`, `Location`) and Workforce Requisitions (`Requisition`, `RequisitionVersion`, `RequisitionApproval`).
  - Job descriptions, versioning, public Career Portal (`/careers`), Schema.org `JobPosting` JSON-LD, `/sitemap.xml`, `/robots.txt`.
  - Candidate CRM, consent enforcement, email deduplication, private document storage (`storage/app/private/`) with SHA-256 checksums, application stage pipeline.
  - Screening evaluations, assessments, interviews, scorecards, hiring decisions, and live analytics.
  - Offer lifecycle (`OfferTemplate`, `Offer`, `OfferVersion`) and Mandatory HR Validation & Handover Safety Gate (`HRH-01`..`HRH-08`) with `TransactionalOutboxEvent` and `ExternalEmployeeReference`.
  - Provider-neutral AI Gateway (`claude`, `gemini`, `openai`, `deterministic_fake` adapters, prompt registry, PII redaction, kill-switches, monthly budgets, schema validation, human review).
  - REST API `/api/v1` with `Idempotency-Key` conflict detection, HMAC-SHA256 signed webhooks, and governed MCP Server `/mcp/v1`.
  - Microsoft Dynamics 365 Finance HR connector UI, field-ownership matrix, dry-run validation, and deterministic Alpha sandbox handover.
- **Disabled / Pending External Credentials by Safe Default:**
  - Live external OAuth calls to a production Microsoft Dynamics 365 Finance HR tenant (`real_employee_creation_enabled = false` by default; fails closed if enabled without external OAuth credentials).
  - Live external billing API calls to Anthropic/Google/OpenAI when external API keys are not supplied in `.env` (uses governed deterministic structured JSON adapter by default so no candidate data leaves the server without explicit key configuration).
