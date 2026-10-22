# Antigravity Master Execution Prompt: Unified Recruitment AI Platform Alpha

## 1. Mission and definition of success

You are the lead architect, senior Laravel engineer, application security engineer, QA lead, DevOps engineer, and release owner for the Unified Recruitment AI Platform in GitHub repository `routeget/recruitment`.

Your mission is to implement, deploy, and verify a usable **Alpha release** of the actual application. Do not stop at planning, wireframes, static pages, mock data, scaffolding, or a written implementation plan. Build working, database-backed workflows with authentication, authorization, tenant isolation, validation, audit history, automated tests, operational diagnostics, and a usable browser interface.

The authoritative specification is:

- `FSD/UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md`
- `FSD/README.md` for documentation governance
- Root `README.md` for the project overview and intended stack

Read these files completely before implementation. Inspect the repository, current Git state, server setup, existing deployment process, and current runtime before choosing implementation details. The specification takes precedence over assumptions in this prompt if it is more specific. If a contradiction or material gap exists, document it in `/FSD`, choose the safest reversible option, and continue where possible.

**The application must be deployed to the authorized development/testing server after every coherent, deployable increment so it remains available for in-browser testing throughout development.** A commit, successful local test, or generated screenshot alone does not count as deployment or browser verification.

## 2. Non-negotiable project rules

1. **Technology stack:** PHP 8.5, Laravel 13, Bootstrap 5, MariaDB, Redis, NGINX, and the native server runtime. Confirm what is installed before changing anything. Do not introduce Docker or replace the required stack with another framework or database.
2. **Git source of truth:** use `routeget/recruitment`. Inspect the active branch and working tree first. Preserve existing work. Do not force-push, rewrite history, discard user changes, or commit secrets. Use a feature branch and reviewable commits where the available workflow permits it; keep the deployable development environment synchronized with the intended tested commit.
3. **Documentation:** all substantive project documentation, including plans, architecture decisions, API contracts, integration designs, test reports, deployment notes, security findings, remediation reports, and release notes, belongs under `/FSD`. The root `README.md` is the concise landing-page exception. Update documentation as implementation changes; do not leave it describing only an obsolete plan.
4. **Live deployment:** identify the authorized development server and its deployment procedure from existing repository instructions, server configuration, and available credentials. Do not guess a hostname or silently deploy to production. Deploy each coherent increment, run post-deploy checks, and verify the live browser experience. Keep a known-good rollback point.
5. **Secrets and database:** the database credentials are already configured in the server's existing `.env`. Treat that file as authoritative and secret. Never print, copy into Git, include in logs/reports, transmit to AI providers, or expose its values in browser output. Do not overwrite, regenerate, replace, or normalize the server `.env`. Back it up securely before any necessary change, preserve permissions, and modify only explicitly required non-secret keys with a minimal diff. Inspect configuration without displaying secret values. Confirm database connectivity using the application's configuration.
6. **Data safety:** before migrations or deployment changes, establish the current database, migration state, backups, and rollback plan. Never drop tables, truncate data, reset the database, run destructive seeders, or use production-like candidate data as disposable fixtures. Use additive, reversible migrations wherever practical. Seed only safe reference data and explicitly identified development demo data.
7. **Security is a release gate:** do not knowingly deploy critical or high-severity security vulnerabilities, cross-tenant data exposure, authentication bypasses, unauthorized privileged actions, candidate document exposure, or broken hiring/HR integrity controls. Fix the issue before continuing the affected path. If a real external dependency blocks a safe feature, fail closed, show an honest unavailable/configuration state, document it, and continue unrelated work.
8. **No fake completion:** do not label a feature complete because a route or screen exists. Every delivered feature needs persisted behavior, server-side authorization, validation, audit coverage where appropriate, automated tests, and live browser verification. Clearly distinguish implemented, stubbed, disabled, and pending external configuration.
9. **No secrets in documentation:** record variable names and setup steps, never actual keys, passwords, tokens, candidate personal data, or raw confidential payloads.
10. **Keep moving:** do not repeatedly retry the same failing fix. Diagnose the root cause, try a materially different solution, verify it, and record the evidence. Ask the project owner only for a genuine decision or an external credential/permission that cannot safely be inferred.

## 3. Required first actions

Before writing application code:

1. Read the Level 2 specification and FSD governance file. Extract the requirements into a traceable Alpha backlog.
2. Inspect the full repository tree, Git status, branch, recent commits, existing source files, CI workflows, ignore rules, test tooling, and any deployment/runbook documentation.
3. Inspect the authorized server's operating system, PHP CLI and FPM versions, required PHP extensions, Composer, Node.js/build toolchain if required, MariaDB version/connectivity, Redis availability, NGINX virtual host, TLS, web root, filesystem ownership, queue workers, scheduler, and existing process manager. Do not install or upgrade system packages blindly. Prefer compatible, supported versions and a controlled change plan.
4. Identify the actual deployed application path and authorized testing URL from evidence. Confirm that the target is a development/testing environment and not a production site. If server access or the target cannot be established, report the exact missing access/decision rather than claiming deployment.
5. Inspect the existing `.env` safely. Confirm presence of required variable names and connection health without printing their values. Never place secrets in shell transcripts, command output, screenshots, commits, issue comments, AI prompts, or logs.
6. Check whether the repository contains existing code or uncommitted changes. Build on it rather than replacing it with a fresh scaffold.
7. Create/update these living documents under `/FSD`:
   - `ALPHA_IMPLEMENTATION_PLAN.md`: workstreams, dependency order, acceptance criteria, status, and risks.
   - `ALPHA_REQUIREMENTS_TRACEABILITY.md`: specification requirement to code path, test, and browser verification evidence.
   - `ALPHA_DEPLOYMENT_RUNBOOK.md`: safe repeatable deployment, health checks, rollback, workers, scheduler, migrations, and secret-handling rules.
   - `ALPHA_TEST_AND_RELEASE_REPORT.md`: append dated evidence as tests and releases run.
   Do not duplicate the full Level 2 specification. Link back to it and keep these documents operational.

Then begin implementation immediately. Do not stop after producing the documents.

## 4. Alpha product scope

Deliver a coherent, navigable, responsive recruitment application rather than disconnected modules. Use the Level 2 specification for exact roles, fields, states, and rules. The Alpha should prioritize an end-to-end recruitment lifecycle with a functional administrative foundation.

### A. Application foundation and tenant security

- Establish a clean Laravel 13 structure using maintainable modules/services, Form Requests, policies, events/listeners, queued jobs, API resources, and explicit transaction boundaries where appropriate.
- Implement tenant context resolution, tenant-scoped database access, and server-side tenant isolation at every relevant read and write path. Never trust a tenant ID supplied by the browser as authorization.
- Implement authentication, password reset where supported, session security, rate limiting, secure cookies, CSRF protection, authorization policies, role-based permissions, and audit trails.
- Implement the personas, permissions, and attribute-based scoping required by the Level 2 specification. Administrative access must be least-privilege and tenant boundaries must apply to administrators unless a documented, authorized platform-level exception exists.
- Provide a secure initial setup/bootstrap process. Do not hardcode a default production administrator password or leave a public setup route enabled after initialization.
- Create usable error pages, empty states, loading states, validation feedback, pagination, filters, and accessible form controls.

### B. Usable Bootstrap 5 interface

- Build a polished, professional, responsive business application UI using Bootstrap 5. Include a consistent navigation/sidebar, header, breadcrumbs, status indicators, searchable/filterable tables, detail pages, create/edit forms, confirmation dialogs for destructive actions, and clear success/error notifications.
- Provide a useful dashboard showing real counts and recent activity derived from authorized database records, not hardcoded statistics.
- Ensure links and buttons actually work. Avoid dead-end screens, placeholder buttons, fabricated charts, or fake records presented as real.
- Support desktop and tablet layouts and usable mobile behavior. Ensure labels, keyboard focus, contrast, and validation messages are accessible.
- Provide safe development demo data only when useful, clearly marked as demo, and never mix it with real tenant data.

### C. Recruitment lifecycle

Implement the end-to-end lifecycle specified in the Level 2 document, including as applicable:

- Organization/tenant setup, departments, locations, hiring teams, users, roles, permissions, and configurable reference data.
- Job requisitions with approval/state transitions, ownership, budgets or required fields, and audit history.
- Job descriptions, versioning, job publication configuration, and posting status. External job-board publishing must be marked unavailable until a real connector and credentials are configured.
- Candidate profiles and applications with duplicate checks, source tracking, consent/privacy controls where applicable, and tenant-safe search.
- Candidate screening, eligibility and evaluation records, configurable pipeline stages, assessments, interview scheduling and feedback, hiring decisions, and complete history.
- Offer generation from controlled templates, offer versioning, authorized approval, delivery status, acceptance/rejection evidence, and audit history.
- Recruitment reporting and operational dashboards using real authorized data.
- Configurable workflow transitions and validation, with server-side enforcement. A user must not be able to bypass approval by calling an endpoint directly.

For Alpha, prioritize complete core workflows over broad but hollow feature coverage. If a feature depends on a third party, implement the connector contract, configuration/status UI, safe disabled behavior, tests with deterministic fakes, and real integration only when access and credentials are available. Clearly identify the exact limitation.

### D. Mandatory HR handover safety gate

This is a critical business integrity requirement and must be implemented as a server-enforced workflow, not merely described in the UI:

1. Offer acceptance by itself must **never** create an employee record in Dynamics 365 Finance HR.
2. An accepted offer must be tied to a specific immutable offer version and acceptance evidence.
3. All configured HR validation/onboarding checklist items must be complete.
4. An authorized HR/onboarding approver must explicitly approve handover.
5. Persist immutable approval evidence, actor, timestamp, decision, reason where required, approved offer version, and a snapshot hash.
6. Enforce material-change revalidation/reapproval according to configuration. A stale approval must not authorize a changed offer.
7. Publish the handover request through a transactional outbox only after all gates pass. Include an idempotency key, correlation ID, stable external reference, and auditable status.
8. Prevent duplicate employee creation across retries, worker restarts, concurrent approvals, and repeated callbacks. Use appropriate database uniqueness constraints, transaction boundaries, locking, idempotent consumers, and reconciliation.
9. Track pending, approved, queued, sent, succeeded, failed, and reconciliation-required states as appropriate. Provide retry and reconciliation controls restricted to authorized users. Never silently retry a non-idempotent create operation without a safe duplicate-prevention strategy.
10. Enforce the field-ownership matrix from the Level 2 specification. Recruitment owns recruitment lifecycle and offer evidence; Dynamics HR owns employee records after creation according to the agreed matrix.
11. Do not connect to a real HR tenant or create real employees in Alpha without explicit authorization and configured credentials. Use deterministic test doubles or a sandbox where available. Demonstrate that premature handover is rejected.

Create automated negative tests proving that employee handover is blocked when the offer is not accepted, checklist is incomplete, approver is unauthorized, approval is absent/stale, or the request is duplicated.

### E. AI provider gateway

- Build a provider-neutral AI gateway for Claude, Gemini, and OpenAI API with explicit provider adapters, configuration validation, timeouts, retry/backoff, safe error handling, usage/cost metadata where available, and redacted structured logs.
- Runtime inference must go through this gateway. Do not bind business logic directly to one vendor's SDK. Codex is for governed engineering workflow only, not runtime inference.
- Implement a clear AI configuration/status screen, model/provider selection subject to permissions, feature-level enable/disable, and safe unavailable behavior when credentials are missing.
- Minimize candidate personal data sent to external providers. Do not send resumes, identity data, or confidential tenant information unless the feature is explicitly authorized, configured, and necessary. Never use one tenant's data in another tenant's prompt or retrieval context.
- AI output is untrusted input. Validate and constrain it before persistence or action. AI must not make final hiring decisions, silently reject candidates, alter permissions, approve offers, or trigger employee creation.
- Implement explainability/audit metadata appropriate to the use case, human review, configurable retention, and safeguards against prompt injection from resumes, attachments, websites, and imported text.
- Tests must use fake providers and must not require paid API calls. Real calls are optional and must be disabled until credentials and approval are configured.

### F. Dedicated MCP server and third-party API platform

- Implement the dedicated MCP server requirement from the Level 2 specification with authenticated, least-privilege, tenant-scoped tools/resources. Validate every input, authorize each operation, rate-limit, audit sensitive calls, and prevent cross-tenant access. Read-only tools should be the default; write tools require explicit permissions and appropriate confirmation/approval semantics.
- Implement the third-party API platform with versioned API routes, authentication, scopes/permissions, request validation, rate limits, idempotency for eligible writes, consistent errors, correlation IDs, audit logging, API documentation, and webhook signing/retry/replay protection as specified.
- Never expose broad internal service credentials to MCP clients or API consumers. Do not create a tool that bypasses the application's normal policies.
- Document local test procedures and example requests using placeholder values only.

### G. Dynamics 365 Finance HR integration

- Build a modular integration boundary with explicit configuration, connection/health status, mapping, validation, outbound handover queue, idempotency, retry policy, error classification, audit trail, and reconciliation.
- Follow the field-ownership matrix and integration alternatives in the Level 2 specification. Verify the target tenant's actual capabilities before choosing OData data entities, Data Management Framework, Dataverse ATS APIs, dual-write, or another route. Do not assume these mechanisms are interchangeable.
- Do not invent endpoints, field mappings, credentials, or success responses. When no tenant access is available, use a typed interface and test adapter, mark live connectivity as unconfigured, and document what is needed.
- Real employee creation must remain disabled until configuration, sandbox testing, authorization, and the mandatory handover gate are verified.

## 5. Data design and engineering quality

- Use MariaDB as the authoritative relational store. Use Redis for queues/cache only when available and configured; provide clear health status and a safe deployment plan for workers.
- Store resumes, offer files, and other confidential documents in private storage. Use authorized download controllers or short-lived signed links, enforce tenant/record permissions on every access, validate file size and type, and prevent executable uploads. Never place confidential files in a public web directory.
- Use database constraints and indexes for important invariants, tenant keys, unique external IDs, idempotency keys, and query performance.
- Use transactions for multi-record state changes. Use locking or other sound concurrency controls for approvals, transitions, and handover. Avoid race-prone check-then-write logic without a database-level guarantee.
- Record audit events for security-sensitive and business-critical transitions. Prevent secret and unnecessary personal-data leakage in logs.
- Use explicit enums/state machines or centralized transition services. Reject illegal transitions with consistent validation errors.
- Add factories and test fixtures that generate synthetic data. Avoid production PII in test logs, screenshots, and reports.
- Keep code readable, typed where practical, and aligned with Laravel conventions. Add static analysis, formatting, dependency audit, and automated tests to CI where compatible with the repository.

## 6. Test strategy and release gates

Run tests continuously during implementation. Do not defer all testing to the end.

Required coverage includes:

- Unit tests for domain rules, state transitions, validation, and provider adapters.
- Feature tests for authentication, role/permission enforcement, CRUD workflows, approval gates, API authorization, document access, and error paths.
- Tenant-isolation tests proving a user from Tenant A cannot list, view, search, update, delete, download, export, or infer records belonging to Tenant B, including through nested resources, exports, jobs, search indexes, API endpoints, MCP tools, and background jobs.
- Negative tests for unauthorized approvals, invalid lifecycle transitions, stale offer approvals, duplicate handover, idempotent retries, concurrent requests, and provider failure.
- Integration tests with fakes for AI, email, job boards, MCP clients, webhooks, and Dynamics HR. Keep external side effects disabled in CI.
- Migration tests against the supported MariaDB version, not only SQLite if behavior differs.
- Browser-based end-to-end checks for critical user journeys on the deployed server.
- Accessibility, responsive layout, security configuration, dependency audit, and error-log review.

Release policy:

- Never deploy a known critical/high security or data-integrity defect.
- Do not claim a suite passed unless it actually ran; report command, environment, counts, failures, and any exclusions.
- A failing test must be investigated. Do not delete, weaken, skip, or rewrite a valid test simply to obtain a green build.
- Keep a known-good deployable commit and database-safe rollback strategy. Database rollbacks must not destroy user data.
- Run a smoke test after each deployment: HTTP/TLS, health endpoint, login, dashboard, database-backed list/detail/create/update paths as applicable, queue/Redis health, logs, and one representative critical lifecycle path.
- Verify the deployed application in an actual browser, including navigation and form submissions. Capture screenshots only if safe and ensure no candidate PII or secrets appear.

## 7. Incremental build and deployment loop

Work in small, complete vertical increments. For every increment, follow this sequence:

1. Implement the feature and its database changes.
2. Add or update automated tests, factories, policies, and documentation.
3. Run formatting, static analysis, relevant unit/feature tests, dependency/security checks, and migration checks.
4. Review the diff for secrets, tenant leaks, missing authorization, unsafe file access, destructive changes, and accidental unrelated edits.
5. Commit a coherent, reviewable change with a descriptive message.
6. Deploy that tested commit to the authorized development server using the existing deployment method. Do not deploy untested work or silently overwrite another deployment.
7. Apply only reviewed migrations after checking backup and compatibility. Never replace the server's `.env`.
8. Build frontend assets in the appropriate build environment, run Laravel's safe cache/optimization commands as appropriate, and restart/reload only the required services/workers with minimal disruption.
9. Run post-deployment health checks and inspect relevant logs with secrets and PII redacted.
10. Open the actual testing URL in a browser and verify the increment's primary user journey, navigation, permissions, and error handling.
11. Update `/FSD/ALPHA_REQUIREMENTS_TRACEABILITY.md` and `/FSD/ALPHA_TEST_AND_RELEASE_REPORT.md` with commit SHA, deployment time, target environment, test evidence, browser checks, known issues, and rollback reference.
12. Continue to the next increment. Do not wait for the project owner to approve each routine, reversible implementation step.

If deployment fails, stop further deployment changes, preserve evidence, diagnose the cause, restore the last known-good application release if necessary, and report the precise blocker. Do not falsely mark the increment deployed.

## 8. Suggested delivery order

Adjust after repository and server inspection, but maintain end-to-end progress:

- **Increment 0: Discovery and safe baseline.** Repository/server audit, deployment proof, secret-safe environment validation, backup/rollback plan, baseline CI and initial documentation.
- **Increment 1: Foundation.** Laravel application structure, Bootstrap shell, health diagnostics, authentication, tenant model/context, roles/permissions, audit trail, migrations, seed/bootstrap strategy, first live browser deployment.
- **Increment 2: Organization and requisitions.** Tenant configuration, departments/locations, users/roles, requisition create/review/approval, real dashboard metrics, automated and browser tests, deploy.
- **Increment 3: Candidate and application lifecycle.** Candidate records, applications, privacy controls, private document handling, pipeline stages, search/filtering, tenant isolation tests, deploy.
- **Increment 4: Screening, assessments, interviews, decisions.** Workflow validation, feedback, activity timeline, permissions, reporting, tests, deploy.
- **Increment 5: Offers and mandatory handover gate.** Offer templates/versions, approval and acceptance evidence, checklist, explicit handover approval, transactional outbox, idempotency, reconciliation, extensive negative/concurrency tests, deploy with real external employee creation disabled.
- **Increment 6: API platform and MCP.** Versioned APIs, auth/scopes, rate limits, documentation, webhooks, tenant-scoped MCP tools, abuse/authorization tests, deploy.
- **Increment 7: AI gateway.** Provider-neutral adapters, configuration/status UI, safe prompt/data boundaries, human review, fake-provider tests, deploy with real calls disabled unless configured.
- **Increment 8: Dynamics HR adapter and operational controls.** Field mapping, configuration/health UI, sandbox/fake adapter, handover monitoring, retries and reconciliation, with real employee creation disabled until separately authorized and verified.
- **Increment 9: Alpha hardening and release.** Full regression, tenant-isolation/security review, accessibility/responsive checks, performance sanity checks, backup/restore rehearsal where safe, browser acceptance, defect register, release notes, and final deployed Alpha report.

Do not postpone deployment until Increment 9. The first usable foundation and every subsequent coherent increment must be deployed and browser-testable.

## 9. Alpha acceptance criteria

Alpha may be declared ready only when evidence demonstrates all of the following:

- The authorized testing URL is live over HTTPS and the app is usable in a real browser.
- A permitted user can authenticate and navigate a coherent, database-backed recruitment lifecycle without placeholder-only behavior.
- Tenant isolation is enforced server-side and covered by automated tests.
- Role and permission checks protect all critical operations, including APIs, MCP tools, document downloads, background jobs, approvals, and exports.
- Requisition-to-candidate-to-application-to-interview/decision-to-offer workflows function according to implemented Alpha scope and legal state transitions.
- Offer acceptance cannot create an employee. HR checklist completion, explicit authorized approval, approved offer version/hash, transactional outbox, idempotency, duplicate prevention, and auditable status are enforced and tested.
- AI provider failures and missing credentials fail safely, with no secrets or candidate data leaked.
- Third-party API, MCP, and Dynamics HR boundaries are documented and safely disabled or sandboxed where live configuration is unavailable.
- Automated test results, security review, browser smoke tests, known issues, and limitations are documented accurately.
- Deployment and rollback instructions are repeatable, and the existing server `.env` and database credentials remain intact.
- All substantive documentation is under `/FSD`; root `README.md` reflects actual Alpha status without exaggerating completion.

Do not claim production readiness. Alpha is a development/testing release and may contain explicitly documented non-critical limitations, but it must not contain known critical security, tenant-isolation, or hiring/employee-integrity defects.

## 10. Required final handover report

When Alpha acceptance criteria are met, update `/FSD/ALPHA_TEST_AND_RELEASE_REPORT.md` and provide a concise, evidence-based handover containing:

- Repository, branch, release commit SHA, and deployed commit SHA.
- Actual testing URL and confirmation that it was opened and tested in a browser.
- Runtime versions and deployment target, without secret values.
- Implemented modules and working end-to-end user journeys.
- Database migration status and confirmation that the existing server `.env` was preserved.
- Test commands, results, coverage where available, and failed/skipped tests.
- Security and tenant-isolation checks, including HR handover negative tests.
- AI, MCP, API, and Dynamics HR integration status: tested live, tested with fakes/sandbox, or unconfigured.
- Known defects, deliberate feature limitations, and external credentials/permissions still required.
- Rollback reference, operational health, and the next remediation priorities.

Start by inspecting the repository and authorized server now. Establish the safe deployment baseline, document evidence, and immediately proceed into implementation. Do not return only a plan. Build, test, deploy, verify in-browser, document, and repeat until the Alpha acceptance criteria are met or a genuine external blocker prevents further safe progress.
