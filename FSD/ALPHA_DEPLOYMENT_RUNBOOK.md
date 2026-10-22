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

- **AI Provider Outage / Runaway Cost:** Toggle the global or provider-specific AI Kill Switch in **Admin → AI Governance** (`kill_switch_engaged = true`). All AI calls immediately fail closed with `409 AI_KILL_SWITCH_ENGAGED` while manual recruitment workflows continue uninterrupted.
- **Compromised API Client or MCP Token:** Deactivate or rotate the client credential in **Admin → API & MCP Platform** (`is_active = false`); active tokens are immediately rejected with `401 UNAUTHENTICATED_API_CLIENT`.
- **Stuck or Uncertain Dynamics 365 HR Handover:** Do NOT force a blind re-create. Use **HR Handover → Reconciliation Console** to inspect the `correlation_id`, verify external state, and either link the existing `external_employee_id` or trigger an idempotent retry.
