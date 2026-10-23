# Unified Recruitment AI Platform — Alpha Deployment & Operations Runbook

**Document ID:** URAP-ALPHA-RUNBOOK-001  
**Version:** 1.1  
**Status:** Active Operational Runbook  
**Date:** 2026-10-22  
**Owner / Audience:** DevOps, Release Engineering, Security, Support  
**Baseline Dependency:** [Level 2 Specification v2.0](UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md) (`URAP-L2-SPEC-001`) & [Microsoft Entra ID Enterprise SSO Specification](MICROSOFT_ENTRA_ID_ENTERPRISE_SSO_LEVEL_2_SPECIFICATION.md) (`URAP-SSO-ENTRA-L2-001`)

---

## 1. Environment & Target Topology

- **Environment Classification:** Authorized Development / Alpha Testing Server (`APP_ENV=local`, `APP_DEBUG=false`)
- **Public HTTPS URL:** `https://career.tringers.com`
- **Application Root:** `/home/career/htdocs/career.tringers.com`
- **Public Web Root:** `/home/career/htdocs/career.tringers.com/public`
- **Private Document Storage:** `/home/career/htdocs/career.tringers.com/storage/app/private` (never web-accessible)
- **Backups Directory:** `/home/career/backups` (`.env` backups in `/home/career/backups/.env.backup.*`; database SQL dumps in `/home/career/backups/databases/`)
- **Runtime Stack:** Ubuntu 24.04.5 LTS, NGINX 1.28.3 (HTTP/2 + TLS 1.3), PHP 8.5.11 (`php8.5-fpm` & `php8.5-cli`), Laravel `13.38.0`, MariaDB `12.3.3-MariaDB-ubu2404`, Redis `7.0.15` (`127.0.0.1:6379`)
- **Host CLI PATH Prefix:** When executing CLI commands from automated shells, prepend `/home/career/snap/antigravity-cli/common/local/bin` to `PATH`.

---

## 2. Secret & `.env` Protection Rules (Mandatory)

1. **Authoritative `.env`:** `/home/career/htdocs/career.tringers.com/.env` contains pre-configured database (`DB_*`) and Redis (`REDIS_*`) credentials.
2. **Prohibited Actions:**
   - NEVER commit `.env` or any backup of `.env` to Git.
   - NEVER print secret values (`DB_PASSWORD`, `DB_USERNAME`, `APP_KEY`, `REDIS_PASSWORD`, `MAIL_PASSWORD`, `AWS_SECRET_ACCESS_KEY`, `MICROSOFT_ENTRA_CLIENT_SECRET`) in shell output, logs, test reports, or browser pages.
   - NEVER overwrite `.env` with `.env.example`.
3. **Backup Procedure Before Any `.env` or Database Change:**
   ```bash
   cp -p /home/career/htdocs/career.tringers.com/.env /home/career/backups/.env.backup.$(date +%Y%m%d_%H%M%S)
   php -r '
   require "vendor/autoload.php";
   $app = require_once "bootstrap/app.php";
   $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
   $db = config("database.connections.mysql");
   $out = "/home/career/backups/databases/recruitment_" . date("Ymd_His") . ".sql";
   $cmd = sprintf(
       "MYSQL_PWD=%s mariadb-dump --single-transaction --routines --triggers -h %s -P %s -u %s %s > %s",
       escapeshellarg((string) $db["password"]),
       escapeshellarg((string) $db["host"]),
       escapeshellarg((string) $db["port"]),
       escapeshellarg((string) $db["username"]),
       escapeshellarg((string) $db["database"]),
       escapeshellarg($out)
   );
   passthru($cmd, $code);
   exit($code);
   '
   ```

---

## 3. Repeatable Deployment Procedure (Per Increment)

Because `/home/career/htdocs/career.tringers.com` is the live served application directory for `https://career.tringers.com`, every increment must follow this controlled sequence:

1. **Pre-Deploy Verification & Backup:**
   - Confirm `.env` backup and MariaDB SQL dump exist in `/home/career/backups/` and have non-zero size.
2. **Dependency & Asset Build:**
   ```bash
   composer install --no-interaction --prefer-dist --optimize-autoloader
   ```
3. **Additive Database Migrations:**
   - Review pending migrations with `php artisan migrate:status`.
   - Apply additive migrations only:
     ```bash
     php artisan migrate --force
     ```
   - Never run `migrate:fresh`, `migrate:reset`, `db:wipe`, or destructive truncation on the server database.
4. **Reference & Safe Demo Data Seeding:**
   ```bash
   php artisan db:seed --class=AlphaSafeBootstrapSeeder --force
   ```
5. **Cache Clear & Optimization:**
   ```bash
   php artisan optimize:clear
   ```
6. **Post-Deploy Smoke & Browser Verification:**
   - Check `/health` (liveness + readiness) and `/health/ready` (readiness) over HTTPS (`https://career.tringers.com/health` and `https://career.tringers.com/health/ready`).
   - Check `/setup/status` returns HTTP `403` (`PUBLIC_SETUP_LOCKED`).
   - Verify primary user journeys in the live browser at `https://career.tringers.com`.
   - Inspect `/home/career/htdocs/career.tringers.com/storage/logs/laravel.log` for unexpected errors.

---

## 4. Rollback & Emergency Recovery Procedures (`URAP-REM-006`)

### 4.1 Separation of Application Rollback vs. Database Schema Rollback (Mandatory Policy)

- **Application Code Rollback and Database Schema Rollback are separate decisions.**
- **Default Recovery Strategy:** For any shared Alpha/staging/production database containing populated tables, **always default to application-code rollback plus forward-only corrective migrations**.
- **Prohibition on Casual `migrate:rollback`:** Do **NOT** execute `php artisan migrate:rollback` on a populated shared Alpha database as a routine recovery step. Standard Laravel `down()` methods drop entire tables (`Schema::dropIfExists(...)`), which will permanently destroy populated tenant, candidate, audit, or integration records in that migration batch.
- Schema rollback (`migrate:rollback`) or full database restore may only be executed after:
  1. Explicit row-count and data-loss impact analysis on every table affected by the migration batch,
  2. Verification that a current, non-empty SQL dump exists in `/home/career/backups/databases/`, and
  3. Explicit release-owner approval.

### 4.2 Application Code Rollback (Leaves Populated Schema & `.env` Intact)

To revert application code to the last known-good commit SHA without touching `.env`, database tables, or uploaded private files:

```bash
git checkout <LAST_KNOWN_GOOD_COMMIT_SHA>
composer install --no-interaction --prefer-dist --optimize-autoloader
php artisan optimize:clear
```

### 4.3 Forward Corrective Migration or Verified Backup Restore

- If a schema change introduced an issue on populated tables, write and deploy a new **forward corrective migration** (`2026_10_22_00000X_...`) that alters or relaxes the column/index without dropping populated tables.
- If a full database restoration from `/home/career/backups/databases/` is required and approved, test the SQL dump against an isolated temporary verification schema first before restoring to the active database.

### 4.4 Microsoft Entra ID Enterprise SSO Configuration & Emergency Break-Glass

- **Safe Default When Unconfigured:** `MICROSOFT_ENTRA_SSO_ENABLED=false` by default. When disabled or missing `MICROSOFT_ENTRA_CLIENT_ID` / `MICROSOFT_ENTRA_CLIENT_SECRET`, `/login` displays a non-leaky disabled diagnostic state and `/auth/entra/redirect` fails closed with HTTP `503 SSO_NOT_CONFIGURED`.
- **Enabling Microsoft Entra ID SSO:** Once an organizational Microsoft Entra app registration (`organizations` audience, Web redirect URI `https://career.tringers.com/auth/entra/callback`) is provisioned, add `MICROSOFT_ENTRA_SSO_ENABLED=true`, `MICROSOFT_ENTRA_CLIENT_ID`, and `MICROSOFT_ENTRA_CLIENT_SECRET` to `.env` (after backing up `.env`), and register the organization's Entra Directory `tid` in **Admin → Tenants & IAM Access**.
- **Break-Glass Recovery:** If a tenant enables `require_sso_for_tenant = true` and an Entra outage occurs, a Platform Superadmin or operator with CLI access can re-enable `allow_emergency_local_login = true` (`allow_local_fallback = true`) on the tenant connection, which is recorded in the append-only `audit_events` log.

### 4.5 Incident Playbooks

- **AI Provider Outage / Runaway Cost:** Toggle the global AI Kill Switch in **Platform Administration & Configuration Centre → AI Governance** (`ai.global_kill_switch = true`) or tenant-scoped **Admin → AI Governance**. All AI calls immediately fail closed with `AI_GLOBAL_KILL_SWITCH_ACTIVE` while manual recruitment workflows continue uninterrupted.
- **Compromised API Client or MCP Token:** Toggle `api.global_mcp_enabled = false` in **Platform Administration & Configuration Centre → API / MCP & Webhooks** or deactivate/rotate the specific client credential in **Admin → API & MCP Platform** (`is_active = false`); active tokens are immediately rejected with `401 UNAUTHENTICATED_API_CLIENT` or `503 MCP_GLOBALLY_DISABLED`.
- **Stuck or Uncertain Dynamics 365 HR Handover:** Do NOT force a blind re-create. Ensure `dynamics.global_live_writes_blocked = true` in **Platform Administration & Configuration Centre → Integrations** and use **HR Handover → Reconciliation Console** to inspect the `correlation_id`, verify external state, and either link the existing `external_employee_id` or trigger an idempotent retry.

---

## 5. Platform Super Admin Provisioning, Recovery & Revocation Runbook (`PSA-01` / `CFG-01`)

Global Platform Super Admin authority (`is_platform_superadmin = true` AND `platform_role = 'PLATFORM_SUPER_ADMIN'` AND `status = 'ACTIVE'`) is strictly separated from tenant-scoped roles and can **only** be granted via the server CLI over SSH. No public endpoint or tenant admin UI can grant global Super Admin authority.

### 5.1 Read-Only Status Inspection (`platform:admin-status`)

Inspect current active real vs synthetic `.test` Platform Super Admin identities (emails masked, zero secrets printed):

```bash
cd /home/career/htdocs/career.tringers.com
php artisan platform:admin-status
php artisan platform:admin-status --json
```

### 5.2 Provisioning a Real Platform Super Admin (`platform:admin-provision`)

Run interactively over SSH so the password is entered via a hidden terminal prompt (never stored in shell history or process arguments):

```bash
cd /home/career/htdocs/career.tringers.com
php artisan platform:admin-provision \
  --email="owner@your-organization.com" \
  --name="Platform Owner" \
  --operator="ssh-operator-id" \
  --reason="Initial production Platform Super Admin provisioning"
```

- **Password Policy:** Minimum 14 characters including uppercase, lowercase, digit, and special character; rejects known demo passwords and email local-part inclusion.
- **Synthetic Domain Block:** Rejects `.test`, `.example`, `.invalid`, `.localhost`, and disposable email domains.
- **Idempotency:** Safe to re-run; if the account is already an active `PLATFORM_SUPER_ADMIN`, returns `PLATFORM_SUPERADMIN_UNCHANGED` (`exit 0`) without duplicating records.
- **Promoting an Existing Non-Super-Admin Account:** Requires explicit `--promote-existing`.
- **Recovering a Suspended Super Admin Account:** Requires explicit `--recover-suspended` and `--reset-password`.

### 5.3 Revoking or Suspending a Platform Super Admin (`platform:admin-revoke`)

To demote or suspend a Platform Super Admin and immediately invalidate all of their active web sessions:

```bash
cd /home/career/htdocs/career.tringers.com
php artisan platform:admin-revoke \
  --email="former-admin@your-organization.com" \
  --operator="ssh-operator-id" \
  --reason="Offboarding / role rotation" \
  --suspend
```

- **Last-Super-Admin Lockout Safeguard:** Both `platform:admin-revoke` and the web `/platform-admin/admins/{id}/revoke` endpoint refuse to revoke or suspend the final active real Platform Super Admin (`LAST_SUPERADMIN_PROTECTION`). Provision a replacement real Platform Super Admin first before revoking the outgoing administrator.

---

## 6. PA-02 Privacy Hardening & Operational Runbook (`PA-02`)

### 6.1 Persistent Supervised Queue Worker (`recruitment-queue-worker.service`)

Asynchronous jobs (`ProcessResumeParsingJob`, `DeliverSignedWebhookJob`) are processed by a persistent user-level `systemd` service (`/home/career/.config/systemd/user/recruitment-queue-worker.service`, reference unit committed at `deploy/systemd/recruitment-queue-worker.service`) with user lingering enabled (`Linger=yes`):

```bash
systemctl --user status recruitment-queue-worker.service
systemctl --user restart recruitment-queue-worker.service
```

- **Command:** `/usr/bin/php8.4 /home/career/htdocs/career.tringers.com/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600 --timeout=120`
- **Auto-Restart:** `Restart=always`, `RestartSec=5`.
- **Log Output:** `/home/career/htdocs/career.tringers.com/storage/logs/queue-worker.log`.
- **Scheduler Output:** All scheduled commands in `routes/console.php` append output to `/home/career/htdocs/career.tringers.com/storage/logs/scheduler.log`:
  - `recruitment:process-scheduled-parse-retries` (every 5 minutes): transitions retry-exhausted jobs (`attempt_count >= max_attempts`) to `FAILED` (`MAX_RETRIES_EXHAUSTED`), cancels queued/retry-scheduled jobs for non-consented or DSAR-restricted/anonymized candidates (`CONSENT_OR_DSAR_BLOCKED` / `DSAR_ANONYMIZED`), and dispatches eligible retries.
  - `recruitment:expire-past-due-jobs` (hourly).
  - `recruitment:enforce-privacy-retention` (daily at `02:30`): evaluates `retention_expires_at <= now()` (with fallback to `updated_at` cutoff when `retention_expires_at` is null), excludes candidates under `LEGAL_HOLD` / `DISPUTE_ACTIVE` or `legal_hold` / `active_dispute` / `statutory_hold` tags, and idempotently minimizes completed, reconciled `HrHandover` snapshots.
  - `recruitment:audit-backup-retention` (daily at `03:00`, dry-run by default).

### 6.2 Secret & Backup File Permissions (`0700` / `0600`)

All environment secret files and database SQL dumps must maintain strict owner-only permissions (`career:career`):

- `/home/career/htdocs/career.tringers.com/.env` -> `0600`
- `/home/career/backups` and `/home/career/backups/databases` -> `0700`
- `/home/career/backups/.env.backup.*` and `/home/career/backups/databases/*.sql` -> `0600`

### 6.3 Database Backup Inventory, 14-Day Rolling Retention & Post-Restore DSAR Replay Procedure

- **Current Verified Baseline Backup (`PA-02`):**
  - `/home/career/backups/databases/recruitment_pa02_20261023_095500.sql` (`185,475` bytes, `0600`, `sha256` prefix `ac65098c30028240...`, 58 tables, 14 migrations including `2026_10_23_000011_make_resume_parse_jobs_document_nullable_for_dsar_scrubbing`).
  - Verified via full isolated restoration rehearsal into a temporary `--skip-networking` MariaDB 12.3.3 instance (`restored_table_count = 58`, `restored_migration_count = 14`, `resume_parse_jobs.candidate_document_id` `is_nullable = YES`).
- **Backup Retention Policy:**
  - Rolling retention window: **14 days** (`BACKUP_RETENTION_DAYS=14`), audited daily by `php artisan recruitment:audit-backup-retention` (dry-run by default).
  - Safeguards: Never deletes the newest valid recovery backup; blocks `--prune-approved` pruning whenever any candidate has an active `LEGAL_HOLD` or `DISPUTE_ACTIVE` status.
- **Mandatory Post-Restore DSAR & Privacy Replay Procedure:**
  - Never edit historical SQL backup files in place.
  - Whenever a database backup is restored during disaster recovery, before re-opening public or tenant traffic:
    1. Run `php artisan migrate --force` to apply all current schema migrations.
    2. Re-apply any `candidate_dsar_anonymized` events recorded after the backup timestamp by invoking `CandidateAndApplicationService::updateCandidateConsentAndDsar(..., ['consent_given' => false, 'dsar_status' => 'ANONYMIZED'])`.
    3. Run `php artisan recruitment:process-scheduled-parse-retries` and `php artisan recruitment:enforce-privacy-retention` to cancel any stale retries and minimize completed HR handover snapshots.

### 6.4 Fail-Closed External AI Privacy & Zero-Data-Retention (ZDR) Gate

- External cloud AI providers (`claude`, `gemini`, `openai`) are **blocked fail-closed** for all candidate PII tasks (`resume.extract`, `resume_extraction`, `candidate.match`, `candidate.match.explanation`, `candidate_screening`) unless `AiProviderConfig::evaluateCandidatePrivacyEligibility()` verifies a structured `settings.privacy_attestation` with `status = 'VERIFIED'`, non-empty `attestation_source`, `dpa_reference`, `zdr_or_retention_reference`, `verifier_identifier`, `verified_at`, un-expired `expires_at`, matching `approved_tasks` and `approved_models`, `redaction_enabled = true`, and non-contradictory `provider_privacy_settings` (`allow_model_training = false`, `training_opt_out_verified = true`, `zdr_enabled = true`, `retention_days = 0`, plus `store_completions = false` for OpenAI, `billing_tier = PAID_ENTERPRISE|VERTEX_AI_ENTERPRISE` & `project_logging_disabled = true` for Gemini, and `commercial_terms_verified = true` & `zdr_org_enabled = true` for Claude).
- Outbound OpenAI Chat Completions requests explicitly include `"store": false`.
- Outbound external AI prompts for resume extraction and matching strip direct contact identifiers (`[REDACTED_EMAIL]`, `[REDACTED_PHONE]`, `[REDACTED_URL]`, `[REDACTED_SSN]`, `[REDACTED_SECRET]`) and wrap minimized qualifications in `<minimized_candidate_qualifications>`, while locally extracted contact fields are merged back into the candidate's structured profile locally.

