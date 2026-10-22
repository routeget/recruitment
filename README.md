# Unified Recruitment AI Platform

An AI-enabled, multi-tenant SaaS platform designed to manage the complete recruitment lifecycle, from workforce requisition and job description authoring through candidate sourcing, applications, screening, assessments, interviews, hiring decisions, offer management, and controlled handover to HR systems.

The platform is designed for recruiters, hiring managers, HR teams, candidates, administrators, and authorized integration clients. It combines configurable recruitment workflows, a public career portal, responsible AI assistance, secure APIs, an MCP integration surface, and a dedicated Microsoft Dynamics 365 Finance HR connector.

> **Project status:** Repository and Level 2 specification baseline established. Full-cycle application development is planned. This README describes the target product and intended architecture; it does not claim that listed features have already been implemented or production-verified.

## Product Principles

- **Recruitment platform owns recruitment.** Requisitions, job descriptions, candidate profiles, applications, recruitment history, interviews, hiring decisions, offers, acceptance evidence, HR validation and handover approvals remain authoritative in this platform.
- **Controlled HR handover.** An accepted offer alone must never create an employee record in Microsoft Dynamics 365 Finance HR. Employee creation requires completed configured HR validation and explicit approval by an authorized HR/onboarding approver.
- **AI assists, people decide.** AI output is advisory. Authorized people and application workflows remain in control of hiring decisions, approvals, offers and consequential state changes.
- **Tenant isolation by design.** All tenant data, documents, queues, caches, retrieval, APIs and integrations must enforce server-side tenant and record-level authorization.
- **Integration by contract.** External systems are connected through documented APIs and dedicated adapters, not direct database access or hidden coupling.
- **Full-cycle delivery.** Build and integrate the complete application package in planned phases, continue basic verification throughout development, and conduct a dedicated blocker-remediation and release-validation phase after the full package is assembled. Critical security and data-integrity defects must not knowingly be propagated.

## Technology Stack

| Layer | Selected technology / approach |
|---|---|
| Backend runtime | PHP 8.5 |
| Application framework | Laravel 13 |
| Web UI | Bootstrap 5, responsive and accessible components |
| Primary database | MariaDB with InnoDB |
| Queue and cache | Redis |
| Deployment | Cloud-hosted web application with TLS and environment isolation |
| Application architecture | Modular monolith initially, with independently scalable queue workers and isolated integration services where justified |
| Document storage | Private object storage with controlled access, metadata and retention |
| Search and retrieval | Relational search initially; optional search engine/vector index where justified |
| Runtime AI | Claude, Gemini and OpenAI API behind a provider-neutral AI gateway |
| Engineering AI | Codex may be used in a governed repository, review, CI and deployment workflow; it is not a runtime inference provider |
| External integration | Versioned REST API, webhooks and a dedicated MCP server |
| HR integration | Dedicated Microsoft Dynamics 365 Finance HR integration module |
| Security and quality | Tenant-aware RBAC/ABAC, audit trails, automated tests, static analysis, dependency and secret scanning |

Technology versions, package compatibility, provider capabilities and external API availability must be verified against current official documentation and the actual deployment environment before implementation or release.

## Planned Feature Areas

### 1. Workforce Planning and Requisitions
- Workforce and headcount requests, replacement/new-role classification, department/location, business justification, budget references and target start dates.
- Configurable approval chains, submission and resubmission, version history, holds, cancellation, audit trails and controlled state transitions.
- Traceability from approved requisition to job publication, applications, hiring decision and offer.

### 2. Job Description and Job Management
- Structured job profiles and job descriptions covering responsibilities, qualifications, skills, experience, employment type, location and compensation range.
- AI-assisted drafting and inclusive-language suggestions with missing-information flags.
- Versioning, review, approval, publishing eligibility, expiry, closure and publication history.

### 3. Public Career Portal and CMS
- Responsive, tenant-branded public career pages and content management.
- Job search, filtering, job detail pages, pagination and candidate application entry points.
- Candidate registration, saved jobs, profile management, application submission, withdrawal and permitted application-status visibility.
- SEO metadata, canonical URLs, sitemap, robots directives and JobPosting structured data based only on approved published jobs.
- Accessible navigation, form validation, privacy notices and consent controls.

### 4. Candidate CRM and Application Pipeline
- Candidate profiles, resumes, experience, skills, source attribution, talent pools, communication preferences and duplicate resolution.
- Application intake, required questions, documents, recruiter assignment, configurable stages, stage history, notes and candidate communications.
- Candidate data access/correction/deletion workflows, consent records and configurable retention.
- Strict separation between candidate-visible status and confidential internal recruiter/interviewer information.

### 5. AI-Assisted Recruitment
- Resume/document extraction and normalized profile fields with confidence and provenance.
- Job-related matching, transparent criteria-level evidence, score breakdowns, missing evidence and recruiter review.
- Screening summaries, interview preparation, synthesis of supplied feedback, job-description drafting and communication assistance.
- Provider-neutral routing across Claude, Gemini and OpenAI API, prompt/version registry, structured-output validation, evaluation, usage metering, budgets, fallbacks and operational controls.
- Human oversight, privacy safeguards, fairness evaluation and AI auditability. AI must not autonomously make final hiring/rejection decisions, approve offers or bypass workflows.

### 6. Assessments and Interviews
- Configurable assessments, invitations, attempts, results, validity windows and vendor adapters.
- Interview scheduling, participant assignment, interview packs, structured scorecards, feedback deadlines, reminders and calendar integration adapters.
- Permission-scoped interviewer access, audit history and decision evidence.

### 7. Hiring Decisions and Offer Management
- Evidence-based decision packets, authorized recommendations, decision rationale and approval history.
- Compensation proposals, configurable offer templates, document generation, versioning and approval workflows.
- Secure offer delivery, acceptance, decline, expiry, revocation and e-signature provider adapters.
- Material offer changes must create a new version and trigger configured approval/acceptance invalidation rules.

### 8. HR Validation and Dynamics 365 Finance HR Handover
- Post-offer HR validation case, versioned checklist, required documents, employment-data validation, findings and exception handling.
- Separate explicit approval by an authorized HR/onboarding approver after validation completion.
- Immutable approved handover snapshot, transactional outbox, asynchronous dispatch, idempotency, bounded retries, dead-letter handling and reconciliation.
- Store the returned Dynamics employee/person reference and sanitized integration evidence.
- Dry-run mode and environment-specific configuration.
- **Hard rule:** no employee creation request before an accepted offer, completed required HR validation and explicit authorized approval are all present.

### 9. Workflow and Communications
- Configurable state machines, sequential/parallel approvals, role-based approvers, deadlines, reminders, escalations, rejection reasons and resubmission.
- Email/SMS/provider adapters, localized templates, consent/preferences, delivery tracking and bounce handling.
- Material changes to approved records must create a new version or re-enter the configured approval process.

### 10. Third-Party REST APIs and Webhooks
- Versioned REST APIs documented through OpenAPI.
- API client registration, scopes, tenant binding, credential rotation/revocation, rate limits and audit.
- Idempotency for retryable side effects and consistent machine-readable error responses.
- Signed webhooks, endpoint verification, replay protection, bounded retries, dead-letter state and delivery history.
- Minimal event payloads; never send CV binaries, identity documents, credentials or full candidate profiles in webhooks.

### 11. MCP Server
- Dedicated, separately secured Model Context Protocol server with an explicit allowlist of tools.
- Tool input/output schemas, client authentication, tenant and record-level authorization, scoped permissions, rate limits and audit.
- Tools execute through domain services and workflow guards; no arbitrary SQL, unrestricted database access, generic HTTP proxy or unrestricted candidate export.
- High-impact actions remain behind the same application authorization and approval gates as the web application.

### 12. Reporting and Analytics
- Recruitment funnel, requisition aging, open roles, stage aging, time-to-fill, source quality, assessment/interview completion, offer approval and acceptance metrics.
- HR handover backlog, integration health, AI usage and cost monitoring.
- Role-scoped reporting, defined metric formulas, tenant isolation and safeguards for sensitive or small-group data.
- AI-generated narrative must be grounded in computed data and distinguish facts from generated interpretation.

### 13. Administration, Security and Operations
- Tenant provisioning, organization structure, users, roles, permissions, branding, lookup values, workflow configuration, templates and feature flags.
- AI provider/model/task configuration, API scopes, MCP tool registry, Dynamics field maps, webhook configuration and retention settings.
- Append-only audit events for critical transitions, approvals, privileged actions, AI policy changes and integration attempts.
- Private document storage, encryption, secrets management, safe uploads, rate limits, secure sessions, monitoring, backup/restore and operational runbooks.

## Architecture Overview

The initial architecture is a **Laravel modular monolith** backed by MariaDB and Redis. Queue workers handle long-running work such as document processing, AI requests, notifications, webhooks and external synchronization. Private object storage holds resumes and offer documents. The optional search/vector layer is derived data and must always re-check permissions against authoritative records.

A central AI gateway isolates provider-specific behavior. A dedicated MCP service and versioned API platform provide controlled external access. The Dynamics connector is a separate integration module and never accesses the Dynamics database directly. Remote calls must not be performed while holding long-running database transactions; reliable outbox, idempotency and reconciliation patterns are required for critical integrations.

## Delivery Strategy

1. **Discovery and architecture:** inspect the repository and runtime, confirm constraints, identify dependencies, produce a gap analysis, requirements traceability matrix, module map, threat model and phased plan.
2. **Foundation and core recruitment:** implement tenancy, identity/access control, audit, requisitions, job descriptions, approvals, portal, candidate records and applications.
3. **Recruitment workflows:** implement pipeline, screening, assessments, interviews, communications, hiring decisions and offers.
4. **AI and integration platform:** implement the provider-neutral AI gateway, AI features, REST API, webhooks, MCP and Dynamics connector.
5. **Complete application package:** integrate modules, configuration, migrations, seeders, automated tests, documentation, installation/deployment instructions and operational runbooks.
6. **Dedicated remediation and release validation:** run full regression, security, performance, accessibility, integration and recovery tests; classify and resolve blockers; repeat verification until release criteria are met.

Basic testing and security checks continue during development. Ordinary defects can be tracked for the dedicated remediation phase, but critical security, tenant-isolation and data-integrity defects must not knowingly be propagated. External integrations that cannot be exercised without credentials or a vendor sandbox must be clearly marked as awaiting external validation.

## Documentation Governance

All substantive project documentation must be stored under **[FSD](FSD/README.md)** only. This includes functional/technical specifications, architecture decisions, integration designs, API contracts, data dictionaries, test plans, security assessments, remediation reports, release evidence, deployment guides and operational runbooks.

- [FSD documentation index and governance](FSD/README.md)
- [Level 2 Functional, Technical and Integration Specification v2.0](FSD/UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md)

The repository root README is the project landing page; substantive documentation must not be added to ad hoc documentation folders outside FSD. Never commit secrets, production credentials, real candidate PII or unredacted sensitive logs.

## Current Specification and Important References

The Level 2 specification is the authoritative implementation baseline. Before implementing an external connector, verify current vendor documentation and the target environment's actual API/entity capabilities.

- [Laravel documentation](https://laravel.com/docs)
- [Dynamics 365 Finance and Operations data entity integration](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/data-management-integration-data-entity)
- [Dynamics 365 Finance and Operations integration overview](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/integration-overview)
- [Dynamics 365 Human Resources ATS integration API introduction](https://learn.microsoft.com/en-us/dynamics365/human-resources/hr-admin-integration-ats-api-introduction)
- [Model Context Protocol](https://modelcontextprotocol.io/)
- [OWASP Application Security Verification Standard](https://owasp.org/www-project-application-security-verification-standard/)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)

## Repository

- Repository: [routeget/recruitment](https://github.com/routeget/recruitment)
- Default branch: main
- Documentation location: FSD

**Governing rule:** The recruitment platform owns recruitment. Dynamics 365 Finance HR is a controlled downstream handover. Employee creation is blocked until an accepted offer, completed required HR validation and explicit authorized approval are all present.
