# Platform Super Admin & Unified Configuration Centre Report

**Document ID:** `FSD-SUPERADMIN-CONFIG-CENTRE-2026-10-22`  
**Status:** `IMPLEMENTED IN CODE` / `CI VERIFIED` / `DEPLOYED` / `LIVE VERIFIED` (with live real production Super Admin provisioning documented as operator-run CLI procedure awaiting owner identity input)  
**Authoritative Directive:** `FSD/ANTIGRAVITY_PLATFORM_SUPERADMIN_AND_CONFIGURATION_CENTRE_DIRECTIVE.md`  
**Catalogue Reference:** `FSD/PLATFORM_CONFIGURATION_CATALOGUE.md`  
**Date:** `2026-10-22`

---

## 1. Initial Production Identity & Architecture Audit Findings

A read-only inspection of the live database and codebase on `/home/career/htdocs/career.tringers.com` established the following baseline before changes:

1. **Administrator Identity State (`php artisan platform:admin-status --json`)**:
   - Total `users` records: `8`
   - Accounts with `is_platform_superadmin = 1`: `1` (`User #8`, masked email `pl***@tringers.test`, seeded by `AlphaSafeBootstrapSeeder` for local/demo testing)
   - Provisioned real (non-`.test`) Platform Super Admin accounts: `0` (`real_superadmin_provisioned: false`)
2. **Architectural Gaps Identified and Remediated**:
   - `is_platform_superadmin` was previously listed in `User::$fillable`, creating a mass-assignment surface if any controller passed unfiltered attributes to `User::create()` or `$user->fill()`.
   - `AlphaSafeBootstrapSeeder` seeded a role with slug `platform_superadmin` inside the `roles` table, and `/admin/tenants-iam` (`AuditGovernanceController`) and `/admin/sso/entra` (`EntraSsoController`) allowed tenant administrators to select any role from `roles`—meaning a tenant administrator could assign the `platform_superadmin` role slug in `tenant_memberships`.
   - Platform administration was split across tenant-scoped pages (`/admin/tenants-iam`, `/admin/sso/entra`, `/admin/ai-governance`, `/admin/integrations/dynamics`, `/admin/api-mcp`, `/admin/audit-security`) with no unified global `/platform-admin` control plane and no persisted `platform_settings` table.

---

## 2. Workstream A & B — Real Platform Super Admin Separation & CLI Provisioning

### 2.1 Separation of Global Authority vs Tenant Roles
- **`app/Models/User.php`**:
  - Removed `is_platform_superadmin`, `platform_role`, `platform_admin_granted_at`, `platform_admin_granted_by`, and `platform_admin_reason` from `User::$fillable`.
  - Added `User::isActiveAccount()` (`status === 'ACTIVE'`), `User::isActivePlatformSuperAdmin()` (`isActiveAccount() && is_platform_superadmin === true && platform_role === 'PLATFORM_SUPER_ADMIN'`), `User::isSyntheticDemoIdentity()` (detects `.test`, `.example`, `.invalid`, `.localhost`), and `User::maskedEmail()`.
  - A tenant membership with `role.slug = 'platform_superadmin'` never grants `User::isActivePlatformSuperAdmin()`.
  - `AuditGovernanceController::addMember()` and `EntraSsoController::saveConfiguration()` explicitly block assigning the `platform_superadmin` role slug (`403` / `422`), and `resources/views/admin/tenants_iam.blade.php` filters `platform_superadmin` out of tenant member dropdowns.
  - Suspended accounts (`status !== 'ACTIVE'`) are immediately rejected at login (`AuthController::login()`) and evicted on active sessions (`EnforceTenantAndSecurityContext`).

### 2.2 CLI-Only Provisioning, Status, and Revocation Commands
Implemented in `app/Services/PlatformAdminService.php` and three Artisan commands:

1. **`php artisan platform:admin-status [--json]`** (`app/Console/Commands/PlatformAdminStatusCommand.php`)
   - Read-only sanitized inspection of active real Super Admins, synthetic `.test` demo accounts, suspended accounts, and masked emails. Never prints passwords, hashes, or session tokens.
2. **`php artisan platform:admin-provision`** (`app/Console/Commands/PlatformAdminProvisionCommand.php`)
   - Validates organizational email format and rejects synthetic domains (`.test`, `.example`, `.invalid`, `.localhost`) and disposable email providers.
   - Enforces strong password policy (minimum 14 characters, uppercase, lowercase, digit, symbol, and blocklist against `PlatformAdmin#2026!`, `DemoPass#2026!`, or email local-part inclusion) via interactive hidden prompt or `--password-stdin`.
   - Idempotent: re-running for an already active Platform Super Admin returns `UNCHANGED` (`exit 0`) and records an `AuditEvent` without duplicating records or rotating the password unless `--reset-password` is passed.
   - Requires explicit `--promote-existing` to elevate an existing non-Super-Admin user (preventing silent privilege escalation).
   - Requires explicit `--recover-suspended` to reinstate a `SUSPENDED` account.
   - Writes an append-only `AuditEvent` (`PLATFORM_SUPERADMIN_PROVISIONED` or `PLATFORM_SUPERADMIN_PROVISION_DENIED`) with operator identifier, reason, and metadata (`password_redacted: true`).
3. **`php artisan platform:admin-revoke`** (`app/Console/Commands/PlatformAdminRevokeCommand.php`)
   - Revokes global Super Admin authority or suspends the account (`--suspend`), invalidates active database sessions, and records `PLATFORM_SUPERADMIN_REVOKED`.
   - Enforces **Last-Super-Admin Lockout Protection**: refuses to revoke or suspend the final active real Platform Super Admin (`LAST_SUPERADMIN_PROTECTION`) and commits a `DENIED` `AuditEvent`.

---

## 3. Workstream B & C — Unified Platform Administration & Configuration Centre (`/platform-admin`)

### 3.1 Server-Side Authorization & Concurrency Controls
- Protected by middleware alias `platform.superadmin` (`app/Http/Middleware/RequirePlatformSuperAdmin.php`) and `Gate::define('platform-superadmin')` (`app/Providers/AppServiceProvider.php`).
- Unauthenticated requests redirect to `/login` (`302`) or receive `401` JSON; authenticated non-Super-Admin users (`tenant_admin`, `recruiter`, `candidate`, tenant-role-only `platform_superadmin`, suspended users, and API/MCP bearer tokens) receive `403 PLATFORM_SUPERADMIN_REQUIRED` and generate a `PLATFORM_ADMIN_ACCESS_DENIED` audit event.
- Backed by `platform_settings` (`app/Models/PlatformSetting.php`) and `PlatformConfigService::REGISTRY` (`app/Services/PlatformConfigService.php`) with:
  - Strict allowlist validation per setting key and type (`string`, `int`, `bool`, `email`).
  - Optimistic locking via `expected_version` (rejecting stale concurrent edits with `OPTIMISTIC_LOCK_CONFLICT`).
  - Mandatory justification reason (`min 10 chars`) for high-risk governance settings (`requires_reason = true`), recording structured `old_value` and `new_value` in `audit_events`.
  - Secret protection: secret values are never stored in `platform_settings` or rendered in the UI; integration cards display boolean configuration state (`CONFIGURED` vs `NOT CONFIGURED`) and last-updated metadata only.

### 3.2 Eleven Functional Control-Plane Sections & Live Runtime Consumers
| Section Slug | Route / Action | Runtime Consumer |
|---|---|---|
| `overview` | `GET /platform-admin?section=overview` | Executive KPIs, active real vs demo Super Admin posture, tenant health, and live runtime probes |
| `branding` | `POST /platform-admin/settings/branding` | `EnforceTenantAndSecurityContext` shares `platformBranding` (`display_name`, `brand_code`, `support_email`, `career_portal_banner`) rendered in `layouts/app.blade.php` and `careers/index.blade.php` |
| `tenants` | `POST /platform-admin/tenants`, `POST /platform-admin/tenants/{id}/lifecycle`, `POST /platform-admin/switch-tenant` | Creates tenants with initial `tenant_admin`, suspends/activates/archives tenants, and switches Super Admin active tenant context |
| `admins` | `POST /platform-admin/admins/{id}/revoke` | Lists all global Super Admins with masked emails, provenance, MFA state, and enforces last-Super-Admin lockout protection on web revocation/suspension |
| `auth-sso` | `POST /platform-admin/settings/auth-sso` | `AuthController::login()` reads `security.login_max_attempts_per_minute`; displays per-tenant Entra SSO status without exposing client secrets |
| `ai-governance` | `POST /platform-admin/settings/ai-governance` | `AiGatewayService::invokeStructuredTask()` enforces `ai.global_kill_switch`, `ai.global_monthly_budget_cap_minor`, `ai.mandatory_pii_redaction`, and `ai.default_primary_provider` |
| `api-mcp` | `POST /platform-admin/settings/api-mcp` | `ApiV1AndMcpController` enforces `api.global_mcp_enabled` (`503 MCP_GLOBALLY_DISABLED`); `ApiAndMcpPlatformService` enforces `webhooks.global_delivery_enabled` and `webhooks.max_subscriptions_per_tenant` |
| `integrations` | `POST /platform-admin/settings/integrations` | `DynamicsHrConnectorService::triggerOutgoingSync()` enforces `dynamics.global_live_writes_blocked` (`BLOCKED_BY_PLATFORM_SAFETY_GATE`) |
| `security` | `POST /platform-admin/settings/security` | Configures `security.session_idle_timeout_minutes` and `security.require_reason_for_sensitive_changes` |
| `audit` | `GET /platform-admin?section=audit` | Filterable global audit & configuration history viewer with `old_value` -> `new_value` diffs and justification reasons |
| `readiness` | `GET /platform-admin?section=readiness` | Live MariaDB, Redis, storage, queue backlog, failed job, and Super Admin provisioning readiness checks |

---

## 4. Verification Summary

- **Code Style**: `./vendor/bin/pint --test` — `PASS` (128 files).
- **Automated Test Suite**: `./vendor/bin/phpunit` — `33 tests, 515 assertions, 0 failures, 0 errors` (including `tests/Feature/PlatformSuperAdminAndConfigCentreTest.php` with `4 tests, 136 assertions`).
- **Dependency Vulnerability Audit**: `composer audit` — `0 advisories found`.

---

## 5. Phase 2 Read-Only Synthetic Identity Audit & Retirement Safeguards

### 5.1 Sanitized Audit of All Existing User Accounts (`users` table)

| User ID | Masked Email | Account Status | Global Super Admin (`is_platform_superadmin`) | Synthetic Domain | Tenant Memberships (`tenant:role(status)`) | Remediation / Disposition |
|---|---|---|---|---|---|---|
| `1` | `ad***@acme-demo.test` | `ACTIVE` | `NO` | `YES` (`.test`) | `acme-demo:tenant_admin(ACTIVE)` | Contained tenant-scoped demo persona (`acme-demo` only); zero global authority |
| `2` | `re***@acme-demo.test` | `ACTIVE` | `NO` | `YES` (`.test`) | `acme-demo:recruiter(ACTIVE)` | Contained tenant-scoped demo persona (`acme-demo` only); zero global authority |
| `3` | `ma***@acme-demo.test` | `ACTIVE` | `NO` | `YES` (`.test`) | `acme-demo:hiring_manager(ACTIVE)` | Contained tenant-scoped demo persona (`acme-demo` only); zero global authority |
| `4` | `hr***@acme-demo.test` | `ACTIVE` | `NO` | `YES` (`.test`) | `acme-demo:hr_validator(ACTIVE)` | Contained tenant-scoped demo persona (`acme-demo` only); zero global authority |
| `5` | `hr***@acme-demo.test` | `ACTIVE` | `NO` | `YES` (`.test`) | `acme-demo:hr_approver(ACTIVE)` | Contained tenant-scoped demo persona (`acme-demo` only); zero global authority |
| `6` | `au***@acme-demo.test` | `ACTIVE` | `NO` | `YES` (`.test`) | `acme-demo:auditor(ACTIVE)` | Contained tenant-scoped demo persona (`acme-demo` only); zero global authority |
| `7` | `ad***@globex-demo.test` | `ACTIVE` | `NO` | `YES` (`.test`) | `globex-demo:tenant_admin(ACTIVE)` | Contained tenant-scoped demo persona (`globex-demo` only); zero global authority |
| `8` | `pl***@tringers.test` | `ACTIVE` (Pending Owner Provisioning) | `YES` | `YES` (`.test`) | `acme-demo:platform_superadmin(ACTIVE)` | **Retire via `php artisan platform:admin-revoke --suspend` immediately after real owner Super Admin is provisioned** |

### 5.2 Seeder & Login Hardening Against Synthetic Admin Re-Enablement
- **`database/seeders/AlphaSafeBootstrapSeeder.php`**: Hardened so that if a real non-synthetic Platform Super Admin already exists (`$realSuperAdminExists === true`) OR if `platform.superadmin@tringers.test` has been revoked/suspended (`status !== 'ACTIVE'` or `is_platform_superadmin === false`), re-running `AlphaSafeBootstrapSeeder` will **never** re-activate the account or re-grant `is_platform_superadmin = true`.
- **`app/Http/Controllers/AuthController.php`**: Filtered `demoPersonas` on `/login` with `->where('status', 'ACTIVE')` so retired/suspended synthetic accounts are automatically removed from the login quick-fill panel.

### 5.3 Two-Step Operator Sequence (Provision Real Owner -> Retire Synthetic Admin)
```bash
cd /home/career/htdocs/career.tringers.com

# Step 1: Provision the real organizational Platform Super Admin (interactive hidden password prompt)
php artisan platform:admin-provision \
  --email="<REAL_OWNER_ORGANIZATIONAL_EMAIL>" \
  --name="<REAL_OWNER_FULL_NAME>" \
  --operator="<AUTHORIZED_OPERATOR_ID>" \
  --reason="Initial production Platform Super Admin provisioning"

# Step 2: Verify real Super Admin is active (must exit 0)
php artisan platform:admin-status --json --require-real

# Step 3: Retire and suspend the synthetic bootstrap Super Admin (pl***@tringers.test)
php artisan platform:admin-revoke \
  --email="platform.superadmin@tringers.test" \
  --operator="<AUTHORIZED_OPERATOR_ID>" \
  --reason="Retirement of synthetic bootstrap/test administrator from production" \
  --suspend

# Step 4: Confirm final sanitized state (active_real_superadmin_count=1, active_demo_superadmin_count=0, suspended_superadmin_count=1)
php artisan platform:admin-status --json --require-real
```

