# Unified Recruitment AI Platform
## Level 2 Functional, Technical and Integration Specification

**Document ID:** URAP-L2-SPEC-001  
**Version:** 2.0  
**Status:** Authoritative implementation baseline  
**Date:** 2026-10-22  
**Audience:** Antigravity, Claude, architects, Laravel engineers, QA, security, DevOps and HR integration teams.

> This document is the implementation source of truth for planned behavior and acceptance controls. It does not assert that the application is already implemented. Verify current official documentation and actual target-environment capabilities before implementing external integrations.

## 1. Purpose and governing decisions

Build a cloud-hosted, multi-tenant SaaS recruitment platform that owns the full recruitment lifecycle from workforce requisition and job description through career publishing, candidate management, screening, assessment, interviews, hiring decision, offer approval, delivery and acceptance.

The recruitment platform is authoritative for recruitment records and workflows. Microsoft Dynamics 365 Finance HR is a downstream HR system. **Offer acceptance alone must never create an employee record in Dynamics.** Employee creation is allowed only after the required HR validation is completed and an authorized HR/onboarding approver explicitly approves the handover.

Confirmed technology baseline:
- PHP 8.5, Laravel 13, Bootstrap 5, MariaDB/InnoDB, Redis, cloud hosting, private object storage and TLS.
- Multi-tenant SaaS with strict server-enforced tenant isolation.
- Modular monolith initially, with queue workers and independently secured integration services where operational isolation requires it. Avoid unnecessary microservices.
- Runtime AI providers: Claude, Gemini and OpenAI API behind a provider-neutral gateway.
- Codex is for a governed engineering workflow, not a runtime inference endpoint.
- Dedicated MCP server, versioned third-party REST API platform and separate Dynamics 365 Finance HR integration module.
- Relational database is authoritative; object storage holds private documents; search/vector retrieval is optional and never the system of record.

## 2. Normative and delivery rules

MUST/MUST NOT are mandatory requirements. SHOULD is a strong recommendation requiring a documented rationale to deviate. Do not change the stack, business ownership, HR approval gate or security controls without product-owner approval.

Before coding, inspect the repository, runtime, dependencies, existing migrations, routes, tests and deployment assumptions. Produce a gap analysis, module map, requirements traceability matrix, phased plan, external dependency list and acceptance gates. Do not silently invent rules affecting security, candidate rights, employment law, data ownership, or irreversible actions.

A feature is complete only when its UI/API, persistence, service logic, authorization, audit, error handling, automated tests and documentation work end to end. Do not use placeholder screens that appear functional. Use migrations, seeders, feature flags, reviewed changes and rollback plans. Do not knowingly propagate critical security or data-integrity defects. Ordinary defects may be logged and addressed in the dedicated remediation phase, while automated checks continue during development.

## 3. Scope and non-goals

In scope: workforce planning/requisitions, job descriptions and approvals, public career portal and CMS, candidate accounts and CRM, applications and pipeline, document handling, AI-assisted parsing and matching, assessments, interviews, hiring decisions, offer generation/approval/delivery/acceptance, post-offer HR validation, Dynamics handover, communications, workflow engine, analytics, AI administration, API platform, MCP, tenant/system administration, security and operations.

Not included in the initial release unless separately approved: payroll, leave, attendance, performance management, learning management, autonomous hiring/rejection, unrestricted AI actions, or training models on candidate data.

## 4. Personas and access controls

- Platform Superadmin: platform configuration, tenant lifecycle, provider catalog and health. Routine unrestricted candidate-PII browsing is prohibited; privileged support access must be scoped, justified and audited.
- Tenant Owner/Admin: tenant users, roles, organization structure, workflows, portal settings, retention and integrations. No access to other tenants.
- Recruitment Admin: recruitment rules, templates, screening criteria, workflows and reports.
- Recruiter: requisitions, jobs, candidates, applications, screening and communications within assigned scope.
- Hiring Manager/Interviewer: assigned requisitions, candidates, interviews and scorecards only.
- HR Validator: post-offer checklist, documents and employment-data validation.
- HR/Onboarding Approver: explicit approval or rejection of the handover.
- Candidate: own profile, consent, documents, applications, preferences and permitted status information only.
- Integration service identity: narrow non-interactive scopes, credential rotation and audit.
- Auditor: read-only access to permitted audit/compliance evidence.

Implement RBAC and record-level ABAC. Enforce permissions in service boundaries, not only in the UI. Prevent self-approval for configured high-risk actions. Candidate-facing users must never access internal notes, interviewer comments, AI deliberations or other candidates' records.

## 5. Tenant isolation

Every tenant-owned record must have tenant_id or a documented tenant-scoped parent relation. Resolve tenant context from trusted session/token/host mapping, never from an untrusted request field. Scope repositories, caches, queues, object paths, search/vector retrieval, idempotency keys, APIs, webhooks and audit queries by tenant. Test cross-tenant reads/writes, IDOR, guessed identifiers, queue payload tampering and retrieval leakage. Platform support access across tenants requires a privileged, reasoned and audited workflow.

## 6. Functional modules

1. **Organization and tenant setup:** tenant profile, brands, business units, departments, locations, legal entities, time zones, currencies, locales, settings and retention.
2. **Workforce requisitions:** role, reason, headcount, department/location, budget reference, target start date, versioning, approval, hold, cancellation and audit.
3. **Job descriptions:** structured job profile, responsibilities, qualifications, skills, experience, employment type, location, compensation range, inclusive-language assistance, versioning and approval.
4. **Career portal/CMS:** responsive public pages, search, facets, stable job URLs, canonical metadata, sitemap, robots directives, SEO and JobPosting structured data generated only from approved published jobs.
5. **Candidate CRM:** profile, experience, skills, talent pools, source attribution, deduplication, consent, communications and data-subject requests.
6. **Applications:** submission, required questions, documents, status transitions, recruiter assignment, stage history, withdrawal and candidate-safe status display.
7. **Documents:** upload limits, extension/content checks, malware scanning where available, private object storage, checksums, signed short-lived URLs, authorization and retention.
8. **AI parsing and screening:** normalized resume fields with confidence/provenance, criteria-level evidence, score breakdown, missing evidence, human review and model/prompt traceability.
9. **Assessments:** configurable invitations, attempts, scores, validity windows, accommodations and vendor adapters.
10. **Interviews:** scheduling, calendar adapter, interview packs, structured scorecards, feedback deadlines, reminders and conflicts.
11. **Hiring decision:** evidence packet, feedback, recommendation, authorized decision, rationale and immutable history.
12. **Offers:** compensation proposal, approved templates, versioning, approval, secure delivery, e-signature adapter, acceptance, decline, expiry and revocation.
13. **HR validation/handover:** checklist version, evidence, completion, separate approval, immutable payload snapshot, outbox, dispatch, retries and reconciliation.
14. **Communication:** email/SMS adapters, templates, localization, preferences, consent, delivery status and bounce handling.
15. **Workflow engine:** versioned transitions, sequential/parallel approval, delegation policy, deadlines, escalation, rejection and resubmission.
16. **AI administration:** providers, model catalog, prompt registry, routing, policies, budgets, evaluation and kill switch.
17. **API/webhooks:** client management, scopes, versioning, rate limits, idempotency, event subscriptions and delivery history.
18. **MCP:** allowlisted tools, scopes, input/output schemas, authorization, audit, rate limits and side-effect policy.
19. **Dynamics connector:** auth, environment, entity discovery, field maps, validation, handover, sync jobs, retries, dead-letter handling and reconciliation.
20. **Reporting/admin:** funnel, time-to-fill, stage aging, source quality, offer acceptance, handover backlog, AI usage, audit, settings, lookups and feature flags.

## 7. Lifecycle state machines

- Requisition: DRAFT → SUBMITTED → IN_APPROVAL → APPROVED → OPEN/ON_HOLD → CLOSED/CANCELLED.
- Job: DRAFT → PENDING_APPROVAL → APPROVED → PUBLISHED → UNPUBLISHED/EXPIRED/CLOSED.
- Application: RECEIVED → ELIGIBILITY_REVIEW → SCREENING → SHORTLISTED → ASSESSMENT/INTERVIEW → DECISION_PENDING → OFFER_IN_PROGRESS → HIRED_PENDING_HR_HANDOVER; alternative terminal/exception states include REJECTED, WITHDRAWN and ON_HOLD.
- Offer: DRAFT → APPROVAL_PENDING → APPROVED → ISSUED → ACCEPTED/DECLINED/EXPIRED/REVOKED. Material changes create a new immutable version and may invalidate prior approval.
- HR validation: NOT_STARTED → VALIDATION_PENDING → IN_PROGRESS → VALIDATION_FAILED or VALIDATION_COMPLETE → APPROVAL_PENDING → APPROVED_FOR_HANDOVER or HR_REJECTED.
- Handover: BLOCKED → READY → QUEUED → IN_PROGRESS → SUCCEEDED, or FAILED_RETRYABLE/FAILED_PERMANENT/RECONCILIATION_REQUIRED.
- Integration job: PENDING → RUNNING → SUCCEEDED/RETRY_WAIT/FAILED/DEAD_LETTER/CANCELLED.

Every transition must be guarded by server-side domain logic and append a durable event. UI controls alone are not enforcement.

## 8. Mandatory HR validation and handover gate

This is a release-critical business control:
1. An accepted offer is a prerequisite, not authorization to create an employee.
2. HR validation must be completed against a versioned checklist, including required documents, identity/employment details and configured mandatory checks.
3. A separate authorized HR/onboarding approver must explicitly approve. Validation completion is not approval.
4. Approval captures approver, role, timestamp, decision/reason, checklist version, accepted offer version, validated snapshot hash and approval-policy version.
5. Material changes to offer, identity, employing legal entity, job/position, start date or other configured critical fields must invalidate approval or require reapproval.
6. Create an immutable payload snapshot and outbox event in the same DB transaction. Dispatch asynchronously; never call a remote API while holding the DB transaction.
7. Include stable external correlation ID and idempotency key. Before retrying an uncertain request, reconcile by correlation/external ID to avoid duplicate employee creation.
8. Record target company/legal entity, returned employee/person ID, payload hash, adapter/mapping version, response status and timestamps.
9. Failures must remain visible, preserve the approval evidence, use bounded retry for transient errors and escalate permanent failures.
10. Support dry-run/integration-disabled mode that validates and previews payloads but creates no employee.

Mandatory acceptance tests:
- HRH-01: accepted offer without HR approval cannot enqueue or invoke employee creation.
- HRH-02: completed checklist without approval remains blocked.
- HRH-03: unauthorized approver is denied.
- HRH-04: approval creates one immutable snapshot and outbox event atomically.
- HRH-05: timeout after possible remote success cannot create a duplicate on retry.
- HRH-06: critical post-approval changes trigger the configured revalidation/reapproval rule.
- HRH-07: successful response stores the returned external employee reference.
- HRH-08: failure exposes sanitized error, retryability, owner and next action.

## 9. Dynamics 365 Finance HR integration

Build a dedicated module and worker boundary. Never connect directly to the Dynamics database. During discovery, confirm exact product/environment, Entra tenant, app registration, available API surface, enabled modules, legal entities, target worker/person entities, mandatory fields, create/update support and customer-specific validations.

Evaluate:
- Public-enabled Dynamics 365 Finance and Operations OData V4 data entities for supported, narrow operations.
- Data Management Framework/recurring integration patterns for supported asynchronous/bulk flows.
- Dataverse-based Dynamics 365 Human Resources ATS integration API only where that product capability/API is actually enabled and applicable.
- Dual-write only where supported and explicitly included in the agreed target architecture.

Do not assume these APIs are interchangeable. Verify current Microsoft documentation and actual environment metadata. Use supported Entra authentication, least privilege, TLS, external secret storage, token expiry handling and separate DEV/UAT/PROD configuration. No production credentials in code or test fixtures.

Configuration includes environment, base URL, directory/tenant ID, app ID, credential reference, API mode, company/legal entity, allowed operations, timeout, retry policy, field-map/schema versions, dry-run flag, health-check interval and alert destination. Monitor authentication, last success, queue depth, oldest pending handover, mapping errors, retries and reconciliation mismatches.

Maintain a field-level ownership matrix: authoritative system, direction, write permissions, transformation, sensitivity, retention and conflict behavior. Two-way sync is prohibited unless conflict resolution is documented. Inbound reference synchronization must not overwrite recruitment records.

## 10. Data ownership matrix

- Requisitions, job descriptions, job publications, candidates, CVs, applications, screening, interviews, hiring decisions, offers, acceptance evidence, HR validation and approval: recruitment platform.
- Employee/person identifier generated by Dynamics: Dynamics; store the returned ID as an external reference.
- Employment record after creation: Dynamics, subject to the agreed field-level ownership matrix.
- Position, department, legal entity, cost centre and job references: configurable by customer; validate before handover.
- Integration status, attempt history, correlation IDs and payload hashes: recruitment platform.
- Credentials/tokens: secret manager/deployment secret store, never plaintext application tables.

Only the minimum approved data should be handed to Dynamics. Recruitment history and offer documents remain in the recruitment platform under its retention policy unless a separate approved transfer rule says otherwise.

## 11. Runtime AI gateway

Recruitment modules must not call provider SDKs directly. All runtime AI calls go through one gateway responsible for task authorization, tenant context, sensitivity classification, minimal context assembly, prompt version, routing, schema validation, safety, budgets, audit, retries, fallback and observability.

Use a common task envelope containing task/version, trusted tenant and actor context, subject references, sensitivity class, locale, input/output schema versions, prompt version, deadline, idempotency and correlation IDs. Provider adapters translate this contract to Claude, Gemini and OpenAI formats. Model names, endpoints, regions, provider retention and capabilities must be configurable and verified before release.

Prompt versions require owner, change summary, tests/evaluation and activation audit. Validate all structured outputs server-side. Reject malformed outputs or send them to review. Fallback must respect sensitivity, allowed region, task capability, budget and provider refusal; it must never bypass a refusal or policy. Default logs exclude raw resumes, identity documents, full prompts and offer details. Provide task/provider kill switches, budgets, input limits, timeouts, concurrency limits and circuit breakers.

AI task requirements:
- JD drafting: structured draft and missing-information flags; human approval before publication.
- Resume parsing: normalized fields, confidence and evidence; human review for low confidence.
- Matching: criteria-level evidence, score breakdown, missing evidence and model/prompt trace.
- Screening summary: job-related evidence only; no protected-trait inference or unsupported personality claims.
- Interview prep/synthesis: grounded in approved competencies and supplied feedback; no invented evidence.
- Candidate communication: draft based on approved state/template; controlled send.
- Offer drafting: approved template and authorized data only; never autonomously approve, sign or release.
- Analytics narrative: permission-filtered aggregates; distinguish computed facts from generated explanation.

## 12. Fairness and human oversight

Do not use protected characteristics or inferred proxies as selection criteria. Keep lawfully collected fairness-audit data isolated from operational ranking. AI must not make final hiring/rejection, compensation or offer-release decisions. Human decision-makers must have appropriate permissions and record decisions. Allow correction/challenge of AI output without erasing original evidence. Evaluate quality, consistency, robustness, accessibility and potential disparate outcomes where lawful. Provide suitable privacy notices and retention/deletion handling. AI tools may not change workflow state or call external systems unless explicitly allowlisted, permission-checked, tested and audited.

## 13. MCP server

Deploy a dedicated, separately secured MCP service. MCP is not a REST replacement or privileged database shortcut. Register each tool with stable name, description, input/output schema, OAuth scope, tenant scope, rate limit, audit class, side-effect class and confirmation requirement.

Allowed examples: authorized open-job search, permitted requisition summary, candidate's own application status, draft JD generation, sanitized integration health and handover status. Prohibit arbitrary SQL/ORM, unrestricted filesystem, generic HTTP proxy, unrestricted OData, unrestricted candidate export and unrestricted email sending.

Tools must invoke domain services, never mutate tables directly. High-impact actions such as hiring decision, offer release, HR approval and employee creation must remain behind the same application gates; initial release should prefer read/status tools. Authenticate clients securely, validate issuer/audience/scopes, rotate credentials, rate-limit per client/tenant, bound payloads/timeouts/concurrency, sanitize errors and audit correlation IDs. Disable MCP by default until configured and reviewed.

## 14. Third-party REST APIs and webhooks

Publish a versioned REST API, e.g. /api/v1, with OpenAPI. Register clients, rotate/revoke credentials, enforce tenant binding and scopes, rate limits, expiry and audit. Use idempotency keys for retried side effects; same key with a different material request body must be rejected. Return consistent machine-readable errors with code, safe message, correlation ID, retryability and field errors.

Webhooks require endpoint verification, HTTPS policy, signing secret, timestamp/signature validation, delivery attempts, bounded backoff, dead-letter state, manual redelivery and replay protection. Use an outbox/reliable event pattern. Event catalog should include requisition approved, job published/closed, application received/stage changed, interview scheduled, offer issued/accepted/declined, HR validation completed, handover approved/started/succeeded/failed and integration health changed. Payloads contain identifiers/minimum event data, never CV binaries, identity documents, secrets or full profiles.

Baseline endpoint inventory:
- POST /api/v1/requisitions; POST /api/v1/requisitions/{id}/submit; POST /api/v1/requisitions/{id}/approval-decision.
- POST /api/v1/jobs/{id}/publish; GET /api/v1/jobs.
- POST /api/v1/candidates; POST /api/v1/applications; GET /api/v1/candidates/me/applications.
- POST /api/v1/applications/{id}/stage-transitions; POST /api/v1/applications/{id}/ai-screening-runs.
- POST /api/v1/applications/{id}/offers; POST /api/v1/offers/{id}/approval-decisions; POST /api/v1/offers/{id}/acceptance-events.
- POST /api/v1/hr-validation-cases; POST /api/v1/hr-validation-cases/{id}/complete; POST /api/v1/hr-validation-cases/{id}/approval-decisions; POST /api/v1/hr-handovers/{id}/dispatch.
- GET /api/v1/integrations/dynamics/health; GET /api/v1/integrations/jobs/{id}; POST /api/v1/webhook-subscriptions; POST /api/v1/ai/tasks/{taskName}.

This is a baseline, not a substitute for complete OpenAPI schemas and response documentation.

## 15. Persistence and critical entities

Use MariaDB/InnoDB, normalized authoritative business records, tenant-aware foreign keys/constraints, immutable approval/audit/state/integration events, and private object storage. Use checksums and metadata for documents, not public URLs. Use outbox events for reliable dispatch, unique constraints for external identifiers and idempotency, optimistic versioning for editable objects, and short transactions. Never hold DB locks across external calls.

Core entity groups:
- Tenant/identity: tenants, tenant_domains, users, memberships, roles, permissions, role_permissions, service_accounts, api_clients, api_scopes.
- Organization: brands, business_units, departments, locations, legal_entities, cost_centres, lookup_values, tenant_settings.
- Requisition/job: requisitions, versions, approvals, job_profiles, job_descriptions, versions, publications, channels.
- Candidate/application: candidates, consents, documents, talent_pools, memberships, applications, stage_events, answers, sources.
- Screening: criteria_sets, criteria, screening_runs, evidence, assessments, attempts and results.
- Interview: interviews, participants, scorecards, responses, feedback, scheduling_events.
- Offers: offers, versions, approvals, documents, delivery_events, acceptance_events, signature_transactions.
- HR handover: validation_cases, checklist_versions, items, results, approvals, snapshots, outbox, attempts, external_employee_references.
- AI: providers, models, task_policies, prompt_templates/versions, runs/events, usage, evaluations, budget_policies.
- Integrations: connections, field_maps/versions, jobs, attempts, reconciliation_items, webhook_subscriptions/deliveries, idempotency_records.
- Governance: workflow_definitions/versions, approval_instances/events, audit/security_events, feature_flags, retention_policies, notification_templates.

Critical fields include tenant_id and status/version fields, offer accepted_version_id, validation checklist version and snapshot hash, approval actor/decision/time/policy version, handover payload schema/map version, payload hash, idempotency key, correlation ID, attempt count, retryable error code and returned external employee ID. Exact schema/ERD and indexes must be documented during implementation planning.

## 16. Service boundaries

Create domain services for requisitions, job publication, candidates/consent, application workflow, documents, screening, interviews, offers, HR validation, HR handover, AI gateway, integration manager, API platform and MCP tool registry. Controllers and tools call these services. Do not scatter transition rules through controllers or frontend JavaScript.

## 17. Concurrency, errors and retries

Use stable codes such as VALIDATION_ERROR, AUTHENTICATION_REQUIRED, PERMISSION_DENIED, TENANT_SCOPE_VIOLATION, INVALID_STATE_TRANSITION, APPROVAL_REQUIRED, IDEMPOTENCY_CONFLICT, RATE_LIMITED, PROVIDER_UNAVAILABLE, INTEGRATION_MAPPING_ERROR and EXTERNAL_SYSTEM_REJECTED. Include correlation IDs; never expose stack traces or secrets.

Use request hashes for idempotency, reject key reuse with materially different requests, use optimistic version checks for edits, and use locks/compare-and-set only around critical state transitions. Retry only transient errors with bounded exponential backoff and jitter. Permanent validation/auth errors are not automatically retried. Uncertain Dynamics outcomes require reconciliation before re-create.

## 18. Security, privacy and compliance

TLS, secure cookies, CSRF protection, secure session rotation, MFA for privileged roles where supported, password hashing, brute-force protection and safe recovery are required. Encrypt sensitive data at rest/in transit; keep keys in a secret manager. Use private storage and short-lived signed URLs. Minimize data, track consent/purpose, configure retention, support candidate access/correction/deletion and propagate deletion to eligible search/vector/provider stores. Audit privileged actions append-only and scrub tokens, passwords, identity numbers and unnecessary PII.

Test OWASP risks including broken access control, IDOR, injection, XSS, CSRF, SSRF, unsafe uploads, mass assignment, insecure deserialization, prompt injection, retrieval poisoning, webhook forgery and tool abuse. Treat candidate content/tool outputs as untrusted data, never instructions. Run dependency/secret scans, static analysis, vulnerability tests and threat modeling. Critical unresolved vulnerabilities block release unless formally assessed under an approved policy.

## 19. Observability and operations

Structured logs should include correlation ID, safe tenant/actor identity, module, operation, outcome, latency and sanitized error. Monitor request latency/errors, queue depth, failed jobs, AI latency/usage/budget, provider availability, webhook delivery, Dynamics backlog, oldest pending handover, dead letters and privileged access. Health checks must not leak secrets or topology. Define and test backup/restore objectives, object recovery and reconciliation. Runbooks must cover provider outage/kill switch, API client compromise, MCP revocation, Dynamics credential rotation, stuck/duplicate handover, webhook replay, deletion requests and rollback.

## 20. Non-functional requirements

- Agree production availability, p95 performance, workload and RPO/RTO targets with product/hosting owners before launch.
- Public job search should be cacheable; AI/document/integration work should be asynchronous.
- Scale web workers and queue workers independently; bound provider concurrency.
- Target WCAG 2.2 AA for public and authenticated interfaces.
- Store timestamps in UTC and render in configured timezone; support locale-aware date/number/currency formatting.
- Critical transitions, approvals, AI policy changes, client changes and handover attempts require durable audit evidence.
- Document SLOs, query plans, performance tests, recovery plans and known limitations.

## 21. Testing and phase gates

- G1 Foundation: PHP/Laravel versions, migrations, CI, auth, tenant scope and baseline tests.
- G2 Core recruitment: requisitions, JD, approvals, jobs, candidates, applications and cross-tenant negative tests.
- G3 Portal/documents: candidate journey, SEO/JobPosting schema, upload security, private storage and consent.
- G4 AI: provider contracts, schema validation, prompts, budgets, fallback policy, redaction, audit and kill switch.
- G5 Screening/interviews/offers: evidence matching, human review, scorecards, versioning, approval invalidation, delivery and acceptance.
- G6 HR gate: HRH-01 through HRH-08, bypass tests, stale snapshots, idempotency and reconciliation.
- G7 API/MCP: OpenAPI, scopes, limits, idempotency, webhook signatures/replay, allowlist and cross-tenant denials.
- G8 Dynamics: auth, field maps, sandbox contract, supported/unsupported entities, transient/permanent failures, retry and duplicate prevention.
- G9 Hardening: static analysis, dependency/secret scans, security testing, performance, accessibility and restore rehearsal.
- G10 Release: UAT, runbooks, monitoring, backups, migrations/rollback and known-issues approval.

## 22. Recommended delivery sequence

P0 Discovery/architecture/traceability/threat model. P1 Foundation/tenancy/IAM/audit. P2 Requisitions/JDs/approvals. P3 Career portal/candidates/documents/applications. P4 Recruiter pipeline/assessment/interviews/notifications. P5 AI gateway and AI features. P6 Offers and acceptance. P7 HR validation/Dynamics handover. P8 REST API/webhooks. P9 MCP server. P10 analytics, accessibility, performance, security and recovery. P11 production readiness.

Complete and verify each phase, but do not interrupt all work for every ordinary defect. Record defects and blockers with severity, affected requirements, workaround, owner and evidence. Stop only affected work when continued implementation risks data corruption, critical security issues, violation of a confirmed business rule or major architectural rework.

## 23. Configuration and administration

Tenant admins manage branding, org structure, roles, workflows, templates, retention and permitted integrations. Platform admins manage platform feature availability, tenant lifecycle, provider catalog, global security and health without routine exposure to tenant candidate content. Configuration requires validation, permission, audit and version/effective date where relevant. Never display stored secrets after save. Provide inventory screens for lookups, statuses, templates, API scopes, MCP tools, AI tasks/models/prompts, Dynamics mappings, webhooks, workflow versions, retention and flags.

## 24. Reporting and analytics

Provide role-scoped reports for requisition aging, open roles, funnel conversion, stage aging, time-to-fill, source quality, assessment/interview completion, offer approval time, acceptance rate, HR handover backlog, integration health and AI usage. Define metric formulas, time windows, exclusions and timezone. Candidate-level analytics must be permission-checked. AI narrative must reference computed data and distinguish facts from generated interpretation. Fairness analytics require lawful purpose, minimized data, access controls and methodology review.

## 25. Environments, migrations and test data

Keep development, UAT and production isolated with separate credentials, URLs, storage and callback configurations. Use synthetic/anonymized candidate data; do not copy production PII without approved lawful basis. Validate migrations against representative data, index/query plans and rollback/forward-fix procedures. Use expand-migrate-contract for risky schema changes. Missing production secrets must fail safely. Production seeders must not create default shared passwords or sample candidate data.

## 26. CI/CD and instructions for Antigravity/Claude

- Inspect the repository first; report current state and gap matrix before broad implementation.
- Maintain traceability from each requirement to module, migration, route/API, UI, permission and test.
- Use feature branches and pull requests; require CI, code review, static analysis, dependency audit, secret scanning and security tests.
- Implement in phases and run targeted tests plus full regression before declaring a gate complete.
- Never change production data or deploy without explicit authorization. No production secrets or candidate PII in fixtures.
- If external access is unavailable, implement the adapter contract and mock/sandbox tests, and mark external validation pending.
- When a fix repeatedly fails, stop repeating it, reassess the design and prove an alternative with a regression test.
- Every phase report must list requirements, changed files, migrations, commands, test counts/failures, security findings, blockers, limitations, commit/PR, deployment status and next gate.
- Do not claim completion from code inspection alone. Demonstrate persistence, authorization, error paths, end-to-end behavior and tests.

## 27. Required deliverables

Architecture/module map, ADRs, threat model, traceability matrix, ERD/data dictionary, retention and ownership matrices, state diagrams, OpenAPI, webhook catalog, MCP tool catalog and permission matrix, AI task/prompt/routing/evaluation documentation, Dynamics API discovery and field-map workbook, HR validation checklist and HRH tests, UI/screen inventory, accessibility evidence, CI/CD, scan reports, test summaries, deployment/rollback guide, monitoring dashboards and support runbooks.

## 28. Release acceptance checklist

- All specified user journeys work end to end with positive and negative authorization tests.
- No cross-tenant leakage is found.
- No Dynamics employee creation occurs before accepted offer plus completed HR validation plus explicit authorized approval.
- Material offer changes invalidate or renew approval as configured.
- AI malformed output, provider outage, policy denial and budget exhaustion fail safely.
- Public portal only exposes approved jobs and handles SEO/publication expiry correctly.
- External calls have timeouts, correlation, bounded retries, sanitized errors and idempotency where required.
- REST and MCP enforce the same domain authorization/workflow guards as the application.
- Security, static analysis, dependency, performance and accessibility checks pass or have formally accepted non-critical exceptions.
- Backup/restore and operational runbooks have been exercised; monitoring is active.
- Documentation, traceability, known issues, deployment and rollback plans are current.

## 29. Official references to verify during implementation

- Dynamics 365 Finance and Operations data entities: https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/data-management-integration-data-entity
- Dynamics 365 Finance and Operations integration overview: https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/integration-overview
- Dynamics 365 Human Resources ATS integration API: https://learn.microsoft.com/en-us/dynamics365/human-resources/hr-admin-integration-ats-api-introduction
- Dynamics 365 Finance and Operations data entity security: https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/security-data-entities
- Laravel: https://laravel.com/docs
- Model Context Protocol: https://modelcontextprotocol.io/
- OWASP ASVS: https://owasp.org/www-project-application-security-verification-standard/
- OWASP Top 10: https://owasp.org/www-project-top-ten/

Verify current official documentation and actual tenant capabilities before finalizing integrations. Dataverse ATS APIs, Finance and Operations OData entities, Data Management integrations and dual-write are different mechanisms and must not be treated as interchangeable.

## 30. Final implementation directive

The first Antigravity/Claude response must provide repository/environment assessment, current-vs-required gap matrix, module/database map, phased plan, unresolved decisions, external access needed, test/security strategy, and explicit confirmation that the HR gate blocks premature employee creation.

After plan approval, implement the entire application incrementally, preserve requirements unless explicitly changed, and record every deviation for approval. The target is a complete integrated installable package first, followed by a dedicated blocker-remediation and release-validation phase. Continue basic tests during development and never knowingly propagate critical security/data-integrity failures.

**Governing rule:** The recruitment platform owns recruitment. Dynamics is a controlled downstream HR handover. Employee creation is impossible until the accepted offer, required HR validation and explicit authorized approval are all present.

## 31. Post-Release Privacy Hardening & Operational Governance (`PA-02`)

- **Fail-Closed External AI Privacy Attestation (`AiProviderConfig::evaluateCandidatePrivacyEligibility`):** External AI providers (`claude`, `gemini`, `openai`) cannot process candidate PII tasks (`resume.extract`, `resume_extraction`, `candidate.match`, `candidate.match.explanation`, `candidate_screening`) unless a structured `settings.privacy_attestation` is `VERIFIED`, un-expired, and includes `attestation_source`, `dpa_reference`, `zdr_or_retention_reference`, `verifier_identifier`, `verified_at`, `expires_at`, `approved_tasks`, `approved_models`, `redaction_enabled = true`, and provider-specific non-contradictory zero-data-retention/no-training settings. OpenAI Chat Completions requests send `"store": false`.
- **Pre-Provider Contact Minimization (`ResumeContentAnalyzer::buildMinimizedRedactedExternalResumePrompt`):** External AI prompts strip direct contact identifiers (`[REDACTED_EMAIL]`, `[REDACTED_PHONE]`, `[REDACTED_URL]`, `[REDACTED_SSN]`, `[REDACTED_SECRET]`) while locally extracted contact fields are merged back into the candidate record locally.
- **Completed HR Handover Snapshot Minimization (`CandidateAndApplicationService::minimizeCompletedHandoverSnapshot`):** Once an `HrHandover` reaches a completed, reconciled state (`COMPLETED`, `HANDOVER_COMPLETED`, `succeeded`, `reconciled`, `requires_reconciliation = false`), DSAR anonymization and `recruitment:enforce-privacy-retention` strip direct contact identifiers (`candidate_email`, `candidate_first_name`, `candidate_last_name`, `candidate_phone`, `address`) from `payload_snapshot` and `approved_payload_snapshot` while preserving statutory onboarding and reconciliation fields (`external_employee_id`, `external_request_id`, `idempotency_key`, `legal_entity_id`, `legal_entity_code`, `offer_version_id`, `offer_snapshot_hash`, `completed_at`, `snapshot_minimized = true`). Pending or reconciling handovers retain their operational payload until resolved. Active `LEGAL_HOLD` or `DISPUTE_ACTIVE` candidates are exempt from automated erasure and snapshot minimization.
- **Backup Retention & Post-Restore DSAR Replay (`recruitment:audit-backup-retention`):** Database backups use a 14-day rolling retention window (dry-run by default), always preserve the newest valid recovery backup, block pruning when any `LEGAL_HOLD` is active, and require post-restore replay of subsequent `candidate_dsar_anonymized` events before re-opening traffic.
