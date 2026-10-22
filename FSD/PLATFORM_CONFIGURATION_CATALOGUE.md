# Platform Configuration Catalogue — Unified Recruitment AI Platform (Alpha)

**Document ID:** `URAP-FSD-CONFIG-CAT-v1.0`  
**Status:** Authoritative Runtime Configuration Registry  
**Date:** 2026-10-22  
**Registry Implementation:** [`App\Services\PlatformConfigService`](../app/Services/PlatformConfigService.php) & [`App\Models\PlatformSetting`](../app/Models/PlatformSetting.php)

---

## 1. Configuration Governance & Precedence Model

All platform and tenant settings in the Unified Recruitment AI Platform are classified into four strict governance tiers. Arbitrary key-value editing and web-initiated writes to `.env` are prohibited.

| Classification | Source of Truth | Mutability in Configuration Centre | Secret Handling & Audit Policy |
|---|---|---|---|
| `PLATFORM_DB_SETTING` | `platform_settings` table (allowlisted in `PlatformConfigService::REGISTRY`) | **Editable** by active `Platform Super Admin` (`platform.superadmin` middleware) with optimistic locking (`expected_version`) | Non-secret governance/operational values only; every mutation requires validation and writes an append-only `AuditEvent` (`platform_config.section_updated`) with `before`, `after`, `version`, and `reason`. |
| `ENVIRONMENT_MANAGED` | Server `.env` / OS environment variables (`config/*.php`) | **Read-Only** in UI | Displayed as effective boolean/enum/mode status; requires controlled host deployment runbook change and service reload. |
| `SECRET_ENV_REFERENCE` | Server `.env` / Secret Manager (`config/*.php`) | **Read-Only (Masked Presence Only)** | **Never** stored in `platform_settings` or returned in HTML, JSON, logs, exceptions, or audit payloads. UI displays only `CONFIGURED (MASKED)` or `NOT_SET`. |
| `TENANT_SCOPED_SETTING` | `tenants`, `ai_provider_configs`, `dynamics_integration_configs`, `external_identity_connections`, `webhook_subscriptions` | Managed per tenant (with platform-level emergency kill-switches & floors in `/platform-admin`) | Webhook `signing_secret` encrypted at rest via `APP_KEY`; API client secrets stored as SHA-256 hashes; tenant budgets capped by platform ceiling. |

---

## 2. Allowlisted Platform Database Settings (`PLATFORM_DB_SETTING`)

Every setting below is defined in [`PlatformConfigService::REGISTRY`](../app/Services/PlatformConfigService.php), validated server-side before persistence, versioned for optimistic concurrency control, and consumed directly by runtime services.

| Setting Key | Section | Type & Default | Sensitivity | Validation Rules | Reason Required? | Runtime Consumer(s) | Restart / Rotation Requirement |
|---|---|---|---|---|---|---|---|
| `platform.display_name` | `branding` | `string` (`"Unified Recruitment AI Platform"`) | `PUBLIC` | `required\|string\|min:3\|max:100` | No | `EnforceTenantAndSecurityContext`, `layouts/app.blade.php`, `careers/index.blade.php` | Immediate runtime effect |
| `platform.brand_code` | `branding` | `string` (`"URAP"`) | `PUBLIC` | `required\|string\|min:2\|max:12\|alpha_num` | No | `EnforceTenantAndSecurityContext`, `layouts/app.blade.php` | Immediate runtime effect |
| `platform.support_email` | `branding` | `string` (`"support@career.tringers.com"`) | `PUBLIC` | `required\|email\|max:150` | No | `EnforceTenantAndSecurityContext`, `layouts/app.blade.php` | Immediate runtime effect |
| `platform.default_timezone` | `branding` | `string` (`"UTC"`) | `INTERNAL` | `required\|timezone:all` | No | `PlatformAdminService::createTenant` | Immediate runtime effect |
| `platform.default_currency` | `branding` | `enum` (`"USD"`) | `INTERNAL` | `required\|string\|in:USD,GBP,EUR,AED,INR` | No | `PlatformAdminService::createTenant` | Immediate runtime effect |
| `platform.career_portal_banner` | `branding` | `string` (`"Verified Enterprise Opportunities — Powered by Governed Recruitment AI"`) | `PUBLIC` | `required\|string\|min:5\|max:240` | No | `EnforceTenantAndSecurityContext`, `careers/index.blade.php` | Immediate runtime effect |
| `ai.global_kill_switch` | `ai_governance` | `boolean` (`false`) | `SENSITIVE_GOVERNANCE` | `required\|boolean` | **Yes** (`min:10`) | `AiGatewayService::executeTask` (`AI_GLOBAL_KILL_SWITCH_ENGAGED`) | Immediate runtime effect |
| `ai.global_monthly_budget_cap_minor` | `ai_governance` | `integer` (`250000` / `$2,500.00`) | `SENSITIVE_GOVERNANCE` | `required\|integer\|min:0\|max:10000000` | **Yes** (`min:10`) | `AiGatewayService::executeTask`, `AiGatewayService::updateProviderConfig`, `AiGatewayService::ensureDefaultsForTenant` | Immediate runtime effect |
| `ai.mandatory_pii_redaction` | `ai_governance` | `boolean` (`true`) | `SENSITIVE_GOVERNANCE` | `required\|boolean` | **Yes** (`min:10`) | `AiGatewayService::executeTask`, `AiGatewayService::updateProviderConfig` | Immediate runtime effect |
| `ai.default_primary_provider` | `ai_governance` | `enum` (`"claude"`) | `INTERNAL` | `required\|string\|in:claude,gemini,openai,deterministic_fake` | **Yes** (`min:10`) | `AiGatewayService::ensureDefaultsForTenant` | Immediate runtime effect |
| `api.global_mcp_enabled` | `api_mcp` | `boolean` (`true`) | `SENSITIVE_GOVERNANCE` | `required\|boolean` | **Yes** (`min:10`) | `ApiV1AndMcpController::listMcpTools`, `ApiV1AndMcpController::invokeMcpTool` (`503 MCP_GLOBALLY_DISABLED`) | Immediate runtime effect |
| `webhooks.global_delivery_enabled` | `api_mcp` | `boolean` (`true`) | `SENSITIVE_GOVERNANCE` | `required\|boolean` | **Yes** (`min:10`) | `ApiAndMcpPlatformService::dispatchSignedWebhook` (`PAUSED_BY_PLATFORM_POLICY`) | Immediate runtime effect |
| `webhooks.max_subscriptions_per_tenant` | `api_mcp` | `integer` (`25`) | `INTERNAL` | `required\|integer\|min:1\|max:100` | No | `ApiAndMcpPlatformService::createWebhookSubscription` (`WEBHOOK_LIMIT_EXCEEDED`) | Immediate runtime effect |
| `dynamics.global_live_writes_blocked` | `integrations` | `boolean` (`true`) | `SENSITIVE_GOVERNANCE` | `required\|boolean` | **Yes** (`min:10`) | `DynamicsHrConnectorService::updateConfig` (`BLOCKED_BY_PLATFORM_SAFETY_GATE`) | Immediate runtime effect |
| `security.login_max_attempts_per_minute` | `security` | `integer` (`8`) | `SENSITIVE_GOVERNANCE` | `required\|integer\|min:3\|max:30` | **Yes** (`min:10`) | `AuthController::login` (`RateLimiter::tooManyAttempts`) | Immediate runtime effect |
| `security.require_mfa_for_platform_admins` | `security` | `boolean` (`true`) | `SENSITIVE_GOVERNANCE` | `required\|boolean` | **Yes** (`min:10`) | `RequirePlatformSuperAdmin::handle` (`403 PLATFORM_MFA_REQUIRED`) | Immediate runtime effect |
| `security.require_step_up_for_sensitive_changes` | `security` | `boolean` (`true`) | `SENSITIVE_GOVERNANCE` | `required\|boolean` | **Yes** (`min:10`) | `PlatformAdminController::promoteAdmin`, `PlatformAdminController::revokeAdmin` (`STEP_UP_AUTH_FAILED`) | Immediate runtime effect |
| `security.min_retention_days` | `security` | `integer` (`180`) | `SENSITIVE_GOVERNANCE` | `required\|integer\|min:30\|max:3650` | **Yes** (`min:10`) | `PlatformAdminService::createTenant`, `PlatformAdminService::updateTenantLifecycle` (`RETENTION_BELOW_PLATFORM_FLOOR`) | Immediate runtime effect |

---

## 3. Environment-Managed & Secret-Reference Settings (`ENVIRONMENT_MANAGED` / `SECRET_ENV_REFERENCE`)

| Environment Key | Classification | Scope | Sensitivity | UI Representation | Runtime Consumer | Rotation / Restart Requirement |
|---|---|---|---|---|---|---|
| `APP_ENV` | `ENVIRONMENT_MANAGED` | Platform Global | `INTERNAL` | Effective value (`local` / `production`) | Laravel bootstrap & `AlphaSafeBootstrapSeeder` guard | `php artisan config:clear` + PHP-FPM reload |
| `APP_DEBUG` | `ENVIRONMENT_MANAGED` | Platform Global | `SENSITIVE_SECURITY` | Effective boolean (`false`) | Exception handler | `php artisan config:clear` |
| `APP_KEY` | `SECRET_ENV_REFERENCE` | Platform Global | `CRITICAL_SECRET` | `CONFIGURED (MASKED)` / `NOT_SET` | Laravel Encrypter (`WebhookSubscription::$signing_secret`, cookies) | Requires re-encryption migration if rotated |
| `MICROSOFT_ENTRA_SSO_ENABLED` | `ENVIRONMENT_MANAGED` | Platform Global | `SENSITIVE_SECURITY` | `false (DISABLED_BY_DEFAULT — FAIL_CLOSED)` | `EntraSsoService::isEnabled` | `php artisan config:clear` after Entra app registration & test tenant verification |
| `MICROSOFT_ENTRA_TENANT_MODE` | `ENVIRONMENT_MANAGED` | Platform Global | `SENSITIVE_SECURITY` | `organizations` (Work/School only) | `EntraSsoService::createAuthorizationRedirect` | `php artisan config:clear` |
| `MICROSOFT_ENTRA_CLIENT_ID` | `ENVIRONMENT_MANAGED` | Platform Global | `INTERNAL` | `CONFIGURED (MASKED_IDENTIFIER)` / `NOT_SET` | `EntraSsoService` | `php artisan config:clear` |
| `MICROSOFT_ENTRA_CLIENT_SECRET` | `SECRET_ENV_REFERENCE` | Platform Global | `CRITICAL_SECRET` | `CONFIGURED (MASKED)` / `NOT_SET` | `EntraSsoService::exchangeAuthorizationCode` | Rotate in Microsoft Entra ID + `.env` + `php artisan config:clear` |
| `SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` | `ENVIRONMENT_MANAGED` | Platform Global | `INTERNAL` | Effective driver summary | Session, Redis Cache, Queue Workers | `php artisan config:clear` + `php artisan queue:restart` |
