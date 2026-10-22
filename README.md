# Unified Recruitment AI Platform (Alpha)

Enterprise multi-tenant AI-assisted recruitment, candidate CRM, career portal, offer management, and governed Microsoft Dynamics 365 Finance HR onboarding handover platform.

- **Live Authorized Alpha Environment:** `https://career.tringers.com`
- **Stack:** PHP 8.5, Laravel 13, Bootstrap 5, MariaDB 12.3, Redis 7, NGINX (TLS 1.3)
- **Enterprise identity requirement:** Microsoft Entra ID SSO for organizational Microsoft 365 work/school accounts is a mandatory implementation requirement. See the Level 2 specification below. SSO must not be represented as operational until it is configured and verified with a dedicated Entra test tenant.
- **Authoritative Specification & Documentation:** All substantive architecture, traceability, runbook, and release documentation lives in [`/FSD`](./FSD/README.md):
  - [Unified Recruitment AI Platform Level 2 Specification v2.0](./FSD/UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md)
  - [Microsoft Entra ID Enterprise SSO Level 2 Specification](./FSD/MICROSOFT_ENTRA_ID_ENTERPRISE_SSO_LEVEL_2_SPECIFICATION.md)
  - [Alpha Implementation Plan](./FSD/ALPHA_IMPLEMENTATION_PLAN.md)
  - [Alpha Requirements Traceability](./FSD/ALPHA_REQUIREMENTS_TRACEABILITY.md)
  - [Alpha Deployment Runbook](./FSD/ALPHA_DEPLOYMENT_RUNBOOK.md)
  - [Alpha Test and Release Report](./FSD/ALPHA_TEST_AND_RELEASE_REPORT.md)
  - [Antigravity Entra SSO and Alpha Remediation Directive](./FSD/ANTIGRAVITY_ENTRA_SSO_AND_ALPHA_REMEDIATION_DIRECTIVE.md)

## Key Alpha Capabilities

1. **Multi-Tenant Security & IAM:** Server-side tenant isolation (`BelongsToTenant`), least-privilege RBAC/ABAC personas, scoped break-glass support access, and an append-only `AuditEvent` ledger with automatic secret/PII redaction.
2. **Workforce Requisitions & Publishing:** Legal entities, departments, locations, versioned requisitions with self-approval prohibition and material-change approval invalidation, and SEO-ready public career portal (`/careers`, Schema.org `JobPosting` JSON-LD, `/sitemap.xml`, `/robots.txt`).
3. **Candidate CRM, Private Documents & Evaluation Pipeline:** Consent-verified applications, email deduplication, private resume storage (SHA-256 checksums, executable file blocking), structured screening, assessments, interviews, scorecards, and hiring decisions.
4. **Offers & Mandatory HR Validation / Handover Safety Gate (`HRH-01`.. `HRH-08`):** Immutable `OfferVersion` snapshots, versioned HR checklist validation (`hr_validator`), separate explicit approval by an authorized `hr_approver`, material-change invalidation, atomic `TransactionalOutboxEvent`, idempotency, reconciliation before retry, and `ExternalEmployeeReference` tracking.
5. **Provider-Neutral AI Gateway, `/api/v1`, Webhooks, `/mcp/v1` & Dynamics 365 Connector:** Governed adapters for Claude, Gemini, OpenAI, and DeterministicFake with kill-switches, monthly budgets, PII redaction, and mandatory human review; idempotent API endpoints; HMAC-SHA256 webhook signing; allowlisted MCP tools; and safe-by-default Dynamics 365 Finance HR connector.
6. **Microsoft Entra ID SSO (required implementation):** Enterprise work/school account authentication using OIDC authorization code flow with PKCE, explicit Entra-to-application tenant mapping, secure identity linking, and the existing RBAC/ABAC authorization model. This is a required implementation target and must not be described as enabled until tested and configured.

## Verification Commands

```bash
php artisan migrate --status
./vendor/bin/pint --test
./vendor/bin/phpunit
```
