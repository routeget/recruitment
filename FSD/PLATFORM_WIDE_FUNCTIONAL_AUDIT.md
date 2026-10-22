# Unified Recruitment AI Platform — Platform-Wide Functional & Security Audit Report

**Document ID:** `URAP-PWFA-001`  
**Version:** `1.0`  
**Date:** `2026-10-22`  
**Status:** `COMPLETED — ALL IDENTIFIED DEFECTS (D-01..D-08) REMEDIATED, REGRESSION-TESTED & LIVE-VERIFIED`  
**Repository:** `https://github.com/routeget/recruitment`  
**Baseline Commit:** `379b7b0bdd0febf3f777bcadc797cf8ce57efe87`  
**Live Environment:** `https://career.tringers.com` (`/home/career/htdocs/career.tringers.com`)

---

## 1. Executive Summary & Audit Scope

This report records the comprehensive, evidence-based functional and security audit of the Unified Recruitment AI Platform against its three authoritative specifications:
1. `FSD/UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md`
2. `FSD/MICROSOFT_ENTRA_ID_ENTERPRISE_SSO_LEVEL_2_SPECIFICATION.md`
3. `FSD/ANTIGRAVITY_PLATFORM_SUPERADMIN_AND_CONFIGURATION_CENTRE_DIRECTIVE.md`

### Key Outcomes
- **Every route (`92` web/API/MCP/CLI endpoints), controller (`16` controllers), service (`13` domain services), middleware (`4` security/tenant middlewares), job (`DeliverSignedWebhookJob`), and Blade view (`27` templates)** was inspected line-by-line against the specifications.
- **8 functional/security defects and specification gaps (`D-01` through `D-08`)** were identified, systematically remediated in code, and verified via dedicated automated feature tests in `tests/Feature/PlatformWideFunctionalAuditRemediationTest.php` (`8` test suites, `53` assertions).
- **Full MariaDB-backed PHPUnit test suite (`41` tests, `568` assertions)** passes at `100%` using `DatabaseTransactions`.
- **Laravel Pint (`129` files)** and **Composer Security Audit (`0` advisories)** pass cleanly.
- **Synthetic Development Identity Preservation:** Per explicit owner directive (`FSD/DEVELOPMENT_CONTINUATION_DIRECTIVE_STATUS.md`), existing synthetic development accounts (`id=1..8`, including `pl***@tringers.test`, `@acme-demo.test`, `@globex-demo.test`) remain intact and active for development/testing, clearly classified as `SYNTHETIC_SEEDED` (`active_real_superadmins: 0`, `active_synthetic_superadmins: 1`), with genuine owner provisioning deferred until designated production handover.

---

## 2. Module-by-Module Functional Workflow Audit

### 2.1 Authentication, Sessions & Microsoft Entra ID Enterprise SSO (`AuthController`, `EntraSsoController`, `EntraSsoService`)
- **Verified Working Workflows:**
  - Local email/password authentication (`/login`, `/logout`), session regeneration, tenant context initialization (`active_tenant_id`), and tenant switching (`POST /switch-tenant`).
  - Fail-closed tenant-required SSO check (`require_sso = true` and `allow_local_fallback = false` blocks local password login with `SSO_REQUIRED_FOR_TENANT`).
  - Microsoft Entra ID OIDC Authorization Code + PKCE (`S256`) flow (`/auth/entra/redirect`, `/auth/entra/callback`), single-use `state` and `nonce` verification, RS256 JWKS signature verification, consumer account rejection (`tid = 9188040d-6c67-4c5b-b112-36a304b66dad`), immutable `tid + oid` external identity binding, and explicit prevention of silent email takeover (`SSO_UNLINKED_EXISTING_ACCOUNT`).
- **Defects Identified & Remediated (`D-05`):**
  - `TenantSsoConnection::$allowed_domains` was stored but not enforced during `createSsoInvitation()` or `resolveAndAuthenticateUser()`. Remediated via `EntraSsoService::assertEmailDomainAllowed()` (`403 SSO_EMAIL_DOMAIN_NOT_ALLOWED`).
  - `EntraSsoController::upsertConnection()` omitted `hr_approver`, `auditor`, and `recruitment_admin` from `default_jit_role_slug` validation. Remediated.

### 2.2 Multi-Tenancy, RBAC, ABAC & Audited Support Access (`TenantContextService`, `RequirePermission`, `RequirePlatformSuperAdmin`, `AuditGovernanceController`)
- **Verified Working Workflows:**
  - Mandatory tenant context resolution (`EnforceTenantAndSecurityContext`), strict cross-tenant isolation (`TenantContextService::assertSameTenant()` -> `403 TENANT_SCOPE_VIOLATION` + `AuditEvent`), and strict separation between tenant-scoped memberships and global `PLATFORM_SUPER_ADMIN` authority (`RequirePlatformSuperAdmin`).
  - Time-boxed Audited Support Access (`SupportAccessGrant`) requiring ticket number and explicit reason, with immediate revocation and full audit trail.
- **Defects Identified & Remediated (`D-01`, `D-02`, `D-08`):**
  - **Multi-Permission Route Middleware (`D-01`):** Enhanced `User::hasAnyPermission()` and `RequirePermission` middleware to accept variadic permissions (`permission:p1,p2,...`), enabling least-privilege single-role access to `/evaluations`, `/offers`, and `/hr-handover` while attaching explicit permission middleware to all 5 `/hr-handover` routes and `platform.superadmin` middleware to `POST /admin/platform/support-context`.
  - **Support Context ABAC PII Prohibition (`D-02`):** Enforced `abac_scope['candidate_pii_browsing_prohibited'] === true` in `User::permissionSlugsForTenant()` so a Platform Super Admin operating under a support grant cannot browse `/candidates` or `/applications` unless holding a direct tenant membership.
  - **Permission-Aware Sidebar Navigation (`D-08`):** Updated `resources/views/layouts/app.blade.php` so navigation links are rendered only when `$authUser` holds the required permission(s) in the active tenant.

### 2.3 Organization Reference Data & Job Requisitions (`OrganizationController`, `RequisitionController`, `RequisitionService`)
- **Verified Working Workflows:**
  - Legal entity, department, and location creation (`/organization`).
  - Requisition creation (`REQ-YYYY-NNNN`), immutable `RequisitionVersion` snapshots on creation and edit, submission for approval (`PENDING_APPROVAL`), multi-step approval/rejection/changes-requested workflow, and strict self-approval prohibition (`REQUISITION_SELF_APPROVAL_PROHIBITED`).
- **Defects Identified & Remediated (`D-04`, `D-08`):**
  - **`ON_HOLD` Material Change Invalidation (`D-04`):** Included `Requisition::STATUS_ON_HOLD` in `RequisitionService::updateRequisition()` so material edits (`job_title`, `headcount`, `salary_min`, `salary_max`, `department_id`, `legal_entity_id`, `location_id`, `employment_type`) to an `ON_HOLD` requisition invalidate prior approvals and reset status to `DRAFT`.
  - **UI Lifecycle Controls (`D-08`):** Added inline Requisition Edit modal/card and `ON_HOLD` / Reopen (`OPEN`) / `CLOSED` / `CANCELLED` status transition buttons in `resources/views/requisitions/show.blade.php`.

### 2.4 Job Postings & Public Career Portal (`JobPostingController`, `JobPublishingService`, `CareerPortalController`)
- **Verified Working Workflows:**
  - Job posting creation from requisitions, immutable `JobPostingVersion` snapshots, approval-gated publication (`REQUISITION_NOT_APPROVED_FOR_PUBLICATION` if requisition is not `APPROVED` or `OPEN`), unpublish and close controls.
  - Public Career Portal (`/careers`, `/careers/{tenantSlug}/{jobSlug}`), Schema.org `JobPosting` JSON-LD structured data, `/sitemap.xml`, and `/robots.txt`.
- **Defects Identified & Remediated (`D-08`):**
  - Added Job Posting Edit form in `resources/views/jobs/show.blade.php` and requisition filter dropdown in `resources/views/jobs/index.blade.php`.

### 2.5 Candidate CRM, Private Document Storage, Consent, Retention & DSAR (`CandidateAndApplicationController`, `CandidateAndApplicationService`)
- **Verified Working Workflows:**
  - Public and internal candidate intake, tenant-scoped email deduplication, mandatory privacy consent on public application (`PRIVACY_CONSENT_REQUIRED`), private disk (`local`) resume upload with SHA-256 checksum and executable extension block, and authorized document download streaming (`GET /candidates/{candidate}/documents/{document}/download`).
- **Defects Identified & Remediated (`D-06`, `D-07`, `D-08`):**
  - **Consent Withdrawal & DSAR Lifecycle (`D-06`):** Added `POST /candidates/{candidate}/consent` (`CandidateAndApplicationController::updateConsent` and `CandidateAndApplicationService::updateCandidateConsentAndDsar`) and UI controls on `/candidates/{candidate}` to record consent withdrawal/re-granting and DSAR status transitions (`NONE`, `OPEN`, `IN_PROGRESS`, `FULFILLED`) with `CANDIDATE_CONSENT_GRANTED` / `CANDIDATE_CONSENT_WITHDRAWN` audit events.
  - **Tenant-Configurable Retention Policy (`D-06`):** Replaced hardcoded `now()->addDays(365)` in `CandidateAndApplicationService` with `$tenant->retention_days` (`1..3650`, default `365`).
  - **Stage Transitions (`D-07`):** Added lowercase `'offer'`, `'hr_handover'`, and `'hired'` keys to `CandidateAndApplicationService::STAGE_TRANSITIONS` to match `OfferAndHrHandoverService` stage assignments.

### 2.6 Structured Screening, Assessments, Interviews, Scorecards & Hiring Decisions (`EvaluationAndDecisionController`, `EvaluationAndDecisionService`)
- **Verified Working Workflows:**
  - Structured screening criteria & evaluations, assessment records, interview scheduling, multi-interviewer scorecards (`STRONG_YES`, `YES`, `NEUTRAL`, `NO`, `STRONG_NO`), and hiring decisions (`HIRE`, `NO_HIRE`, `HOLD`) with self-approval prohibition (`HIRING_DECISION_SELF_APPROVAL_PROHIBITED`).
- **Defects Identified & Remediated (`D-01`, `D-08`):**
  - Allowed single-role interviewers (`interviews.manage`) and decision makers (`decisions.manage`) to access `GET /evaluations` (`D-01`) and use the Stage Move form (`D-08`).

### 2.7 Offers, Offer Versions & Approvals (`OfferAndHrHandoverController`, `OfferAndHrHandoverService`)
- **Verified Working Workflows:**
  - Offer template creation, offer generation gated on an approved `HiringDecision`, immutable `OfferVersion` snapshots with SHA-256 content hashes, offer approval workflow, material revision invalidation (resetting status to `DRAFT`), and send/accept/decline/withdraw transitions.
- **Defects Identified & Remediated (`D-01`, `D-08`):**
  - Enforced `$this->tenantContext->requireTenantId()` in `offersIndex()`, permitted `offers.approve` holders on `GET /offers`, added optional `?application_id=` filter on `GET /offers`, and added deep-links from `/applications` to `/offers?application_id={id}`.

### 2.8 Mandatory HR Validation & Dynamics 365 Finance HR Handover Safety Gate (`HRH-01`..`HRH-08`)
- **Verified Working Workflows:**
  - Offer acceptance creates/updates `HrValidationCase` in `not_started` / `in_validation` and **never** dispatches employee creation (`HRH-01`).
  - All 6 mandatory checklist items (`identity_verified`, `right_to_work_verified`, `background_check_completed`, `tax_and_bank_collected`, `legal_entity_confirmed`, `start_date_confirmed`) must be completed before HR approval (`HRH-02`..`HRH-04`).
  - Explicit separation of duties between checklist validator (`hr_validation.manage`) and HR approver (`hr_handover.approve`) (`HRH-05`).
  - Dispatch creates `EmployeeHandover` and `EmployeeHandoverAttempt` records with full reconciliation support (`HRH-06`..`HRH-08`).
- **Defects Identified & Remediated (`D-01`, `D-07`):**
  - Attached explicit route permissions to all 5 `/hr-handover` routes (`D-01`).
  - Fixed `OfferAndHrHandoverService::buildCanonicalHandoverPayload()` to use `$candidate->email ?? $candidate->primary_email` so the canonical payload always contains the candidate's email (`D-07`).
  - Enforced `PlatformConfigService::getBool('dynamics.global_live_writes_blocked', true)` inside `OfferAndHrHandoverService::dispatchToDynamics()` when `$simulatedOutcome === 'live'` (`422 DYNAMICS_GLOBAL_LIVE_WRITES_BLOCKED`) (`D-07`).

### 2.9 Provider-Neutral AI Gateway (`AiGatewayController`, `AiGatewayService`)
- **Verified Working Workflows:**
  - Provider configurations (`claude`, `gemini`, `openai`, `deterministic_fake`), versioned prompt registry, PII redaction (`[REDACTED_EMAIL]`, `[REDACTED_PHONE]`) prior to prompt interpolation, monthly token budget limits, global (`ai.global_kill_switch`) and provider (`kill_switch_engaged`) kill switches, and mandatory `human_review_required = true` on all AI runs.
- **Defects Identified & Remediated (`D-03`):**
  - Enforced tenant-level `$tenant->ai_kill_switch` in `AiGatewayService::executeTask()` (`409 AI_TENANT_KILL_SWITCH_ENGAGED` + `AuditEvent`).

### 2.10 REST API v1, Governed MCP Server v1 & Signed Outbound Webhooks (`ApiV1AndMcpController`, `ApiAndMcpPlatformService`, `DeliverSignedWebhookJob`, `WebhookSsrfGuard`)
- **Verified Working Workflows:**
  - `ApiClientPrincipal` authentication isolated from human `User` roles (`URAP-REM-002`), atomic database-locked idempotency (`URAP-REM-003`), encrypted webhook signing secrets at rest + queued delivery + SSRF guard (`URAP-REM-004`), and MCP tool scope enforcement + autonomous action prohibition (`MCP_PROHIBITED_AUTONOMOUS_ACTION`).
- **Defects Identified & Remediated (`D-03`, `D-08`):**
  - Blocked API/MCP bearer calls for `SUSPENDED` tenants (`403 TENANT_SUSPENDED`) in `ApiV1AndMcpController::resolveApiAuthContext()`.
  - Enforced tenant-level `$tenant->mcp_enabled` in `listMcpTools()` and `invokeMcpTool()` (`403 MCP_DISABLED_FOR_TENANT`).
  - Enforced `webhooks.global_delivery_enabled` in `DeliverSignedWebhookJob::handle()` (`PAUSED_BY_PLATFORM_POLICY` / `WEBHOOK_GLOBAL_DELIVERY_DISABLED`).
  - Hidden API client creation form in `resources/views/integrations/api_mcp.blade.php` when the user lacks `integrations.manage`.

### 2.11 Platform Super Admin & Unified Configuration Centre (`PlatformAdminController`, `PlatformAdminService`, `PlatformConfigService`)
- **Verified Working Workflows:**
  - CLI commands (`platform:admin-status`, `platform:admin-provision`, `platform:admin-revoke`), last-Super-Admin lockout protection, 11-section Configuration Centre (`/platform-admin`), optimistic locking (`expected_version`), mandatory justification for high-risk keys, and secret masking.
- **Defects Identified & Remediated (`D-03`, `D-08`):**
  - Fixed `PlatformAdminService::createTenant()` to default `mcp_enabled` to `false` and persist `require_sso` / `allow_local_fallback`, fixed `updateTenantLifecycle()` to persist `require_sso`, fixed `updateWebhookSubscriptionStatus()` audit log field (`target_url`), and fixed API client table properties (`client_id`, `is_active`) in `resources/views/platform_admin/index.blade.php`.

### 2.12 Analytics, Health Probes & Operational Diagnostics (`AnalyticsController`, `HealthController`)
- **Defects Identified & Remediated (`D-08`):**
  - Fixed `resources/views/analytics/index.blade.php` to render array-structured `$sourceEffectiveness` rows (`total`, `hired`, `conversion_rate`) and updated `AnalyticsController::index()` to include `hr_validation_cases` in `not_started`/`in_validation`/`pending_fixes` in `handover_blocked`.
  - Wired `PlatformConfigService` (`platform.display_name` and `dynamics.global_live_writes_blocked`) into `HealthController::check()` and `readiness()`.

---

## 3. Automated Test & Verification Evidence

### 3.1 PHPUnit Feature & Regression Suite (MariaDB 12.3)
```text
PHPUnit 12.5.40 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.5.11
Configuration: /home/career/htdocs/career.tringers.com/phpunit.xml

.........................................                         41 / 41 (100%)

Time: 00:04.221, Memory: 26.00 MB

OK (41 tests, 568 assertions)
```

### 3.2 Code Style & Dependency Security
- `./vendor/bin/pint --test`: **`PASS` (`129` files)**
- `composer audit`: **`No security vulnerability advisories found.`**
- `php artisan platform:admin-status --json`: **`status: WARNING_NO_REAL_SUPERADMIN` (`active_real_superadmins: 0`, `active_synthetic_superadmins: 1`, `synthetic_accounts_present: true`)** — preserved per explicit owner development continuation directive.
