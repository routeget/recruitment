# Unified Recruitment AI Platform — Alpha Requirements Traceability Matrix

**Document ID:** URAP-ALPHA-RTM-001  
**Version:** 1.0  
**Status:** Living Traceability Matrix  
**Date:** 2026-10-22  
**Owner / Audience:** Architecture, Engineering, QA, Security, Product Owner  
**Baseline Dependency:** [Level 2 Specification v2.0](UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md) (`URAP-L2-SPEC-001`)

---

## 1. Requirement-to-Implementation & Verification Matrix

| Req ID | Spec Section | Requirement Summary | Code Module / Service / Migration | Automated Test Coverage | Live Browser Verification Evidence | Status |
|---|---|---|---|---|---|---|
| **RTM-01** | §§1, 4, 5, 18 | Multi-tenant SaaS isolation, server-resolved tenant context, RBAC + ABAC, append-only audit trail | `app/Services/TenantContextService.php`, `app/Services/AuditService.php`, `app/Http/Middleware/EnforceTenantContext.php` | `tests/Feature/TenantIsolationAndAuthTest.php` | Verified login, tenant switching, cross-tenant denial on `https://career.tringers.com` | Scheduled Increment 1 |
| **RTM-02** | §§6.1, 6.2, 7 | Organization structure (`departments`, `locations`, `legal_entities`) & Workforce Requisition lifecycle (`DRAFT` → `APPROVED` → `OPEN`) with self-approval prevention | `app/Services/RequisitionService.php`, `app/Http/Controllers/RequisitionController.php` | `tests/Feature/RequisitionLifecycleTest.php` | Verified org setup, requisition create, submit, approve, and dashboard metrics | Scheduled Increment 2 |
| **RTM-03** | §§6.3, 6.4, 7 | Job descriptions with versions/approvals, Career Portal/CMS, SEO canonical tags, `sitemap.xml`, `robots.txt`, Schema.org `JobPosting` JSON-LD for approved published jobs only | `app/Services/JobPublishingService.php`, `app/Http/Controllers/CareerPortalController.php` | `tests/Feature/CareerPortalAndJobPublishingTest.php` | Verified public career portal, job detail JSON-LD, draft job exclusion, sitemap | Scheduled Increment 3 |
| **RTM-04** | §§6.5, 6.6, 6.7, 7 | Candidate CRM, deduplication, consent tracking, private document uploads (`SHA-256` checksum, signed/authorized downloads), Application intake & stage transitions | `app/Services/CandidateCrmService.php`, `app/Services/DocumentStorageService.php`, `app/Services/ApplicationPipelineService.php` | `tests/Feature/CandidateApplicationDocumentTest.php` | Verified candidate creation, CV upload/download auth, application stage progression | Scheduled Increment 3 |
| **RTM-05** | §§6.8–6.11, 7 | Screening runs & criteria evidence, Assessments, Interview scheduling, structured Scorecards, and Hiring Decisions | `app/Services/ScreeningAndInterviewService.php`, `app/Http/Controllers/WorkflowEvaluationController.php` | `tests/Feature/ScreeningInterviewDecisionTest.php` | Verified screening evaluation, interview scorecard submission, and hiring decision packet | Scheduled Increment 4 |
| **RTM-06** | §§6.12, 7 | Offer templates, immutable `offer_versions`, offer approval, issuance, acceptance/decline evidence, and material-change approval invalidation | `app/Services/OfferWorkflowService.php`, `app/Http/Controllers/OfferController.php` | `tests/Feature/OfferLifecycleTest.php` | Verified offer creation, approval, candidate acceptance, and material edit invalidation | Scheduled Increment 5 |
| **RTM-07** | §§6.13, 7, 8 | **Mandatory HR Validation & Handover Safety Gate** (`HRH-01`–`HRH-08`): accepted offer alone never creates employee; versioned checklist completion + separate authorized HR approval + atomic snapshot hash + transactional outbox + idempotency + reconciliation | `app/Services/HrValidationAndHandoverService.php`, `app/Http/Controllers/HrHandoverController.php` | `tests/Feature/HrHandoverSafetyGateTest.php` (`HRH-01` through `HRH-08`) | Verified HR validation checklist, blocked premature handover, explicit approval, outbox & snapshot view | Scheduled Increment 5 |
| **RTM-08** | §§6.17, 6.18, 13, 14 | Versioned REST API (`/api/v1`), API client scopes, idempotency (`IDEMPOTENCY_CONFLICT`), signed webhooks with replay protection, and allowlisted tenant-scoped MCP server (`/mcp/v1`) | `app/Services/ApiPlatformService.php`, `app/Services/WebhookDeliveryService.php`, `app/Services/McpServerService.php` | `tests/Feature/ApiPlatformAndMcpServerTest.php` | Verified `/api/v1` endpoints, OpenAPI spec, webhook console, and MCP tool registry UI | Scheduled Increment 6 |
| **RTM-09** | §§6.8, 6.16, 11, 12 | Provider-neutral Runtime AI Gateway (Claude, Gemini, OpenAI adapters + deterministic fake), prompt registry, schema validation, kill switch, budgets, PII redaction, human review | `app/Services/AiGatewayService.php`, `app/Services/AiAdapters/*` | `tests/Feature/AiGatewayAndGovernanceTest.php` | Verified AI administration console, kill switch, budget guard, and human-reviewed AI drafting/screening | Scheduled Increment 7 |
| **RTM-10** | §§6.19, 9, 10 | Dynamics 365 Finance HR connector boundary, field-ownership matrix enforcement, dry-run/sandbox adapter, retry/reconciliation controls, real employee creation disabled by default | `app/Services/DynamicsHrConnectorService.php`, `app/Http/Controllers/DynamicsIntegrationController.php` | `tests/Feature/DynamicsConnectorAndReconciliationTest.php` | Verified Dynamics integration health/mapping console, dry-run dispatch, and reconciliation UI | Scheduled Increment 8 |
| **RTM-11** | §§19, 20, 24, 28 | Operational analytics/reporting, health diagnostics, accessibility (WCAG 2.2 AA), security headers, backup/restore verification, and Alpha release gate | `app/Http/Controllers/AnalyticsController.php`, `app/Http/Controllers/HealthController.php` | Full PHPUnit Suite | Full end-to-end browser verification on `https://career.tringers.com` | Scheduled Increment 9 |

---

## 2. Mandatory HR Handover Safety Gate Test Matrix (`HRH-01` to `HRH-08`)

| Test ID | Specification Rule | Expected Enforcement Outcome |
|---|---|---|
| `HRH-01` | Accepted offer without HR approval cannot enqueue or invoke employee creation | Attempting handover dispatch right after offer acceptance returns `APPROVAL_REQUIRED` (`422`/`403`); 0 outbox events created |
| `HRH-02` | Completed HR checklist without explicit HR approval remains blocked | Completing all checklist items moves case to `VALIDATION_COMPLETE`/`APPROVAL_PENDING`, but handover remains `BLOCKED` until explicit approval |
| `HRH-03` | Unauthorized approver (or self-approver who performed validation/offer creation where restricted) is denied | Returns `PERMISSION_DENIED` (`403`); audit event logged |
| `HRH-04` | Explicit approval creates one immutable snapshot (with SHA-256 hash) and one transactional outbox event atomically | Single DB transaction creates `hr_handover_approvals`, `hr_handover_snapshots`, and `transactional_outbox_events` |
| `HRH-05` | Timeout after possible remote success cannot create a duplicate employee on retry | Connector transitions to `RECONCILIATION_REQUIRED` and queries by `correlation_id`/`idempotency_key` before any re-create |
| `HRH-06` | Critical post-approval changes (offer version, start date, legal entity, compensation) invalidate approval | Editing material fields after approval invalidates handover readiness (`BLOCKED`) and requires revalidation/reapproval |
| `HRH-07` | Successful handover response stores returned external employee reference | `external_employee_references` stores Dynamics worker ID, legal entity, payload hash, and timestamps |
| `HRH-08` | Handover failure exposes sanitized error, retryability classification, owner, and next action | Permanent vs. transient errors classified; stack traces/secrets scrubbed; operator retry/reconcile controls enforced |
