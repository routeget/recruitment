# FSD - Functional and Technical Specifications

This folder is the single authoritative location for project documentation created for the Unified Recruitment AI Platform.

## Primary implementation baseline & authoritative reports

- [Unified Recruitment AI Platform - Level 2 Specification v2.0](UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md)
- [Microsoft Entra ID Enterprise SSO - Level 2 Specification](MICROSOFT_ENTRA_ID_ENTERPRISE_SSO_LEVEL_2_SPECIFICATION.md)
- [Platform Super Admin & Configuration Centre Directive](ANTIGRAVITY_PLATFORM_SUPERADMIN_AND_CONFIGURATION_CENTRE_DIRECTIVE.md)
- [Platform-Wide Functional & Security Audit Report](PLATFORM_WIDE_FUNCTIONAL_AUDIT.md)
- [Platform Functional Defect Register (`D-01`..`D-08`)](PLATFORM_FUNCTIONAL_DEFECT_REGISTER.md)
- [Complete Requirements Traceability Matrix](PLATFORM_REQUIREMENTS_TRACEABILITY.md)
- [Alpha Requirements Traceability Matrix](ALPHA_REQUIREMENTS_TRACEABILITY.md)
- [Alpha Test & Release Report](ALPHA_TEST_AND_RELEASE_REPORT.md)
- [Development Continuation Directive Status](DEVELOPMENT_CONTINUATION_DIRECTIVE_STATUS.md)

## Documentation governance

1. All project specifications, functional requirements, technical designs, architecture decision records, integration specifications, data dictionaries, API contracts, test plans, remediation reports, release evidence, deployment guides and operational runbooks must be stored under `/FSD`.
2. Do not create project documentation in the repository root or in ad hoc documentation folders. The repository root README may remain as a minimal repository landing page, but substantive project documentation belongs here.
3. Organize future documents into clear subfolders under `/FSD` only when the document volume justifies it, for example `/FSD/architecture`, `/FSD/integrations`, `/FSD/testing`, `/FSD/security` and `/FSD/releases`. These are examples, not a requirement to create empty folders.
4. Use descriptive, stable filenames and include document ID, version, status, owner/audience, date, dependencies and change history in each substantial document.
5. Keep implementation requirements traceable to code modules, migrations, APIs, UI, authorization rules, tests and acceptance evidence.
6. Update the relevant specification and traceability matrix when approved requirements change. Do not silently overwrite history for material changes; version or record the change.
7. Never store secrets, access tokens, production credentials, real candidate PII or unredacted sensitive logs in documentation.
8. Antigravity and Claude must read the Level 2 specification before implementation and treat it as the approved baseline unless the product owner explicitly changes a requirement.
9. The agreed delivery strategy is full-cycle development of the complete integrated application package, followed by a dedicated blocker-remediation and release-validation phase. Continue basic testing during development and do not knowingly propagate critical security or data-integrity defects.
10. The mandatory Dynamics 365 Finance HR gate remains: accepted offer + completed required HR validation + explicit authorized HR/onboarding approval. Offer acceptance alone must never trigger employee creation.

## Repository documentation rule

**Put all new project documentation under `/FSD` only.** Source code, automated tests, migrations, application configuration and build/deployment artifacts belong in their appropriate implementation locations; this rule is specifically for documentation artifacts.
