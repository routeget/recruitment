# Unified Recruitment AI Platform — Functional Defect Register

**Document ID:** `URAP-FDR-001`  
**Version:** `1.0`  
**Date:** `2026-10-22`  
**Status:** `ALL IDENTIFIED DEFECTS RESOLVED & REGRESSION-TESTED`  
**Baseline Commit:** `379b7b0bdd0febf3f777bcadc797cf8ce57efe87`  
**Application URL:** `https://career.tringers.com` (`/home/career/htdocs/career.tringers.com`)

---

## 1. Executive Summary

During the Platform-Wide Functional Audit across all application modules, routes, controllers, services, background jobs, Blade templates, and authorization layers against `FSD/UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md`, `FSD/MICROSOFT_ENTRA_ID_ENTERPRISE_SSO_LEVEL_2_SPECIFICATION.md`, and `FSD/ANTIGRAVITY_PLATFORM_SUPERADMIN_AND_CONFIGURATION_CENTRE_DIRECTIVE.md`, **8 functional/security defects and specification gaps (`D-01` through `D-08`)** were identified, systematically remediated in code, and verified via automated feature/regression tests in `tests/Feature/PlatformWideFunctionalAuditRemediationTest.php` (`8` test suites, `53` assertions; total suite `41` tests, `568` assertions).

| Defect ID | Domain / Module | Severity | Summary of Defect | Status | Regression Test |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **`D-01`** | RBAC / Route & Controller Authorization | **High** | Multiple `/hr-handover` routes lacked explicit `permission:...` middleware, `GET /evaluations` and `GET /offers` blocked single-role interviewers/approvers, `POST /admin/platform/support-context` relied only on controller check instead of route middleware, and `offersIndex`/`hrHandoverIndex` used nullable `currentTenantId()` instead of fail-closed `requireTenantId()`. | **RESOLVED** | `test_d01_rbac_and_navigation_enforcement_across_evaluations_offers_and_hr_handover` |
| **`D-02`** | Support Context ABAC (`candidate_pii_browsing_prohibited`) | **High** | Audited Support Context sessions stored `abac_scope.candidate_pii_browsing_prohibited = true`, but `User::permissionSlugsForTenant()` returned full `tenant_admin` permissions including `candidates.view`, `candidates.manage`, and `applications.manage`. | **RESOLVED** | `test_d02_support_context_enforces_candidate_pii_browsing_prohibition` |
| **`D-03`** | Tenant Flags & Platform Policy Enforcement | **High** | Tenant-level `ai_kill_switch`, `mcp_enabled`, and `status=SUSPENDED` were not enforced in `AiGatewayService` and `ApiV1AndMcpController`; `PlatformAdminService` hardcoded `mcp_enabled=true` and omitted `require_sso` on tenant creation/update; `DeliverSignedWebhookJob` did not check `webhooks.global_delivery_enabled`; `PlatformAdminService::updateWebhookSubscriptionStatus` logged `$locked->endpoint_url` (null) instead of `$locked->target_url`. | **RESOLVED** | `test_d03_tenant_level_ai_kill_switch_mcp_flag_and_suspended_tenant_api_block` & `test_d05_entra_sso_allowed_domains_and_global_webhook_delivery_pause` |
| **`D-04`** | Requisition `ON_HOLD` Material Change Invalidation | **Medium** | Editing material fields (`job_title`, `headcount`, `salary_min`, `salary_max`, etc.) on an `ON_HOLD` requisition did not invalidate prior approvals or reset status to `DRAFT` in `RequisitionService::updateRequisition()`. | **RESOLVED** | `test_d04_requisition_on_hold_material_change_invalidates_approvals` |
| **`D-05`** | Microsoft Entra ID SSO `allowed_domains` & JIT Role Validation | **Medium** | `EntraSsoService` stored `allowed_domains` on `TenantSsoConnection` but did not enforce domain allowlisting during `createSsoInvitation()` or `resolveAndAuthenticateUser()`; `EntraSsoController` omitted `hr_approver`, `auditor`, and `recruitment_admin` from `default_jit_role_slug` validation. | **RESOLVED** | `test_d05_entra_sso_allowed_domains_and_global_webhook_delivery_pause` |
| **`D-06`** | Candidate Consent Withdrawal / DSAR Lifecycle & Tenant Retention Policy | **Medium** | No route/controller/UI action existed for post-application consent withdrawal or DSAR status updates (`OPEN`, `IN_PROGRESS`, `FULFILLED`), and `CandidateAndApplicationService` hardcoded `addDays(365)` instead of using `$tenant->retention_days`. | **RESOLVED** | `test_d06_candidate_consent_withdrawal_dsar_update_and_tenant_retention_days` |
| **`D-07`** | HR Handover Canonical Payload Email, Global Dynamics Safety Gate & Stage Transitions | **Medium** | `OfferAndHrHandoverService::buildCanonicalHandoverPayload()` read `$candidate->primary_email` (null on `Candidate` model, which uses `email`), did not check `dynamics.global_live_writes_blocked` inside `dispatchToDynamics()`, and `CandidateAndApplicationService::STAGE_TRANSITIONS` omitted lowercase `'offer'` / `'hr_handover'` keys. | **RESOLVED** | `test_d07_hr_handover_canonical_email_dynamics_global_gate_and_stage_transitions` |
| **`D-08`** | UI / View Polish, Navigation Guarding & Analytics / Health Readiness Wiring | **Low–Medium** | `layouts/app.blade.php` displayed all sidebar links regardless of user permissions; `analytics/index.blade.php` rendered array-valued `sourceEffectiveness` rows as strings; `evaluations/index.blade.php` omitted `interviews.manage` permission for the Stage Move form; `platform_admin/index.blade.php` referenced `$client->key_prefix`/`$client->status` instead of `$client->client_id`/`$client->is_active`; `requisitions/show.blade.php` and `jobs/show.blade.php` lacked edit/hold/reopen/filter UI controls; `AnalyticsController` omitted `hr_validation_cases` from `handover_blocked`; `HealthController` did not reflect runtime `PlatformConfigService` overrides. | **RESOLVED** | `test_d08_analytics_source_effectiveness_rendering_and_health_platform_config` |

---

## 2. Detailed Defect Analysis & Remediation Evidence

### `D-01`: RBAC Route & Controller Authorization Hardening
- **Severity:** High
- **Spec Reference:** `FSD/UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md` §4.2 (RBAC Matrix), §5.6–§5.8
- **Root Cause:**
  1. `GET /hr-handover`, `POST /hr-handover/{case}/checklist`, `POST /hr-handover/{case}/approve`, `POST /hr-handover/{case}/dispatch`, and `POST /hr-handover/handovers/{handover}/reconcile` in `routes/web.php` did not have route-level `permission:...` middleware, and `OfferAndHrHandoverController::hrHandoverIndex()` lacked a permission check.
  2. `RequirePermission` middleware only accepted a single permission slug, preventing routes shared by multiple roles (`GET /evaluations`, `GET /offers`, `POST /offers/{offer}/accept`) from permitting any of a set of authorized permissions (`screening.manage,interviews.manage,decisions.manage,candidates.view` or `offers.manage,offers.approve`).
  3. `POST /admin/platform/support-context` lacked `->middleware('platform.superadmin')` at the route definition layer.
  4. `OfferAndHrHandoverController::offersIndex()` and `hrHandoverIndex()` used `$this->tenantContext->currentTenantId()` instead of `$this->tenantContext->requireTenantId()`.
- **Remediation:**
  - Added `User::hasAnyPermission(array $permissionSlugs, ?int $tenantId = null): bool` in `app/Models/User.php`.
  - Updated `app/Http/Middleware/RequirePermission.php` to accept variadic `string ...$permissions` and enforce `$user->hasAnyPermission($permissions, $tenantId)`.
  - Updated `routes/web.php` to attach multi-permission middleware to `/evaluations`, `/offers`, `/offers/{offer}/accept`, and `/hr-handover` routes, and attached `platform.superadmin` middleware to `POST /admin/platform/support-context`.
  - Updated `OfferAndHrHandoverController::offersIndex()` and `hrHandoverIndex()` to enforce `$this->tenantContext->requireTenantId()` and explicit permission checks.

### `D-02`: Support Context ABAC Candidate PII Browsing Prohibition
- **Severity:** High
- **Spec Reference:** `FSD/UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md` §4.3 (Audited Support Access & ABAC), `FSD/ANTIGRAVITY_PLATFORM_SUPERADMIN_AND_CONFIGURATION_CENTRE_DIRECTIVE.md` §5.3
- **Root Cause:**
  - When a Platform Super Admin activated a time-boxed `SupportAccessGrant`, `AuditGovernanceController::grantSupportContext()` stored `'abac_scope' => ['candidate_pii_browsing_prohibited' => true]`. However, `User::permissionSlugsForTenant()` granted all `tenant_admin` permissions whenever an active `SupportAccessGrant` existed, without stripping `candidates.view`, `candidates.manage`, and `applications.manage` when `candidate_pii_browsing_prohibited` was `true`.
- **Remediation:**
  - Updated `User::permissionSlugsForTenant()` in `app/Models/User.php` to inspect `$activeGrant->abac_scope['candidate_pii_browsing_prohibited']` and filter out `['candidates.view', 'candidates.manage', 'applications.manage']` unless the user holds a direct `TenantMembership` with those permissions.

### `D-03`: Tenant-Level Flags (`ai_kill_switch`, `mcp_enabled`, `status=SUSPENDED`) & Platform Webhook Delivery Controls
- **Severity:** High
- **Spec Reference:** `FSD/UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md` §6.4 (AI Kill Switches), §8.1–§8.3 (REST API & MCP Governance), `FSD/ANTIGRAVITY_PLATFORM_SUPERADMIN_AND_CONFIGURATION_CENTRE_DIRECTIVE.md` §4.4–§4.7
- **Root Cause:**
  1. `AiGatewayService::executeTask()` checked `ai.global_kill_switch` and `$providerConfig->kill_switch_engaged`, but did not check the tenant-level `$tenant->ai_kill_switch` boolean column.
  2. `ApiV1AndMcpController::resolveApiAuthContext()` did not verify `$tenant->status === 'ACTIVE'`, allowing API tokens belonging to a `SUSPENDED` tenant to call `/api/v1/*` and `/mcp/v1/*`.
  3. `ApiV1AndMcpController::listMcpTools()` and `invokeMcpTool()` checked `api.global_mcp_enabled` and `config('recruitment.mcp.enabled')`, but did not check `$tenant->mcp_enabled`.
  4. `PlatformAdminService::createTenant()` hardcoded `'mcp_enabled' => true` instead of defaulting to `false` (or reading `$data['mcp_enabled']`) and omitted `require_sso` / `allow_local_fallback`; `updateTenantLifecycle()` did not persist `require_sso`.
  5. `DeliverSignedWebhookJob::handle()` did not check `PlatformConfigService::getBool('webhooks.global_delivery_enabled', true)` before dispatching outbound HTTP requests.
  6. `PlatformAdminService::updateWebhookSubscriptionStatus()` logged `$locked->endpoint_url` (which does not exist on `WebhookSubscription`) instead of `$locked->target_url`.
- **Remediation:**
  - Enforced `$tenant->ai_kill_switch` in `AiGatewayService::executeTask()` (`409 AI_TENANT_KILL_SWITCH_ENGAGED` + `AuditEvent`).
  - Enforced `$tenant->status === 'ACTIVE'` in `ApiV1AndMcpController::resolveApiAuthContext()` (`403 TENANT_SUSPENDED`) and `$tenant->mcp_enabled` in `listMcpTools()` and `invokeMcpTool()` (`403 MCP_DISABLED_FOR_TENANT`).
  - Updated `PlatformAdminService::createTenant()`, `updateTenantLifecycle()`, and `updateWebhookSubscriptionStatus()`.
  - Updated `DeliverSignedWebhookJob::handle()` to check `webhooks.global_delivery_enabled` and pause delivery (`PAUSED_BY_PLATFORM_POLICY` / `WEBHOOK_GLOBAL_DELIVERY_DISABLED`) when disabled.

### `D-04`: Requisition `ON_HOLD` Material Change Approval Invalidation
- **Severity:** Medium
- **Spec Reference:** `FSD/UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md` §5.2 (Requisition Lifecycle & Material Change Rules)
- **Root Cause:**
  - `RequisitionService::updateRequisition()` checked `in_array($requisition->status, [Requisition::STATUS_APPROVED, Requisition::STATUS_OPEN, Requisition::STATUS_PENDING_APPROVAL], true)` when deciding whether a material change should invalidate approvals and reset status to `DRAFT`, omitting `Requisition::STATUS_ON_HOLD`.
- **Remediation:**
  - Added `Requisition::STATUS_ON_HOLD` to `$wasApprovedOrOpen` in `RequisitionService::updateRequisition()`.

### `D-05`: Microsoft Entra ID SSO `allowed_domains` Enforcement & JIT Role Validation
- **Severity:** Medium
- **Spec Reference:** `FSD/MICROSOFT_ENTRA_ID_ENTERPRISE_SSO_LEVEL_2_SPECIFICATION.md` §5.2, §6.3
- **Root Cause:**
  - `TenantSsoConnection::$allowed_domains` was persisted in the database, but `EntraSsoService::createSsoInvitation()` and `EntraSsoService::resolveAndAuthenticateUser()` did not validate the user's email domain against `$connection->allowed_domains` when non-empty.
  - `EntraSsoController::upsertConnection()` validated `default_jit_role_slug` against `recruiter,hiring_manager,interviewer,hr_operations` only, omitting `hr_approver,auditor,recruitment_admin`.
- **Remediation:**
  - Added `assertEmailDomainAllowed(TenantSsoConnection $connection, ?string $email)` in `EntraSsoService` and invoked it in both `createSsoInvitation()` and `resolveAndAuthenticateUser()` (`403 SSO_EMAIL_DOMAIN_NOT_ALLOWED`).
  - Expanded `default_jit_role_slug` validation in `EntraSsoController::upsertConnection()`.

### `D-06`: Candidate Consent Withdrawal / DSAR Lifecycle & Tenant Retention Days
- **Severity:** Medium
- **Spec Reference:** `FSD/UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md` §4.5, §5.4 (Candidate Privacy, Consent, Retention & DSAR)
- **Root Cause:**
  - While consent was captured at application submission, there was no controller action or UI form on `/candidates/{candidate}` to record consent withdrawal, re-granting, or DSAR status transitions (`NONE`, `OPEN`, `IN_PROGRESS`, `FULFILLED`).
  - `CandidateAndApplicationService::submitPublicApplication()` and `createInternalCandidate()` hardcoded `now()->addDays(365)` instead of reading `$tenant->retention_days`.
- **Remediation:**
  - Updated `CandidateAndApplicationService` to resolve `$tenant->retention_days` (`1..3650`, default `365`) when calculating `retention_expires_at`.
  - Added `CandidateAndApplicationService::updateCandidateConsentAndDsar()` and `CandidateAndApplicationController::updateConsent()` (`POST /candidates/{candidate}/consent`), with full audit trail (`CANDIDATE_CONSENT_GRANTED`, `CANDIDATE_CONSENT_WITHDRAWN`) and interactive UI controls in `resources/views/candidates/show.blade.php`.

### `D-07`: HR Handover Canonical Payload Email, Global Dynamics Safety Gate & Stage Transitions
- **Severity:** Medium
- **Spec Reference:** `FSD/UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md` §5.8, §9.2 (`HRH-01`..`HRH-08`)
- **Root Cause:**
  1. `OfferAndHrHandoverService::buildCanonicalHandoverPayload()` accessed `$application->candidate->primary_email`, whereas the `Candidate` model column is `email`.
  2. `OfferAndHrHandoverService::dispatchToDynamics()` checked `$connectorConfig->real_employee_creation_enabled` when `$simulatedOutcome === 'live'`, but did not also verify `PlatformConfigService::getBool('dynamics.global_live_writes_blocked', true)`.
  3. `CandidateAndApplicationService::STAGE_TRANSITIONS` only keyed `'OFFER'` and `'HR_HANDOVER'` in uppercase, whereas `OfferAndHrHandoverService` sets `current_stage = 'offer'` and `'hr_handover'` in lowercase.
- **Remediation:**
  - Updated `OfferAndHrHandoverService::buildCanonicalHandoverPayload()` to use `$candidate->email ?? $candidate->primary_email`.
  - Enforced `PlatformConfigService::getBool('dynamics.global_live_writes_blocked', true)` in `OfferAndHrHandoverService::dispatchToDynamics()` (`422 DYNAMICS_GLOBAL_LIVE_WRITES_BLOCKED`) and added `live` to the allowed `simulated_outcome` enum in `OfferAndHrHandoverController::dispatchHandover()`.
  - Added lowercase `'offer'`, `'hr_handover'`, and `'hired'` keys to `CandidateAndApplicationService::STAGE_TRANSITIONS`.

### `D-08`: UI / View Polish, Sidebar Permission Filtering, Analytics & Health Readiness
- **Severity:** Low–Medium
- **Spec Reference:** `FSD/UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md` §5.2–§5.8, §10, §12
- **Root Cause & Remediation:**
  - `resources/views/layouts/app.blade.php`: Filtered sidebar navigation items using `$authUser->hasPermission(...)` / `$authUser->hasAnyPermission(...)` so roles only see modules they are authorized to access, and dynamically displayed `platformBranding['display_name']`.
  - `resources/views/analytics/index.blade.php` & `app/Http/Controllers/AnalyticsController.php`: Fixed `$sourceEffectiveness` loop to render structured array metrics (`total`, `hired`, `conversion_rate`) and included `hr_validation_cases` in `not_started`/`in_validation`/`pending_fixes` in the `handover_blocked` counter.
  - `resources/views/evaluations/index.blade.php`: Enabled the Stage Move form for users with `interviews.manage` as well as `applications.manage` or `screening.manage`.
  - `resources/views/platform_admin/index.blade.php` & `resources/views/integrations/api_mcp.blade.php`: Fixed `$client->client_id` / `$client->is_active` display and hid the API Client creation form when the user lacks `integrations.manage`.
  - `resources/views/requisitions/show.blade.php`, `resources/views/jobs/show.blade.php`, `resources/views/jobs/index.blade.php`, `resources/views/candidates/index.blade.php`, `resources/views/applications/index.blade.php`: Added Requisition Edit & Hold/Reopen/Cancel controls, Job Posting Edit & Requisition filter, Offer deep-link from Applications, and Candidate DSAR status badges.
  - `app/Http/Controllers/HealthController.php`: Wired `PlatformConfigService` (`platform.display_name` and `dynamics.global_live_writes_blocked`) into `/health` and `/health/ready`.
