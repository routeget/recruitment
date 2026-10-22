# Antigravity Execution Directive: Production Super Admin Provisioning and Platform Configuration Centre

**Repository:** `routeget/recruitment`  
**Base branch:** `main` (fetch latest before implementation)  
**Authorized Alpha environment:** https://career.tringers.com  
**Deployment path:** `/home/career/htdocs/career.tringers.com`  
**Objective:** Correct the missing/unclear production platform-administrator provisioning and deliver a real, secure, unified Platform Administration & Configuration Centre. This is an implementation directive, not a request to add documentation only.

## 1. Confirmed baseline and observed gaps

Repository review found:

- `database/seeders/AlphaSafeBootstrapSeeder.php` defines the `platform_superadmin` role and a demo identity `platform.superadmin@tringers.test`, but demo users and tenants are seeded only in `local` and `testing` environments. This is not evidence of a provisioned production Super Admin account.
- The same seeder uses a static demo password for synthetic local/testing users. That password must never be reused or exposed as production credentials.
- `resources/views/layouts/app.blade.php` exposes individual links such as **Tenants & IAM Access**, **AI Gateway & Governance**, **API Platform, Webhooks & MCP**, **Dynamics 365 HR Connector**, and **Operational Diagnostics**. These do not amount to a complete, unified platform configuration centre.
- The navigation currently conditions the Tenants & IAM link on tenant IAM permission or `is_platform_superadmin`; do not assume a menu condition is an authorization control.
- The release report says the tenant IAM/SSO governance console is live. It does not establish which real production user has platform-wide authority or that a full platform settings console exists.
- Microsoft Entra SSO is intentionally disabled until genuine Entra registration credentials and a dedicated test tenant are provisioned. Keep this fail-closed behavior intact.

Treat these as investigation starting points. Fetch current `main`, inspect all relevant models, policies, middleware, controllers, migrations, seeders, views, tests, and live deployment evidence before changing code. Do not infer the production database's current Super Admin state from the repository alone.

## 2. Required outcomes

Deliver both outcomes together:

1. **A verified, securely provisioned production Platform Super Admin account** with clear, auditable bootstrap/recovery procedures.
2. **A dedicated Platform Administration & Configuration Centre** visible to, and usable by, authorized platform administrators and inaccessible to tenant-only administrators, recruiters, candidates, API clients, and unauthenticated visitors.

Do not create or reveal a hard-coded production account, password, recovery token, or secret. Do not create a production administrator automatically just because a migration or ordinary deployment runs.

## 3. Workstream A — Audit current administrator and authorization state

Before modifying the live database:

1. Inspect `User`, `Role`, `TenantMembership`, platform-admin flags/policies, the tenant context middleware, route authorization, and all IAM admin actions.
2. Determine whether `is_platform_superadmin` is a global platform authority flag or merely a display/navigation flag. Establish the authoritative server-side authorization policy and apply it consistently.
3. Review whether `platform_superadmin` role membership is tenant-scoped. A platform administrator must not gain global rights merely by belonging to a tenant role, nor should a tenant administrator gain platform-wide access.
4. Inspect current production user records and role assignments using a read-only, least-privilege query or an approved administrative report. Do not print passwords, password hashes, tokens, secrets, candidate data, or unrelated PII. Report only the minimum needed to establish whether an active platform Super Admin exists (for example, user ID, masked email, active/suspended state, flag/role state, MFA state, and last-login timestamp where available).
5. Compare database state to the deployed code and document discrepancies. Do not seed demo tenants or demo users into production. Do not change existing administrator privileges until the intended authorized account is identified and the owner-approved provisioning path is ready.
6. Add an automated audit/test that detects a missing active platform Super Admin without leaking personal data. Avoid making routine health endpoints reveal account emails or IDs.

If no authorized production Super Admin exists, proceed with the secure provisioning mechanism in Workstream B and clearly record the account as **not provisioned** until the owner completes the controlled setup.

## 4. Workstream B — Secure production Super Admin provisioning

Implement an explicit, operator-controlled CLI provisioning flow compatible with the project's Laravel/PHP versions. Prefer a dedicated Artisan command such as `platform:admin-provision` and a separate, documented recovery procedure.

### Security requirements

- No public registration route, setup wizard, secret URL, query-string token, or browser endpoint may create/promote a platform Super Admin.
- Never create a production Super Admin from `DatabaseSeeder`, `AlphaSafeBootstrapSeeder`, ordinary migrations, scheduled jobs, or an unattended deployment.
- Never embed production email addresses, passwords, temporary passwords, reset tokens, client secrets, or recovery codes in source code, CI variables printed to logs, GitHub artifacts, or documentation.
- The command must require an explicit interactive operator action and safely collect the intended account identifier. Password creation must use a secure prompt or a short-lived, approved password-reset/invitation flow; never echo it or persist it to logs.
- Prefer creating an invitation/reset flow that forces the administrator to set a unique password and enroll in MFA before the first privileged session. If the current authentication stack cannot support that safely, implement and test the minimum secure alternative, document residual risks, and do not claim MFA enforcement unless it is enforced server-side.
- Require recent authentication/authorized host access for any promotion of an existing account; log who performed it, when, target account ID, old/new privilege state, and reason. Do not log secrets.
- Make the command idempotent and fail closed: refuse ambiguous identities, suspended accounts, conflicting accounts, or incomplete security prerequisites. Never silently promote a tenant admin because their email matches a configuration value.
- Provide a separate, auditable revocation/demotion process. Protect against removing the last active platform Super Admin without an explicitly confirmed recovery plan.
- Ensure MFA, session rotation, secure cookies, rate limiting, audit logging, and reauthentication for sensitive changes according to the current auth architecture. Do not add an authentication bypass to facilitate bootstrap.
- Use transactions and locking for account/role/flag changes. Apply only additive migrations where schema changes are necessary.
- The CLI must print a safe result (success/failure and non-sensitive identifier) and never dump the user model or credential fields.
- Never use the synthetic `platform.superadmin@tringers.test` identity or the demo password as the live production administrator. Demo accounts remain restricted to synthetic local/testing environments.

### Acceptance tests

- No production Super Admin is created by a normal deploy, migration, or seeder.
- Unauthenticated HTTP requests cannot create or promote a Super Admin.
- The command refuses missing/ambiguous identifiers and invalid states.
- Authorized provisioning succeeds only through the approved operator flow and is audited.
- Duplicate invocation does not create duplicates or unexpectedly change account privileges.
- Revocation is audited and cannot accidentally lock out every administrator without a deliberate, tested recovery procedure.
- No password, reset token, MFA secret, or credential is present in logs, CLI output, repository, CI artifacts, or audit metadata.
- Tenant admins, recruiters, candidates, and machine principals cannot invoke platform provisioning or platform-admin actions.

## 5. Workstream C — Build the Platform Administration & Configuration Centre

Add a clear top-level navigation group named **Platform Administration** or **Platform Configuration**. Use a dedicated route namespace and controller/policy, for example `/platform-admin` and `/platform-admin/settings`, but follow established route conventions and avoid conflicts with current routes.

This is a platform-wide control plane, distinct from the tenant-scoped **Tenants & IAM Access** console. Label the distinction clearly in the UI. Do not merely relabel existing tenant pages or add nonfunctional links.

### Required sections

Implement functional pages for the settings that are supported by the current application architecture. Where an integration is not provisioned, expose an honest status and safe configuration workflow rather than a fake connected state.

1. **Platform Overview**
   - Platform version/build/deployed SHA where safely available, service readiness, configuration completeness, pending setup tasks, and recent administrative audit events.
   - Do not expose filesystem paths, environment variables, secrets, candidate PII, or internal diagnostics to users without authorization.
2. **Platform Identity & Branding**
   - Platform name, logo/brand assets, public support/contact metadata, and global UI defaults.
   - Keep tenant-specific branding isolated from global branding.
3. **Tenant Lifecycle**
   - List tenants with safe summary metadata, status, configuration readiness, and audited enable/suspend/reactivate operations where supported.
   - No cross-tenant candidate/application records or private documents in this section.
   - Tenant lifecycle actions require confirmation, reason, policy checks, and audit entries.
4. **Platform Users & Roles**
   - List/manage platform administrators and permitted support operators; separate platform-wide roles from tenant memberships.
   - Show the current user's effective platform permissions. Prevent self-escalation and unauthorized privilege assignment.
   - Tenant-specific users and roles should link to the existing tenant IAM interface rather than duplicating or bypassing its rules.
5. **Authentication & Identity Providers**
   - Entra SSO enabled/disabled/configuration status, callback URI, organizational audience policy, approved tenant/connection state, key rotation/secret presence status without displaying secret values, and test/diagnostic status.
   - Keep `MICROSOFT_ENTRA_SSO_ENABLED=false` until valid app registration credentials and a dedicated test tenant are supplied and a real browser flow is verified. No “connected” label based solely on non-empty configuration.
   - Never store or return client secrets in ordinary settings rows or HTML. Use environment/secret-manager references for secret material; mask values and support rotation safely.
6. **AI Provider & Usage Governance**
   - Provider availability, enablement switches, budget/limit policy, safe usage metrics, and provider readiness.
   - Keep external provider keys server-side; never display their values. Keep global policy separate from tenant-specific budgets and kill switches.
7. **API, MCP & Webhook Governance**
   - API-client lifecycle, scopes, webhook delivery health, retry/failure status, endpoint validation status, and safe secret rotation.
   - Machine principals remain separate from human identities. Do not expose bearer tokens or webhook signing secrets after creation.
8. **External Integrations**
   - Dynamics 365 HR integration configuration/readiness, field ownership, safe connectivity diagnostics, and clear state for disabled employee creation.
   - Do not enable live employee creation, real outbound HR writes, or external billing simply by adding configuration UI. Existing approval/safety gates remain mandatory.
9. **Security Policies**
   - Session/MFA requirements, authentication policy, tenant SSO enforcement, retention and privacy settings, rate-limit policy, and safe feature flags that the code genuinely supports.
   - Do not offer a UI toggle for a control that is not enforced by the backend. Mark environment-managed settings read-only where changing them requires a controlled deployment.
10. **Audit & Change History**
    - Append-only records for setting changes, administrator provisioning/revocation, tenant lifecycle actions, authentication changes, provider toggles, and failed/denied admin attempts.
    - Record actor, action, target, timestamp, outcome, reason, and redacted before/after values. Never record secrets or sensitive candidate data.
11. **Operational Readiness**
    - Show truthful health/readiness status and links to existing diagnostics where authorized. Do not allow this UI to replace independent health checks or conceal a degraded dependency.

If a required section is not safe or not yet supported, render a clear `Not configured`, `Environment managed`, or `Not yet implemented` state with a reason. Do not render inert save buttons, fake status indicators, or controls that claim success without persistence and verification.

## 6. Settings architecture and change control

- Inventory existing configuration sources first: environment/config files, database-backed tenant settings, AI provider records, Dynamics configuration, SSO connection records, and feature flags.
- Classify each setting as **environment-managed**, **platform database setting**, **tenant-scoped setting**, or **secret-manager reference**. Document ownership and precedence.
- Use a typed registry/schema for database-backed platform settings with validation, explicit defaults, permitted value ranges, sensitivity classification, and an audit policy. Do not add a generic arbitrary-key/value editor that can mutate runtime behavior without validation.
- Keep tenant-specific configuration tenant-scoped and enforce tenant isolation. A global Super Admin console may administer tenant metadata but must not bypass data-access policies to inspect candidate records.
- Do not persist raw secrets in a generic settings table. For secret values, use the existing Laravel encryption/secret-management mechanism or environment/secret-manager references, with strict access, masking, rotation, and no read-back of plaintext.
- Use CSRF protection, server-side authorization on every route/action, input validation, safe output encoding, rate limiting for sensitive operations, and reauthentication for high-risk changes.
- Use transactions for multi-record changes. Apply optimistic locking/version checks where useful to prevent silently overwriting concurrent administrator changes.
- Require a reason for sensitive changes and record redacted before/after values in the append-only audit log.
- Ensure configuration changes are consumed by the actual runtime services. A persisted value that has no effect must not be presented as operational.
- Avoid writing to `.env` from the web application. Environment-managed settings must be changed through the documented deployment/secret-management process.

## 7. Navigation, usability, and access rules

- Add a clear **Platform Administration** navigation entry only for a server-authorized platform Super Admin (and any separately defined least-privilege platform operations role).
- Add a landing page with status cards, grouped configuration sections, search/filtering where appropriate, explicit save/cancel behavior, validation feedback, and visible audit/change history.
- Preserve existing recruitment workspace navigation for tenant users.
- Hide unauthorized links as a usability measure, but enforce authorization in middleware/policies/controllers for every GET, mutation, export, API endpoint, and nested route.
- Do not trust a client-supplied role, tenant ID, feature flag, route parameter, or hidden form field to establish platform authority.
- A tenant administrator must never obtain platform authority by switching tenants or editing requests.
- API clients/MCP principals and candidates must never access browser platform administration routes.
- Ensure keyboard accessibility, responsive Bootstrap layout consistent with the existing application, and useful empty/configuration states.

## 8. Test plan — required before PR approval

Add/extend PHPUnit feature tests, policy tests, and service tests for:

### Platform Super Admin
- Active platform Super Admin can access the platform admin landing page and only the permitted platform actions.
- Guest receives the correct unauthenticated response.
- Tenant admin, recruiter, candidate, suspended user, and API/MCP principal are denied all platform admin endpoints and mutations.
- A tenant-scoped role alone never grants platform-wide permissions.
- Tenant switching does not alter global platform authority.
- Platform admin actions are audited; denied attempts are auditable where appropriate.
- Privilege escalation, self-escalation, forged IDs, CSRF-sensitive actions, and cross-tenant access are rejected.

### Provisioning
- Normal migrations, seeders, and deployment bootstrap do not create production admins.
- CLI provisioning is explicit, validates identity, refuses ambiguous/unsafe states, handles duplicate execution, and never emits credential material.
- MFA/password-reset requirements are enforced server-side if represented as required.
- Last-admin protection and documented recovery behavior work as designed.

### Configuration centre
- Each section renders for authorized users and is denied to unauthorized personas.
- Valid settings persist and affect the real service; invalid values are rejected with no partial writes.
- Secret values never appear in page HTML, JSON, logs, audit metadata, error messages, or normal list/detail responses.
- Environment-managed values are read-only in the UI.
- Platform-global and tenant-scoped settings remain isolated.
- Sensitive changes require the intended confirmation/reason and produce append-only audit records.
- Entra SSO stays disabled and reports unconfigured until real credentials exist; no mock can make production appear connected.
- AI, webhook, and Dynamics safety gates cannot be bypassed by configuration changes.
- Accessibility and navigation links resolve correctly.

Run targeted tests, the complete PHPUnit suite against supported MariaDB versions, Pint, configured static analysis, `composer audit`, and full GitHub Actions CI. Do not weaken or delete tests to get green CI.

## 9. Deployment and live verification requirements

The only authorized target is `https://career.tringers.com`, application path `/home/career/htdocs/career.tringers.com`.

- Fetch the latest `main`; inspect open PRs and current deployed SHA first. Keep this work on a dedicated branch/PR and do not overwrite another in-progress branch.
- Use additive migrations only. Inspect migration `up()` and `down()` against populated Alpha data. Never run `migrate:fresh`, truncate tables, reset the database, or perform a casual destructive rollback.
- Preserve the live `.env`, candidate documents, user/tenant records, and audit history. Verify a current backup and the documented recovery path before deployment.
- Do not insert demo tenants or synthetic demo users into production as a shortcut.
- Do not claim a production administrator is provisioned until the approved CLI flow has been executed on the host and the resulting account is verified without exposing credentials.
- After CI passes, deploy the exact reviewed/tested commit through the authorized runbook. Record the deployed SHA and migration status.
- Verify with a real browser: unauthenticated access denied; platform Super Admin can sign in and reach the panel; tenant admin and recruiter cannot access it by URL or crafted request; configuration changes persist; audit events are created; secrets remain masked; SSO remains fail-closed without credentials; health/readiness remains correct.
- Verify that the platform navigation is visible to the correct account and absent for tenant-only users. Capture redacted evidence; do not capture secrets, passwords, candidate PII, or private documents.
- If host/browser/operator access is unavailable, finish code, tests, PR, and safe deployment instructions, but explicitly mark deployment/live verification as pending. Do not report success without evidence.

## 10. Required documentation

Update these files after implementation with evidence-backed results:

- `FSD/ALPHA_REQUIREMENTS_TRACEABILITY.md` — platform admin provisioning and configuration-centre requirements, code paths, tests, CI, deployment and live verification.
- `FSD/ALPHA_TEST_AND_RELEASE_REPORT.md` — accurate Super Admin state, navigation, configuration sections, test results, deployed SHA, browser checks, and remaining blockers.
- `FSD/ALPHA_DEPLOYMENT_RUNBOOK.md` — CLI bootstrap, recovery, MFA, revocation, and safe operational procedure. Do not include secrets or actual production credentials.
- A dedicated configuration catalogue under `FSD/` listing each setting, owner/scope, source of truth, sensitivity, validation, runtime consumer, and restart/rotation requirement.
- A completion report under `FSD/` containing commit/PR/CI links, test counts, migration review, deployment SHA, live evidence, and residual risks.

Use truthful status labels: `OPEN`, `IMPLEMENTED IN CODE`, `CI VERIFIED`, `DEPLOYMENT PENDING`, `DEPLOYED — LIVE VERIFICATION PENDING`, and `VERIFIED IN ALPHA`.

## 11. Definition of done

Do not mark this work complete until:

- [ ] Current production administrator state was safely inspected and documented.
- [ ] A controlled CLI provisioning/recovery process exists; no hard-coded production account or password exists.
- [ ] The intended production Super Admin has actually been provisioned through the approved process, or the exact owner/operator action still required is documented.
- [ ] Platform-wide authorization is enforced server-side and isolated from tenant roles and machine identities.
- [ ] The unified Platform Administration & Configuration Centre is functional, navigable, responsive, and properly authorized.
- [ ] Settings have typed validation, correct scope/source of truth, audit history, secret masking, and real runtime effects.
- [ ] SSO, AI, webhook, and Dynamics safety gates remain enforced.
- [ ] Regression tests and full CI pass against supported MariaDB versions.
- [ ] The tested commit is deployed to the authorized Alpha URL and verified in a real browser, or the exact access blocker is recorded without claiming deployment.
- [ ] Documentation reflects evidence, not intention, and contains no secrets or candidate PII.

**Start now:** inspect latest `main`, current PRs/CI/deployed SHA, current routes and policies, the seeder, and current production administrator state. Then implement Workstreams A–C; do not stop after producing a plan or a visual mock-up.
