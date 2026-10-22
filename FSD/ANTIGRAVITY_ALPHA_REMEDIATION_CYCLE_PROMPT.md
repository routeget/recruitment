# Antigravity Prompt: Unified Recruitment AI Alpha Remediation Cycle

You are continuing the existing Alpha application, not starting a new project. Work in routeget/recruitment, starting from the latest main after reviewing CI status. The authorized development environment is reported as https://career.tringers.com, deployed at /home/career/htdocs/career.tringers.com. Independently confirm the active server deployment and current SHA before changing it.

Read first:
- FSD/UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md
- FSD/ALPHA_TEST_AND_RELEASE_REPORT.md
- FSD/ALPHA_DEPLOYMENT_RUNBOOK.md
- FSD/ALPHA_INDEPENDENT_REMEDIATION_AUDIT_2026-10-22.md
- FSD/ALPHA_REQUIREMENTS_TRACEABILITY.md

## Mission

Perform a disciplined test, remediation, regression, and deployment cycle. Reproduce findings, fix root causes, prove each fix with automated tests, deploy each application/runtime remediation to the authorized Alpha server, and verify it in the real browser. Do not stop after writing a report or changing code. Do not claim success without evidence.

## Mandatory rules

1. Treat the audit as a prioritized initial finding register, not proof that all defects have been found.
2. Every application/runtime remediation must be deployed to https://career.tringers.com after it passes tests and review. This is mandatory even for small fixes. Deploy only the tested commit and verify the deployed SHA.
3. After every deployment, run health/readiness checks and verify the affected workflow in a real browser or through appropriate authenticated API tests. Record status codes, result, timestamp, deployed SHA, and redacted evidence.
4. The existing server .env is authoritative. Preserve its database credentials, application key, permissions, and unrelated values. Never print, commit, copy into documentation, or expose secret values. Do not replace it with .env.example.
5. Never reset, truncate, or drop the live Alpha database. Inspect migrations and backups first. Prefer forward-only corrective migrations. Do not run schema rollback on the shared Alpha database without explicit owner approval, a verified restorable backup, and a documented data-loss analysis.
6. The user has explicitly instructed that weak-password and development personal-access-token policy findings may be marked PASS/accepted because this is a live development environment. Apply this narrowly to the intentional synthetic development setup. Do not mark exposed real secrets, authentication bypass, cross-tenant data exposure, privilege escalation, or access to real candidate data as PASS.
7. Dynamics 365 real employee creation must remain disabled unless separately authorized. Never test with real candidate/employee records or a production HR tenant without explicit authorization.
8. Do not delete, weaken, or skip valid tests to get green CI. Do not treat a passing health endpoint as proof of application correctness.
9. Use feature branches and reviewable commits. Keep CI green. Do not force-push or discard unrelated work.
10. All substantial reports, plans, findings, and runbooks belong under FSD. Update traceability and the release report after each increment.

## Start by confirming CI and server baseline

- Inspect current main, open PRs, workflow runs, and the current deployed SHA.
- PR #1 addressed a missing tests/Unit directory that caused PHPUnit to abort before running tests. Verify the fix is present in main and that CI actually executes the full suite. Do not infer success from the PR branch alone.
- Establish server backup status, migration state, health, log baseline, and exact deployment mechanism before touching runtime files.
- Independently verify the live Alpha URL and authenticated routes from the authorized environment. If browser access is unavailable, record the blocker rather than claiming the live site is healthy.

## Priority 1: API-client identity and MCP permission inheritance

Finding: ApiV1AndMcpController::resolveApiAuthContext() authenticates an API client, then selects an arbitrary user by current tenant, falling back to the first user globally. MCP authorization subsequently uses that user's roles and permissions. This can cause a machine client to inherit human privileges and can misattribute actions to an unrelated user.

Required work:
- Remove arbitrary-user and global-user fallback.
- Introduce an explicit tenant-bound machine principal or service identity with defined lifecycle, status, permissions, and audit attribution. Do not pretend a machine call is a human user's action.
- Authorize every MCP tool against the API client's explicit tool-level scopes and the tool's required permission. A generic mcp.use scope must not grant access to every tool.
- Check client status, tenant status, tenant binding, principal binding, scope, and permission on every request.
- Keep human-session authorization separate from API-client authorization.
- Add negative tests proving that a client with mcp.use only cannot call candidate.search, application.get, or any tool whose required scope it lacks. Test tenant mismatch, disabled clients, revoked credentials, and missing principal.
- Ensure responses and audit records never expose another tenant's data or attribute machine calls to an unrelated user.
- Run the full suite and CI, deploy the tested commit, then verify MCP and REST behavior on the live Alpha server.

## Priority 2: Idempotency concurrency and duplicate side effects

Finding: ApiAndMcpPlatformService::executeIdempotent() checks for an existing key, executes the side-effect callback, and inserts the idempotency record afterwards. Two concurrent requests can both execute the callback before one loses the unique-key race.

Required work:
- Implement atomic reservation/processing/completion semantics using a transaction and database-backed concurrency control.
- Make the business mutation and final response record commit atomically where they share a database.
- For the same key and same payload, concurrent calls must return the one committed outcome. For the same key and a different payload, return a deterministic 409 without executing the callback.
- Define safe behavior for in-progress records, timeouts, failed transactions, and expiration.
- Handle expected unique-key races by loading and validating the winning record, not by leaking a generic 500 or duplicating the business action.
- Add deterministic concurrent tests for requisition and candidate creation, asserting exactly one business record. Include same payload, different payload, rollback, and retry cases.
- Run tests, deploy the tested commit, and verify API behavior on the live Alpha server.

## Priority 3: Webhook delivery must not fake success

Finding: ApiAndMcpPlatformService::dispatchSignedWebhook() creates a delivery row with HTTP status 200, status DELIVERED, and a delivered timestamp without making an outbound HTTP request. The UI then claims that a verification ping was delivered.

Required work:
- Implement actual outbound delivery through a queued job with TLS verification, strict timeouts, bounded retries/backoff, response classification, and attempt history. Only mark delivery successful after an actual successful HTTP response.
- Before enabling outbound requests, mitigate SSRF: HTTPS-only by default, reject loopback/private/link-local/multicast/reserved IPs, validate DNS results and redirects, mitigate DNS rebinding, and use a destination allowlist where feasible. Redirects must not bypass validation.
- Encrypt signing secrets at rest, redact them in logs and UI, and support safe rotation.
- Test with a controlled mock receiver, success, timeout, invalid TLS, non-2xx, retry exhaustion, and redirect-to-private-address cases.
- If real delivery is not completed, disable it and show SIMULATED or NOT CONFIGURED. Never label a database record DELIVERED without network delivery.
- Deploy and verify the live UI and relevant routes after remediation.

## Priority 4: /setup/status contract mismatch

Finding: AuthController::setupStatus() returns HTTP 403 unconditionally, while the release report says it returns HTTP 200.

Required work:
- Define the intended safe public contract. Return 200 only if this is a public status endpoint and exposes no sensitive details; otherwise retain 403 and correct the report.
- Add tests for initialized and uninitialized states and prove no public bootstrap action is possible.
- Deploy and verify the actual status code and response on the live Alpha URL. Update the release report to match evidence.

## Priority 5: Database-safe rollback documentation

Finding: The runbook suggests php artisan migrate:rollback --step=1, while the latest migration's down() drops whole tables. This can destroy live Alpha records.

Required work:
- Update FSD/ALPHA_DEPLOYMENT_RUNBOOK.md to prefer application-code rollback without schema rollback, followed by a forward corrective migration.
- Require owner approval and a verified restore plan before any schema rollback or destructive database operation.
- Never run destructive rollback against the shared Alpha database during this cycle.
- Verify that the backup procedure creates a restorable backup without exposing secrets. Documentation-only updates need not be deployed as application code, but must be committed and reflected in the operational runbook.

## Priority 6: Health/readiness semantics

Finding: HealthController::jsonHealth() calculates overall health from database and private storage only, even though it separately reports Redis/cache degradation.

Required work:
- Separate liveness from readiness or include required Redis/queue dependencies in readiness.
- Report degraded/unavailable status truthfully when core operations are impaired, without revealing stack traces or secrets.
- Add tests for database, storage, cache/Redis, and queue failure states.
- Deploy and verify the live health/readiness contract.

## Priority 7: CI database compatibility

The CI workflow provisions MariaDB 11.4 while the reported server uses MariaDB 12.3.3. Review supported versions and add a compatible CI version or matrix. Keep CI isolated from the live Alpha database. Verify migrations and constraints against the intended runtime. Deploy only if application code changes; CI-only changes must still pass CI.

## Required full regression suite

Run and record:
- composer audit
- ./vendor/bin/pint --test
- Full PHPUnit suite against MariaDB
- Targeted tests for each finding above
- Tenant A versus Tenant B isolation across web, API, MCP, document download, exports, and background jobs
- Authenticated versus unauthenticated access and least-privilege roles
- Offer acceptance, HR checklist, explicit handover approval, stale approval invalidation, idempotent outbox, ambiguous timeout reconciliation, and proof that premature employee creation is blocked
- AI kill switch, budget enforcement, redaction, provider failure, and proof that no candidate data is sent when providers are unconfigured
- Real browser checks on the deployed URL for each affected module

Do not claim test coverage that was not run. Preserve failures and explain environment-specific exclusions.

## Per-fix deployment checklist

For every application/runtime fix, append to FSD/ALPHA_TEST_AND_RELEASE_REPORT.md:
- Finding ID and root cause
- Commit SHA and changed paths
- Test commands and actual results
- CI run URL and conclusion
- Backup/migration status, with no secret values
- Deployment timestamp and deployed SHA
- Live HTTPS health and affected-route results
- Browser-tested user journey and result
- Rollback reference and remaining risk

Deploy each tested application/runtime fix to https://career.tringers.com before moving to the next finding. If deployment or browser verification cannot be completed, mark it REMEDIATED IN CODE, DEPLOYMENT PENDING, not CLOSED.

## Final acceptance

The remediation cycle is complete only when all high-severity findings are fixed and verified, CI is green, the full regression suite passes, the live Alpha is at the verified commit, every runtime fix has post-deployment browser evidence, and the release report accurately reflects implemented versus simulated/unconfigured integrations. Do not claim production readiness.
