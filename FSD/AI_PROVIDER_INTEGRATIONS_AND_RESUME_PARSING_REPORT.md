# AI Provider Integrations & Intelligent Resume Processing — Architecture & Verification Report

**Repository:** `routeget/recruitment`  
**Target Branch:** `main`  
**Authorized Environment:** `https://career.tringers.com`  
**Parent Specifications:**  
- `FSD/UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md`  
- `FSD/TRINGERS_PUBLIC_CAREER_PORTAL_LEVEL_2_SPECIFICATION_v1.0.md`  
**Schema Version:** `RESUME_SCHEMA_v1.0`  
**Status:** Verified & Deployed in Production (`62 / 62` Automated Feature & Security Tests Passing, `1,280` Assertions)

---

## 1. Executive Summary

This report documents the end-to-end architecture, implementation, security controls, and verification evidence for the **Common AI Provider Integration Layer** and **Automated AI-Based Resume Processing & Explainable Candidate-to-Job Matching** across Recruitment AI (`https://career.tringers.com`).

Key capabilities delivered:
1. **Provider-Neutral AI Gateway & Adapter Contract (`AiProviderAdapterInterface`):**
   - First-class runtime provider adapters for **Anthropic Claude** (`AnthropicClaudeAdapter`), **Google Gemini** (`GoogleGeminiAdapter`), **OpenAI** (`OpenAiAdapter`), and the **Built-In Governed Document & Rule Analyzer** (`DeterministicStructuredAdapter`).
   - Explicit architectural enforcement that **`codex` is an engineering workflow and never a runtime application model provider** (`AI_PROVIDER_CODEX_NOT_RUNTIME`).
2. **Encrypted Provider Credentials, Connection Validation & Capability Discovery:**
   - AES-256-CBC (`Crypt::encryptString`) credential storage in `ai_provider_configs.encrypted_api_key`, automatic key masking (`api_key_masked`), hidden serialization (`$hidden = ['encrypted_api_key']`), live connection validation (`POST /ai/providers/{id}/validate`), model compatibility enforcement (`AI_MODEL_INCOMPATIBLE`), and honest connection status reporting (`CONNECTED`, `UNCONFIGURED`, `BUILT_IN`, `AUTH_FAILED`, `RATE_LIMITED`, `UNREACHABLE`).
3. **Task-Specific Routing, Fallback, Retries & Governance Controls:**
   - Configurable per-tenant task routes (`ai_task_routes`) for `resume.extract`, `candidate.match`, `candidate.match.explanation`, `job.description.generate`, `interview.summary`, and `offer.draft`.
   - Bounded retry handling on transient HTTP 429/5xx errors, automatic fallback to the configured secondary provider when the primary provider fails or returns invalid schema output (`ai.provider_fallback_engaged`), and three-tier emergency kill-switch and monthly budget enforcement.
4. **End-to-End Automated AI Resume Parsing (`RESUME_SCHEMA_v1.0`):**
   - Multi-format text extraction (`ResumeTextExtractorService`) supporting **PDF** (native FlateDecode stream & Tj/TJ operator extraction + Poppler `pdftotext` fallback), **DOCX** (OpenXML `word/document.xml` XPath extraction), **DOC**, and **TXT**.
   - Scanned/image-only PDF detection (`SCANNED_DOCUMENT_OCR_UNAVAILABLE`) that transitions the parse job to `REVIEW_REQUIRED` rather than fabricating candidate data.
   - Untrusted resume security scanning (`ResumeContentAnalyzer::detectAndNeutralizePromptInjection`) that neutralizes prompt-injection directives (`Ignore all previous instructions`, system-prompt exfiltration, autonomous hiring/handover commands), wraps resume text in isolated `<untrusted_resume_document>` delimiters, and logs `resume.prompt_injection_neutralized`.
   - Zero-hallucination schema validation (`ResumeParsingService::normalizeAndValidateResumeSchema`) ensuring missing fields remain `null` or `[]` and are explicitly listed in `missing_fields`.
5. **Candidate-Facing & Tenant Recruitment Workflows:**
   - **Candidate Pre-Submission AI Resume Pre-Fill** (`POST /{tenantSlug}/jobs/{jobSlug}/extract-resume`) with mandatory privacy consent enforcement.
   - **Candidate Post-Submission Resume Review & Correction** (`GET/POST /{tenantSlug}/applications/{applicationUuid}/resume-review` and `POST .../reprocess`).
   - **Recruiter Candidate CRM & Application Review** (`resources/views/candidates/show.blade.php`, `resources/views/applications/show.blade.php`, `resources/views/candidates/index.blade.php`) with parse status badges, provenance metadata, side-by-side human review/correction forms, one-click reprocessing, skill-based filtering (`GET /candidates?skill=...`), and explainable candidate-to-job matching separating **Extracted Resume Facts** from **AI Match Interpretation**.

---

## 2. Common AI Provider Integration Architecture

### 2.1 Provider Adapter Contract & Implementations

| Component | File Path | Responsibility |
|---|---|---|
| Adapter Contract | `app/Contracts/AiProviderAdapterInterface.php` | Defines `getProviderKey()`, `getDisplayName()`, `getDefaultEndpoint()`, `getSupportedModels()`, `getSupportedCapabilities()`, `supportsCapability()`, `validateConnection()`, and `executeStructuredTask()`. |
| Base HTTP Adapter | `app/Services/Ai/Adapters/AbstractHttpProviderAdapter.php` | Implements bounded retry backoff (`max_retries`), timeout normalization (`PROVIDER_TIMEOUT`), HTTP 401/403 (`PROVIDER_AUTH_FAILED`), HTTP 429 (`PROVIDER_RATE_LIMITED`), HTTP 5xx (`PROVIDER_UNAVAILABLE`), and JSON code-fence extraction (`INVALID_JSON_OUTPUT`). |
| Anthropic Claude Adapter | `app/Services/Ai/Adapters/AnthropicClaudeAdapter.php` | Connects to Anthropic Messages API (`/v1/messages`) for `claude-3-7-sonnet`, `claude-3-5-sonnet-latest`, `claude-3-5-haiku-latest`, and `claude-3-opus-latest`. |
| Google Gemini Adapter | `app/Services/Ai/Adapters/GoogleGeminiAdapter.php` | Connects to Google Generative Language API (`/v1beta/models/{model}:generateContent`) with `responseMimeType: application/json` for `gemini-2.5-pro`, `gemini-2.5-flash`, `gemini-1.5-pro`, and `gemini-1.5-flash`. |
| OpenAI Adapter | `app/Services/Ai/Adapters/OpenAiAdapter.php` | Connects to OpenAI Chat Completions API (`/v1/chat/completions`) with `response_format: {type: json_object}` for `gpt-4.1`, `gpt-4.1-mini`, `gpt-4o`, and `gpt-4o-mini`. |
| Governed Local Rule Analyzer | `app/Services/Ai/Adapters/DeterministicStructuredAdapter.php` | Provides deterministic, evidence-backed document extraction and qualification matching (`urap-deterministic-v1`) when external cloud API keys are unconfigured or used as a fallback. |
| AI Gateway Service | `app/Services/AiGatewayService.php` | Orchestrates provider registration, encrypted credential management, connection validation, task-specific routing, PII/secret redaction, fallback execution, schema validation, and immutable audit logging. |

### 2.2 Credential Encryption & Zero-Leakage Guarantee

- **Encrypted Storage:** `AiProviderConfig::setPlaintextApiKey()` encrypts the raw API key using Laravel's `Crypt::encryptString()` before persisting to `encrypted_api_key` and stores only a masked preview (`sk-a...3210`) in `api_key_masked`.
- **Serialization Guard:** `AiProviderConfig::$hidden` includes `'encrypted_api_key'`, preventing accidental JSON serialization in API responses or logs.
- **UI & Audit Redaction:** Browser views (`resources/views/ai/index.blade.php`) never render the decrypted key; audit events record only `credential_updated: bool` and `connection_status`.

---

## 3. Automated AI-Based Resume Parsing & Explainable Matching

### 3.1 Lifecycle States (`ResumeParseJob`)

Every resume parsing operation is tracked in the tenant-scoped `resume_parse_jobs` table (`app/Models/ResumeParseJob.php`) with the following states:
- `UPLOADED` — Document stored in private tenant storage with SHA-256 checksum.
- `QUEUED` — Dispatched to `ProcessResumeParsingJob` for asynchronous background execution.
- `PROCESSING` — Text extraction, prompt-injection neutralization, and AI structured extraction in progress.
- `COMPLETED` — Validated against `RESUME_SCHEMA_v1.0` and synchronized to the candidate profile.
- `REVIEW_REQUIRED` — Flagged for human review due to scanned image-only PDF (`SCANNED_DOCUMENT_OCR_UNAVAILABLE`), neutralized prompt-injection patterns (`PROMPT_INJECTION_DETECTED`), or high ambiguity/missing core fields.
- `RETRY_SCHEDULED` — Transient upstream provider timeout or rate-limit scheduled for bounded retry.
- `FAILED` — Unreadable/corrupted file (`EMPTY_DOCUMENT`, `MALFORMED_DOCUMENT`) or total provider outage; existing candidate profile data is preserved untouched.

### 3.2 Canonical Structured Output (`RESUME_SCHEMA_v1.0`)

`ResumeParsingService::normalizeAndValidateResumeSchema()` validates and normalizes extracted output into:
- `schema_version`: `'RESUME_SCHEMA_v1.0'`
- `full_name`, `first_name`, `last_name`
- `contact`: `email`, `phone`, `location_city`, `country_code`, `links`
- `emails`, `phones`, `location`, `country_code`, `professional_links`
- `professional_summary`, `current_title`, `years_experience`, `total_years_experience`
- `skills` (array of normalized skill strings)
- `work_experience` (array of `{employer, job_title, start_date, end_date, is_current, responsibilities}`)
- `education` (array of `{institution, degree, field_of_study, graduation_date}`)
- `certifications`, `projects`, `languages`
- `missing_fields`, `ambiguous_fields`, `evidence_quotes`
- `prompt_injection_detected`, `security_flags`

### 3.3 Untrusted Resume Security & Prompt-Injection Defense

1. **Upload Magic-Byte & Extension Verification:** `CandidateAndApplicationService::storePrivateDocument()` and `CareerPortalController::extractResumeForApplication()` enforce strict MIME/extension allowlists (`pdf`, `doc`, `docx`, `txt`) and block disguised executable payloads (`<?php`, `#!`, ELF `\x7fELF`, PE `MZ\x90\x00`).
2. **Prompt-Injection Neutralization:** `ResumeContentAnalyzer::detectAndNeutralizePromptInjection()` detects and neutralizes adversarial instructions embedded in resumes (`Ignore all previous instructions`, `Reveal system prompt`, `Automatically approve HR handover`, `<script>`, etc.), replaces them with `[NEUTRALIZED_UNTRUSTED_DIRECTIVE]`, sets `prompt_injection_detected = true`, transitions the parse job to `REVIEW_REQUIRED`, and records an audit event (`resume.prompt_injection_neutralized`).
3. **Advisory-Only AI Boundary:** Neither resume parsing nor `evaluateApplicationMatch()` can automatically advance, reject, hire, or approve HR handover for any candidate.

---

## 4. Traceability Matrix & Endpoints

| Workflow / Capability | Route / Endpoint | Controller / Service | Permission / Guard |
|---|---|---|---|
| AI Provider Config Update | `POST /ai/providers/{configId}` | `AiGatewayController@updateConfig` | `ai.admin` |
| AI Provider Connection Validation | `POST /ai/providers/{configId}/validate` | `AiGatewayController@validateConnection` | `ai.admin` |
| AI Task Route Configuration | `POST /ai/routes/{routeId}` | `AiGatewayController@updateTaskRoute` | `ai.admin` |
| Candidate Pre-Submission AI Pre-Fill | `POST /{tenantSlug}/jobs/{jobSlug}/extract-resume` | `CareerPortalController@extractResumeForApplication` | Public + Mandatory `consent_given` |
| Candidate Resume Review & Correction | `GET/POST /{tenantSlug}/applications/{applicationUuid}/resume-review` | `CareerPortalController@showResumeReview`, `submitResumeReview` | Public Application UUID + Tenant Scope |
| Candidate Resume Reprocess | `POST /{tenantSlug}/applications/{applicationUuid}/resume-review/reprocess` | `CareerPortalController@reprocessCandidateResume` | Public Application UUID + Tenant Scope |
| Recruiter Trigger / Reprocess Parse | `POST /candidates/{id}/documents/{documentId}/parse` | `CandidateAndApplicationController@parseCandidateResume` | `candidates.manage` + Tenant Scope |
| Recruiter Review & Correct Parse | `POST /candidates/{id}/resume-parses/{parseJobId}/review` | `CandidateAndApplicationController@reviewCandidateResumeParse` | `candidates.manage` + Tenant Scope |
| Recruiter Evaluate AI Job Match | `POST /applications/{id}/ai-match` | `CandidateAndApplicationController@evaluateApplicationAiMatch` | `applications.manage` + Tenant Scope |
| Recruiter Mark AI Match Reviewed | `POST /applications/{id}/ai-match/review` | `CandidateAndApplicationController@reviewApplicationAiMatch` | `screening.manage` + Tenant Scope |
| REST API v1 List Resume Parses | `GET /api/v1/candidates/{id}/resume-parses` | `ApiV1AndMcpController@listCandidateResumeParses` | Bearer Token (`candidates.view`) |
| REST API v1 Trigger Resume Parse | `POST /api/v1/candidates/{id}/documents/{documentId}/parse` | `ApiV1AndMcpController@triggerCandidateResumeParse` | Bearer Token (`candidates.manage`) |
| REST API v1 Review Resume Parse | `POST /api/v1/candidates/{id}/resume-parses/{parseJobId}/review` | `ApiV1AndMcpController@reviewCandidateResumeParseApi` | Bearer Token (`candidates.manage`) |
| REST API v1 Evaluate Job Match | `POST /api/v1/applications/{id}/match` | `ApiV1AndMcpController@evaluateApplicationMatchApi` | Bearer Token (`candidates.manage`) |

---

## 5. Automated Verification Results

All **16** required test scenarios in `tests/Feature/AiProviderAndResumeParsingTest.php` and the full platform regression suite (`61` tests, `649` assertions) pass:

- `test_provider_configuration_encrypted_credentials_connection_validation_and_codex_exclusion`: Verifies Claude, Gemini, and OpenAI configuration, encrypted API key storage, key masking, zero secret leakage in HTML/JSON/audit logs, live connection validation (`CONNECTED`, `UNCONFIGURED`, `AUTH_FAILED`, `RATE_LIMITED`), model compatibility checks (`AI_MODEL_INCOMPATIBLE`), RBAC enforcement (`403` for non-admin), and explicit rejection of `codex` as a runtime provider (`AI_PROVIDER_CODEX_NOT_RUNTIME`).
- `test_task_specific_routing_fallback_on_provider_error_and_malformed_json_normalization`: Verifies task routing (`resume.extract`), primary provider malformed JSON detection, automatic fallback to secondary provider (`gemini`), schema validation, and `ai.provider_fallback_engaged` audit logging.
- `test_candidate_and_recruiter_resume_parsing_across_pdf_docx_doc_txt_with_review_correction_reprocess_and_search`: Verifies consent enforcement on pre-submission extraction, session pre-fill, public application DOCX upload & parsing, candidate review & correction persistence, recruiter legacy `.doc` upload, idempotency on duplicate parse requests, and candidate search by parsed skill (`?skill=Kubernetes`).
- `test_untrusted_resume_security_prompt_injection_missing_fields_scanned_pdf_empty_and_malformed_files`: Verifies disguised PHP script rejection (`422`), empty file handling (`EMPTY_DOCUMENT`), malformed PDF header handling (`MALFORMED_DOCUMENT`), scanned image-only PDF detection (`REVIEW_REQUIRED` / `SCANNED_DOCUMENT_OCR_UNAVAILABLE`), prompt-injection neutralization (`resume.prompt_injection_neutralized`), and zero hallucination of missing fields (`phones` and `education` remain `[]`).
- `test_cross_tenant_isolation_and_provider_outage_resilience_across_web_and_rest_api_v1`: Verifies strict `403 TENANT_SCOPE_VIOLATION` across Tenant A and Tenant B on both web and `/api/v1/*` routes, and verifies that total provider outage marks `ResumeParseJob` as `FAILED` without corrupting candidate records.
