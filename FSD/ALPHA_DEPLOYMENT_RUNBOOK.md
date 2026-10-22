# Unified Recruitment AI Platform — Alpha Deployment & Operations Runbook

**Document ID:** URAP-ALPHA-RUNBOOK-001  
**Version:** 1.0  
**Status:** Active Operational Runbook  
**Date:** 2026-10-22  
**Owner / Audience:** DevOps, Release Engineering, Security, Support  
**Baseline Dependency:** [Level 2 Specification v2.0](UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md) (`URAP-L2-SPEC-001`)

---

## 1. Environment & Target Topology

- **Environment Classification:** Authorized Development / Alpha Testing Server (`APP_ENV=local`, `APP_DEBUG=false`)
- **Public HTTPS URL:** `https://career.tringers.com`
- **Application Root:** `/home/career/htdocs/career.tringers.com`
- **Public Web Root:** `/home/career/htdocs/career.tringers.com/public`
- **Private Document Storage:** `/home/career/htdocs/career.tringers.com/storage/app/private` (never web-accessible)
- **Backups Directory:** `/home/career/backups`
- **Runtime Stack:** Ubuntu 24.04.5 LTS, NGINX (HTTP/2 + TLS 1.3), PHP 8.5.11 (`php8.5-fpm` & `php8.5-cli`), MariaDB `12.3.3-MariaDB-ubu2404`, Redis (`127.0.0.1:6379`)

---

## 2. Secret & `.env` Protection Rules (Mandatory)

1. **Authoritative `.env`:** `/home/career/htdocs/career.tringers.com/.env` contains pre-configured database (`DB_*`) and Redis (`REDIS_*`) credentials.
2. **Prohibited Actions:**
   - NEVER commit `.env` or any backup of `.env` to Git.
   - NEVER print secret values (`DB_PASSWORD`, `DB_USERNAME`, `APP_KEY`, `REDIS_PASSWORD`, `MAIL_PASSWORD`, `AWS_SECRET_ACCESS_KEY`) in shell output, logs, test reports, or browser pages.
   - NEVER overwrite `.env` with `.env.example`.
3. **Backup Procedure Before Any `.env` Edit:**
   ```bash
   cp -p /home/career/htdocs/career.tringers.com/.env /home/career/backups/.env.backup.$(date +%Y%m%d_%H%M%S)
   ```

---

## 3. Repeatable Deployment Procedure (Per Increment)

Because `/home/career/htdocs/career.tringers.com` is the live served application directory for `https://career.tringers.com`, every increment must follow this controlled sequence:

1. **Pre-Deploy Verification & Backup:**
   - Verify `.env` backup exists in `/home/career/backups/`.
   - Dump non-destructive schema/data backup before structural migrations when tables exist:
     ```bash
     php artisan schema:backup-check # or mysqldump to /home/career/backups/
     ```
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
   php artisan view:cache
   ```
6. **Post-Deploy Smoke & Browser Verification:**
   - Check `/health` endpoint over HTTPS (`https://career.tringers.com/health`).
   - Verify primary user journeys in the live browser at `https://career.tringers.com`.
   - Inspect `/home/career/htdocs/career.tringers.com/storage/logs/laravel.log` for unexpected errors.

---

## 4. Rollback & Emergency Recovery Procedures

### 4.1 Application Code Rollback
To revert to the last known-good commit SHA without touching `.env` or uploaded private files:
```bash
git checkout <LAST_KNOWN_GOOD_COMMIT_SHA>
composer install --no-interaction --prefer-dist --optimize-autoloader
php artisan optimize:clear
```

### 4.2 Database Migration Rollback
All Alpha migrations are written as additive, reversible migrations. If the latest migration batch must be rolled back without data loss:
```bash
php artisan migrate:rollback --step=1
```

### 4.3 Incident Playbooks
- **AI Provider Outage / Runaway Cost:** Toggle the global or provider-specific AI Kill Switch in **Admin → AI Governance** (or set `ai_kill_switch = true` in `tenant_settings` / `ai_providers`). All AI calls immediately fail closed with `PROVIDER_UNAVAILABLE` while manual recruitment workflows continue uninterrupted.
- **Compromised API Client or MCP Token:** Revoke or rotate the client credential in **Admin → API & MCP Platform**; active tokens are immediately rejected with `AUTHENTICATION_REQUIRED`.
- **Stuck or Uncertain Dynamics 365 HR Handover:** Do NOT force a blind re-create. Use **HR Handover → Reconciliation Console** to inspect the `correlation_id`, verify external state, and either link the existing `external_employee_id` or trigger an idempotent retry.
