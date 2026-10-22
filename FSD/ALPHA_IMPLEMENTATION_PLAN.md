# Unified Recruitment AI Platform — Alpha Implementation Plan

**Document ID:** URAP-ALPHA-PLAN-001  
**Version:** 1.0  
**Status:** Active — Execution in Progress  
**Date:** 2026-10-22  
**Owner / Audience:** Lead Architect, Laravel Engineering, Security, QA, DevOps, Release Engineering  
**Baseline Dependency:** [Level 2 Functional, Technical and Integration Specification v2.0](UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md) (`URAP-L2-SPEC-001`)

---

## 1. Repository & Authorized Server Baseline Assessment

### 1.1 Repository State at Discovery
- **Repository:** `https://github.com/routeget/recruitment`
- **Branch:** `main` (baseline commit `497226c`)
- **Initial Tracked Files:** `README.md`, `FSD/README.md`, `FSD/UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md`, `FSD/ANTIGRAVITY_ALPHA_IMPLEMENTATION_MASTER_PROMPT.md`
- **Initial Untracked Server Files:**
  - `.env`: Authoritative environment configuration containing pre-provisioned MariaDB and Redis credentials. Backed up safely with preserved permissions to `/home/career/backups/.env.backup.initial`. Never printed, committed, or overwritten.
  - `public/index.php`: Initial web root placeholder (`Hello World :-)`).

### 1.2 Verified Server & Runtime Environment
- **Authorized Testing URL:** `https://career.tringers.com` (live over HTTP/2 + TLS 1.3 via NGINX)
- **Deployed Application Path:** `/home/career/htdocs/career.tringers.com` (web root: `/home/career/htdocs/career.tringers.com/public`)
- **Operating System:** Ubuntu 24.04.5 LTS (`x86_64`)
- **PHP Runtime:** PHP `8.5.11` (`cli` and `fpm-fcgi`) with `pdo_mysql`, `redis`, `mbstring`, `xml`, `curl`, `zip`, `bcmath`, `intl`, `gd`, `sodium`, `openssl`, `fileinfo`, `pcntl`, `posix`, `Zend OPcache`
- **Dependency & Build Toolchain:** Composer `2.10.2`, Node.js `v24.22.0`, npm `11.19.1`
- **Primary Relational Store:** MariaDB `12.3.3-MariaDB-ubu2404` (InnoDB), verified connected via `.env` credentials (`0` initial tables)
- **Cache & Queue Store:** Redis (`127.0.0.1:6379`), verified connected with configured authentication (`PONG`)
- **Environment Mode:** `APP_ENV=local`, `APP_DEBUG=false`, `APP_URL=https://career.tringers.com` (confirmed development/testing environment)

---

## 2. Current-vs-Required Gap Matrix

| Domain Area | Specification Section | Baseline State (Pre-Increment 1) | Required Alpha State |
|---|---|---|---|
| Laravel 13 Application Foundation | §§1, 4, 5, 15, 16 | Placeholder `public/index.php` only | Full Laravel 13 modular monolith, Bootstrap 5 UI, health endpoints, structured logging, Correlation ID middleware |
| Multi-Tenant Isolation & IAM | §§4, 5, 18 | None | `tenants`, `users`, `memberships`, `roles`, `permissions`, server-side tenant context resolution, RBAC + record-level ABAC policies, append-only `audit_events` |
| Organization & Workforce Requisitions | §§6.1–6.3, 7 | None | Departments, locations, legal entities, lookup values; Requisition CRUD, versioning, approval workflow (`DRAFT` → `SUBMITTED` → `IN_APPROVAL` → `APPROVED` → `OPEN`/`ON_HOLD`/`CLOSED`/`CANCELLED`), self-approval prevention |
| Job Descriptions & Career Portal / CMS | §§6.3, 6.4, 7 | None | Job descriptions with versioning & approvals; public career portal with tenant-scoped published jobs, search/facets, canonical SEO metadata, `sitemap.xml`, `robots.txt`, Schema.org `JobPosting` JSON-LD |
| Candidate CRM, Consent, Documents & Applications | §§6.5–6.7, 7, 18 | None | Candidate profiles, deduplication, consent tracking, DSAR support, private document storage with SHA-256 checksums & authorized downloads, Application intake & stage state machine |
| Screening, Assessments, Interviews & Decisions | §§6.8–6.11, 7 | None | Criteria sets, screening runs, assessment invitations/results, interview scheduling, structured scorecards, hiring decision packets & immutable decision records |
| Offer Management | §§6.12, 7 | None | Controlled offer templates, compensation proposals, immutable offer versions, offer approval, issuance, acceptance/decline evidence, material-change approval invalidation |
| Mandatory HR Validation & Handover Safety Gate | §§6.13, 7, 8 | None | Versioned HR checklists, HR validation case lifecycle, separate explicit HR/onboarding approval (distinct from validator/self), atomic snapshot + transactional outbox, idempotency, reconciliation, negative tests `HRH-01`–`HRH-08` |
| Runtime AI Gateway | §§6.8, 6.16, 11, 12 | None | Provider-neutral AI gateway (`ClaudeAdapter`, `GeminiAdapter`, `OpenAiAdapter`, `DeterministicFakeAiAdapter`), task envelope, prompt registry, output schema validation, kill switch, budget control, PII redaction, human review |
| REST API (`/api/v1`), Webhooks & MCP Server | §§6.17, 6.18, 13, 14 | None | Versioned `/api/v1` endpoints, API client scopes, idempotency middleware (`IDEMPOTENCY_CONFLICT`), signed webhooks with replay protection, dedicated `/mcp/v1` server with allowlisted tenant-scoped tools |
| Dynamics 365 Finance HR Integration | §§6.19, 9, 10 | None | Dedicated integration module, connection/health UI, field-ownership matrix enforcement, dry-run/sandbox adapter, real employee creation disabled until external credentials & authorization are configured |

---

## 3. Module & Service Architecture

All business transitions, permissions, tenant checks, and audit logging are enforced in domain services under `app/Services/` and policies under `app/Policies/`:

1. `TenantContextService`: Resolves active tenant from authenticated membership, bound API client, or verified portal domain/slug; never trusts raw request `tenant_id`.
2. `AuditService`: Writes append-only `audit_events` with correlation ID, actor, tenant, entity, action, before/after redacted metadata, and IP/user-agent.
3. `RequisitionService`: Enforces requisition state machine, versioning, sequential approval decisions, and self-approval prohibition.
4. `JobPublishingService`: Manages job descriptions, versions, approvals, publication eligibility, and Career Portal feed/JSON-LD generation.
5. `CandidateCrmService` & `DocumentStorageService`: Handles candidate deduplication, explicit consent records, DSAR export/erasure markers, and private filesystem document storage (`storage/app/private/tenants/{tenant_id}/...`) with SHA-256 checksum verification and extension/MIME validation.
6. `ApplicationPipelineService`: Guards application stage transitions (`RECEIVED` → `ELIGIBILITY_REVIEW` → `SCREENING` → `SHORTLISTED` → `ASSESSMENT` → `INTERVIEW` → `DECISION_PENDING` → `OFFER_IN_PROGRESS` → `HIRED_PENDING_HR_HANDOVER` or `REJECTED`/`WITHDRAWN`/`ON_HOLD`) and records `application_stage_events`.
7. `ScreeningAndInterviewService`: Manages screening runs, criteria evidence, assessment attempts, interview schedules, participant scorecards, and hiring decision records.
8. `OfferWorkflowService`: Manages offer templates, immutable `offer_versions`, offer approvals, delivery, acceptance evidence, and automatic invalidation when material fields change.
9. `HrValidationAndHandoverService`: Enforces the **Mandatory HR Handover Safety Gate** (`HRH-01` through `HRH-08`). Verifies accepted offer version, completed checklist items, authorized HR/onboarding approver (preventing unauthorized or stale approval), and atomically writes `hr_handover_approvals`, `hr_handover_snapshots` (with SHA-256 payload hash), `hr_handover_records`, and `transactional_outbox_events` inside a single DB transaction with row locking (`SELECT ... FOR UPDATE`).
10. `DynamicsHrConnectorService`: Consumes outbox events outside the DB transaction, enforces idempotency and reconciliation-before-retry, supports `DRY_RUN`/`DISABLED`/`SANDBOX` modes, and stores `external_employee_references`.
11. `AiGatewayService`: Single runtime entrypoint for all AI tasks (`jd_drafting`, `resume_parsing`, `candidate_matching`, `screening_summary`, `interview_synthesis`, `offer_drafting`, `analytics_narrative`). Enforces kill switches, tenant budgets, prompt version registry, PII minimization, output schema validation, and human-in-the-loop review flags.
12. `ApiPlatformService` & `WebhookDeliveryService`: Handles API client token authentication, scope checks, request-hash idempotency enforcement, and HMAC-SHA256 signed webhook deliveries.
13. `McpServerService`: Implements the allowlisted Model Context Protocol server with strict schema validation, tenant scoping, rate limiting, and audit logging.

---

## 4. Field-Level Ownership Matrix (Recruitment vs. Dynamics 365 Finance HR)

| Entity / Field Group | Authoritative System | Direction | Write Policy & Conflict Behavior |
|---|---|---|---|
| Requisitions, Job Descriptions, Publications | Recruitment Platform | Internal / Outbound Ref | Recruitment owns full lifecycle and approval history |
| Candidate Profiles, CVs, Consent, Screening, Interviews, Scorecards | Recruitment Platform | Internal Only | Never transmitted to Dynamics HR; retained under recruitment retention policy |
| Offer Terms, Offer Versions, Offer Approval & Acceptance Evidence | Recruitment Platform | Internal (Snapshot summarized) | Recruitment is authoritative; material post-approval changes invalidate HR handover approval |
| HR Validation Checklist & Explicit Handover Approval | Recruitment Platform | Gate Control | Mandatory prerequisite before any Dynamics handover payload can be queued |
| Approved Handover Snapshot (`legal_entity_code`, `department_code`, `job_code`, `start_date`, `compensation_summary`, `candidate_identity_summary`) | Recruitment Platform (at handover time) | Outbound (`Recruitment -> Dynamics`) | Immutable snapshot hash (`SHA-256`) created at approval time; dispatched via transactional outbox |
| Dynamics Employee / Worker ID (`external_employee_id`) | Dynamics 365 Finance HR | Inbound Response (`Dynamics -> Recruitment`) | Generated by Dynamics upon handover success; stored in `external_employee_references` |
| Post-Hire Employee Lifecycle (Payroll, Leave, Performance, Position Changes) | Dynamics 365 Finance HR | Dynamics Internal | Out of scope for Recruitment writes after initial handover completion |

---

## 5. Incremental Delivery Schedule & Status

| Increment | Scope | Target Gate | Status |
|---|---|---|---|
| **Increment 0** | Discovery, server & `.env` audit, `/FSD` operational docs, baseline CI | G1 Prep | Completed |
| **Increment 1** | Laravel 13 foundation, Bootstrap 5 UI shell, health diagnostics, auth, multi-tenant context, RBAC/ABAC, audit trail, initial live browser deployment | G1 Foundation | In Progress |
| **Increment 2** | Organization setup (departments, locations, legal entities) & Workforce Requisitions with state machine and approvals | G2 Core (Part 1) | Planned |
| **Increment 3** | Job descriptions, Career Portal/CMS (SEO, `JobPosting` JSON-LD, sitemap), Candidate CRM, private Documents, Applications & pipeline | G2/G3 Core & Portal | Planned |
| **Increment 4** | Screening criteria/runs, Assessments, Interviews, Scorecards, Hiring Decisions & operational reporting | G5 Workflow | Planned |
| **Increment 5** | Offer templates/versions/approvals/acceptance + Mandatory HR Validation & Handover Safety Gate (`HRH-01`–`HRH-08`) | G5/G6 HR Gate | Planned |
| **Increment 6** | Versioned REST API (`/api/v1`), Idempotency, Signed Webhooks, and Dedicated MCP Server (`/mcp/v1`) | G7 API & MCP | Planned |
| **Increment 7** | Provider-neutral AI Gateway (Claude, Gemini, OpenAI adapters, prompt registry, kill switch, budgets, human review) | G4 AI Gateway | Planned |
| **Increment 8** | Dynamics 365 Finance HR connector boundary, field-map management, dry-run/sandbox execution, reconciliation controls | G8 Dynamics | Planned |
| **Increment 9** | Full regression, tenant-isolation & security verification, WCAG 2.2 AA accessibility & responsive audit, final Alpha handover report | G9/G10 Release | Planned |
