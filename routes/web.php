<?php

use App\Http\Controllers\AiGatewayController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApiV1AndMcpController;
use App\Http\Controllers\AuditGovernanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CandidateAndApplicationController;
use App\Http\Controllers\CareerPortalConfigurationController;
use App\Http\Controllers\CareerPortalController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EntraSsoController;
use App\Http\Controllers\EvaluationAndDecisionController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\IntegrationsAndDynamicsController;
use App\Http\Controllers\JobPostingController;
use App\Http\Controllers\OfferAndHrHandoverController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PlatformAdminController;
use App\Http\Controllers\RequisitionController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'jsonHealth'])->name('health');
Route::get('/health/ready', [HealthController::class, 'jsonReadiness'])->name('health.ready');
Route::get('/setup/status', [AuthController::class, 'setupStatus'])->name('setup.status');

// Increment 6: Governed MCP Server (/mcp/v1)
Route::prefix('mcp/v1')->group(function () {
    Route::get('/tools', [ApiV1AndMcpController::class, 'listMcpTools'])->name('mcp.v1.tools');
    Route::post('/invoke', [ApiV1AndMcpController::class, 'invokeMcpTool'])->name('mcp.v1.invoke');
});

// Public Career Portal: the domain root is the aggregate job board.
Route::get('/', [CareerPortalController::class, 'index'])->name('careers.index');
Route::get('/careers', [CareerPortalController::class, 'index'])->name('careers.legacy-index');
Route::get('/careers/{tenantSlug}/jobs/{jobSlug}', [CareerPortalController::class, 'showJob'])->name('careers.legacy-show');
Route::post('/careers/{tenantSlug}/jobs/{jobSlug}/apply', [CareerPortalController::class, 'submitPublicApplication'])->name('careers.legacy-apply');
Route::post('/careers/cookie-consent', [CareerPortalController::class, 'saveCookieConsent'])->name('careers.cookie-consent');
Route::get('/legal/{page}', [CareerPortalController::class, 'legalPage'])
    ->where('page', 'privacy-policy|terms-of-use|cookie-policy|privacy|terms|cookies')
    ->name('careers.legal');
Route::get('/sitemap.xml', [CareerPortalController::class, 'sitemap'])->name('careers.sitemap');
Route::get('/robots.txt', [CareerPortalController::class, 'robots'])->name('careers.robots');

Route::get('/auth/entra/redirect', [EntraSsoController::class, 'redirect'])->name('auth.entra.redirect');
Route::get('/auth/entra/callback', [EntraSsoController::class, 'callback'])->name('auth.entra.callback');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    // Tenant application namespace. Platform-only identities are rejected by tenant.user.
    Route::middleware('tenant.user')->group(function () {
        Route::post('/tenant/switch', [AuthController::class, 'switchTenant'])->name('tenant.switch');
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/organization', [DashboardController::class, 'index'])->name('organization.home');

        // Organization structure administration is a subpage, not the tenant landing route.
        Route::get('/organization/setup', [OrganizationController::class, 'index'])
            ->middleware('permission:org.manage')
            ->name('organization.index');
        Route::post('/organization/legal-entities', [OrganizationController::class, 'storeLegalEntity'])
            ->middleware('permission:org.manage')
            ->name('organization.legal-entities.store');
        Route::post('/organization/departments', [OrganizationController::class, 'storeDepartment'])
            ->middleware('permission:org.manage')
            ->name('organization.departments.store');
        Route::post('/organization/locations', [OrganizationController::class, 'storeLocation'])
            ->middleware('permission:org.manage')
            ->name('organization.locations.store');

        // Increment 2: Workforce Requisitions
        Route::get('/requisitions', [RequisitionController::class, 'index'])
            ->middleware('permission:requisitions.view')
            ->name('requisitions.index');
        Route::post('/requisitions', [RequisitionController::class, 'store'])
            ->middleware('permission:requisitions.create')
            ->name('requisitions.store');
        Route::get('/requisitions/{requisition}', [RequisitionController::class, 'show'])
            ->middleware('permission:requisitions.view')
            ->name('requisitions.show');
        Route::put('/requisitions/{requisition}', [RequisitionController::class, 'update'])
            ->middleware('permission:requisitions.create')
            ->name('requisitions.update');
        Route::post('/requisitions/{requisition}/submit', [RequisitionController::class, 'submit'])
            ->middleware('permission:requisitions.create')
            ->name('requisitions.submit');
        Route::post('/requisitions/{requisition}/approve', [RequisitionController::class, 'decideApproval'])
            ->middleware('permission:requisitions.approve')
            ->name('requisitions.approve');
        Route::post('/requisitions/{requisition}/transition', [RequisitionController::class, 'transitionState'])
            ->middleware('permission:requisitions.create')
            ->name('requisitions.transition');

        // Increment 3: Job Descriptions & Publishing
        Route::get('/jobs', [JobPostingController::class, 'index'])
            ->middleware('permission:jobs.manage')
            ->name('jobs.index');
        Route::post('/jobs', [JobPostingController::class, 'store'])
            ->middleware('permission:jobs.manage')
            ->name('jobs.store');
        Route::get('/jobs/{job}', [JobPostingController::class, 'show'])
            ->middleware('permission:jobs.manage')
            ->name('jobs.show');
        Route::post('/jobs/{job}/publish', [JobPostingController::class, 'publish'])
            ->middleware('permission:jobs.publish')
            ->name('jobs.publish');
        Route::post('/jobs/{job}/status', [JobPostingController::class, 'changeStatus'])
            ->middleware('permission:jobs.publish')
            ->name('jobs.status');

        // Increment 3: Candidate CRM, Private Documents & Applications
        Route::get('/candidates', [CandidateAndApplicationController::class, 'candidatesIndex'])
            ->middleware('permission:candidates.view')
            ->name('candidates.index');
        Route::post('/candidates', [CandidateAndApplicationController::class, 'storeCandidate'])
            ->middleware('permission:candidates.manage')
            ->name('candidates.store');
        Route::get('/candidates/{candidate}', [CandidateAndApplicationController::class, 'candidateShow'])
            ->middleware('permission:candidates.view')
            ->name('candidates.show');
        Route::post('/candidates/{candidate}/documents', [CandidateAndApplicationController::class, 'uploadDocument'])
            ->middleware('permission:candidates.manage')
            ->name('candidates.documents.upload');
        Route::post('/candidates/{candidate}/documents/{document}/parse', [CandidateAndApplicationController::class, 'parseCandidateDocument'])
            ->middleware('permission:candidates.manage')
            ->name('candidates.documents.parse');
        Route::post('/candidates/{candidate}/resume-parses/{parseJob}/review', [CandidateAndApplicationController::class, 'reviewCandidateResumeParse'])
            ->middleware('permission:candidates.manage')
            ->name('candidates.resume-parses.review');
        Route::post('/candidates/{candidate}/consent', [CandidateAndApplicationController::class, 'updateConsent'])
            ->middleware('permission:candidates.manage')
            ->name('candidates.consent.update');
        Route::get('/documents/{document}/download', [CandidateAndApplicationController::class, 'downloadDocument'])
            ->middleware('permission:candidates.view')
            ->name('documents.download');

        Route::get('/applications', [CandidateAndApplicationController::class, 'applicationsIndex'])
            ->middleware('permission:candidates.view')
            ->name('applications.index');
        Route::post('/applications', [CandidateAndApplicationController::class, 'storeApplication'])
            ->middleware('permission:applications.manage')
            ->name('applications.store');
        Route::get('/applications/{application}', [CandidateAndApplicationController::class, 'applicationShow'])
            ->middleware('permission:candidates.view')
            ->name('applications.show');
        Route::post('/applications/{application}/transition', [CandidateAndApplicationController::class, 'transitionApplicationStage'])
            ->middleware('permission:applications.manage')
            ->name('applications.transition');
        Route::post('/applications/{application}/ai-match', [CandidateAndApplicationController::class, 'evaluateApplicationAiMatch'])
            ->middleware('permission:screening.manage,ai.use')
            ->name('applications.ai-match');
        Route::post('/applications/{application}/ai-match/review', [CandidateAndApplicationController::class, 'reviewApplicationAiMatch'])
            ->middleware('permission:screening.manage,ai.use')
            ->name('applications.ai-match.review');

        // Increment 4: Screening, Assessments, Interviews, Scorecards, Decisions & Analytics
        Route::get('/evaluations', [EvaluationAndDecisionController::class, 'index'])
            ->middleware('permission:screening.manage,interviews.manage,decisions.manage,candidates.view')
            ->name('evaluations.index');
        Route::post('/evaluations/screening', [EvaluationAndDecisionController::class, 'storeScreening'])
            ->middleware('permission:screening.manage')
            ->name('evaluations.screening.store');
        Route::post('/evaluations/assessments', [EvaluationAndDecisionController::class, 'storeAssessment'])
            ->middleware('permission:screening.manage')
            ->name('evaluations.assessments.store');
        Route::post('/evaluations/interviews', [EvaluationAndDecisionController::class, 'storeInterview'])
            ->middleware('permission:interviews.manage')
            ->name('evaluations.interviews.store');
        Route::post('/evaluations/scorecards', [EvaluationAndDecisionController::class, 'storeScorecard'])
            ->middleware('permission:interviews.manage')
            ->name('evaluations.scorecards.store');
        Route::post('/evaluations/decisions', [EvaluationAndDecisionController::class, 'storeDecision'])
            ->middleware('permission:decisions.manage')
            ->name('evaluations.decisions.store');

        Route::get('/analytics', [AnalyticsController::class, 'index'])
            ->middleware('permission:reports.view')
            ->name('analytics.index');

        // Increment 5: Offers & Mandatory HR Validation / Handover Safety Gate
        Route::get('/offers', [OfferAndHrHandoverController::class, 'offersIndex'])
            ->middleware('permission:offers.manage,offers.approve')
            ->name('offers.index');
        Route::post('/offers/templates', [OfferAndHrHandoverController::class, 'storeOfferTemplate'])
            ->middleware('permission:offers.manage')
            ->name('offers.templates.store');
        Route::post('/offers', [OfferAndHrHandoverController::class, 'storeOffer'])
            ->middleware('permission:offers.manage')
            ->name('offers.store');
        Route::post('/offers/{offer}/issue', [OfferAndHrHandoverController::class, 'issueOffer'])
            ->middleware('permission:offers.approve')
            ->name('offers.issue');
        Route::post('/offers/{offer}/accept', [OfferAndHrHandoverController::class, 'acceptOffer'])
            ->middleware('permission:offers.manage,offers.approve')
            ->name('offers.accept');
        Route::post('/offers/{offer}/revise', [OfferAndHrHandoverController::class, 'reviseOffer'])
            ->middleware('permission:offers.manage')
            ->name('offers.revise');

        Route::get('/hr-handover', [OfferAndHrHandoverController::class, 'hrHandoverIndex'])
            ->middleware('permission:hr_validation.manage,hr_handover.approve,hr_handover.dispatch,dynamics.admin')
            ->name('hr-handover.index');
        Route::post('/hr-handover/{case}/checklist', [OfferAndHrHandoverController::class, 'updateChecklist'])
            ->middleware('permission:hr_validation.manage,dynamics.admin')
            ->name('hr-handover.checklist');
        Route::post('/hr-handover/{case}/approve', [OfferAndHrHandoverController::class, 'approveHandover'])
            ->middleware('permission:hr_handover.approve')
            ->name('hr-handover.approve');
        Route::post('/hr-handover/{case}/dispatch', [OfferAndHrHandoverController::class, 'dispatchHandover'])
            ->middleware('permission:hr_handover.dispatch,dynamics.admin')
            ->name('hr-handover.dispatch');
        Route::post('/hr-handover/handovers/{handover}/reconcile', [OfferAndHrHandoverController::class, 'reconcileHandover'])
            ->middleware('permission:hr_handover.dispatch,dynamics.admin')
            ->name('hr-handover.reconcile');

        // Increments 6, 7 & 8: AI Gateway, API Platform, Webhooks, MCP & Dynamics 365 Connector
        Route::get('/ai', [AiGatewayController::class, 'index'])
            ->middleware('permission:ai.use')
            ->name('ai.index');
        Route::post('/ai/execute', [AiGatewayController::class, 'executeTask'])
            ->middleware('permission:ai.use')
            ->name('ai.execute');
        Route::post('/ai/runs/{run}/review', [AiGatewayController::class, 'reviewTaskRun'])
            ->middleware('permission:ai.use')
            ->name('ai.runs.review');
        Route::post('/ai/providers/{provider}', [AiGatewayController::class, 'updateProviderConfig'])
            ->middleware('permission:ai.admin')
            ->name('ai.providers.update');
        Route::post('/ai/providers/{provider}/validate', [AiGatewayController::class, 'validateProviderConnection'])
            ->middleware('permission:ai.admin')
            ->name('ai.providers.validate');
        Route::post('/ai/routes/{route}', [AiGatewayController::class, 'updateTaskRoute'])
            ->middleware('permission:ai.admin')
            ->name('ai.routes.update');
        Route::post('/ai/prompts', [AiGatewayController::class, 'storePromptVersion'])
            ->middleware('permission:ai.admin')
            ->name('ai.prompts.store');

        Route::get('/integrations/api', [IntegrationsAndDynamicsController::class, 'apiAndMcpIndex'])
            ->middleware('permission:api.admin')
            ->name('integrations.api.index');
        Route::post('/integrations/api/clients', [IntegrationsAndDynamicsController::class, 'storeApiClient'])
            ->middleware('permission:api.admin')
            ->name('integrations.api.clients.store');
        Route::post('/integrations/api/webhooks', [IntegrationsAndDynamicsController::class, 'storeWebhook'])
            ->middleware('permission:api.admin')
            ->name('integrations.api.webhooks.store');

        Route::get('/integrations/dynamics', [IntegrationsAndDynamicsController::class, 'dynamicsIndex'])
            ->middleware('permission:dynamics.admin')
            ->name('dynamics.index');
        Route::post('/integrations/dynamics/config', [IntegrationsAndDynamicsController::class, 'updateDynamicsConfig'])
            ->middleware('permission:dynamics.admin')
            ->name('dynamics.config.update');
        Route::post('/integrations/dynamics/cases/{case}/dry-run', [IntegrationsAndDynamicsController::class, 'dryRunValidate'])
            ->middleware('permission:dynamics.admin')
            ->name('dynamics.dry-run');

        // Increment 1: IAM & Audit
        Route::get('/admin/tenants-iam', [AuditGovernanceController::class, 'iamIndex'])
            ->middleware('permission:iam.manage')
            ->name('admin.iam.index');
        Route::post('/admin/tenants-iam/members', [AuditGovernanceController::class, 'storeTenantMember'])
            ->middleware('permission:iam.manage')
            ->name('admin.iam.members.store');
        Route::post('/admin/tenants-iam/sso/connections', [EntraSsoController::class, 'storeConnection'])
            ->middleware('permission:iam.manage')
            ->name('admin.iam.sso.connections.store');
        Route::post('/admin/tenants-iam/sso/invitations', [EntraSsoController::class, 'createInvitation'])
            ->middleware('permission:iam.manage')
            ->name('admin.iam.sso.invitations.store');
        Route::post('/admin/tenants-iam/sso/identities/link', [EntraSsoController::class, 'linkIdentity'])
            ->middleware('permission:iam.manage')
            ->name('admin.iam.sso.identities.link');
        Route::post('/admin/tenants-iam/sso/identities/{identity}/status', [EntraSsoController::class, 'updateIdentityStatus'])
            ->middleware('permission:iam.manage')
            ->name('admin.iam.sso.identities.status');
        Route::get('/admin/audit-events', [AuditGovernanceController::class, 'auditIndex'])
            ->middleware('permission:audit.view')
            ->name('admin.audit.index');

    }); // End tenant.user namespace.

    Route::post('/admin/platform/support-context', [AuditGovernanceController::class, 'grantPlatformSupportContext'])
        ->middleware('platform.superadmin')
        ->name('admin.iam.support-context');

    // Platform Administration & Configuration Centre.
    Route::prefix('platformadmin')->middleware('platform.admin')->name('platform-admin.')->group(function () {
        Route::get('/', [PlatformAdminController::class, 'index'])->name('index');
        Route::get('/career-portal-configuration', [CareerPortalConfigurationController::class, 'index'])->name('career-portal.index');
        Route::get('/career-portal-configuration/{section}', [CareerPortalConfigurationController::class, 'index'])->name('career-portal.section');
        Route::post('/career-portal-configuration/draft', [CareerPortalConfigurationController::class, 'saveDraft'])->name('career-portal.draft.save');
        Route::post('/career-portal-configuration/validate', [CareerPortalConfigurationController::class, 'validateDraft'])->name('career-portal.draft.validate');
        Route::post('/career-portal-configuration/reset-default', [CareerPortalConfigurationController::class, 'resetDefault'])->name('career-portal.draft.reset');
        Route::post('/career-portal-configuration/preview', [CareerPortalConfigurationController::class, 'generatePreview'])->name('career-portal.preview');
        Route::post('/career-portal-configuration/publish', [CareerPortalConfigurationController::class, 'publish'])->name('career-portal.publish');
        Route::post('/career-portal-configuration/rollback/{versionNumber}', [CareerPortalConfigurationController::class, 'rollback'])->name('career-portal.rollback');
        Route::post('/career-portal-configuration/assets', [CareerPortalConfigurationController::class, 'uploadAsset'])->name('career-portal.assets.upload');
        Route::get('/integrations/api', [PlatformAdminController::class, 'index'])->defaults('section', 'api-mcp')->name('integrations.api');
        Route::get('/integrations/dynamics', [PlatformAdminController::class, 'index'])->defaults('section', 'integrations')->name('integrations.dynamics');
        Route::get('/system/diagnostics', [HealthController::class, 'diagnosticsPage'])->name('system.diagnostics');
        Route::get('/section/{section}', [PlatformAdminController::class, 'index'])->name('section');
        Route::post('/settings/{section}', [PlatformAdminController::class, 'updateSection'])->name('settings.update');
        Route::post('/support-context', [AuditGovernanceController::class, 'grantPlatformSupportContext'])->name('support-context');
        Route::post('/tenants', [PlatformAdminController::class, 'storeTenant'])->name('tenants.store');
        Route::post('/tenants/{tenant}/lifecycle', [PlatformAdminController::class, 'updateTenantLifecycle'])->name('tenants.lifecycle');
        Route::post('/admins/promote', [PlatformAdminController::class, 'promoteAdmin'])->name('admins.promote');
        Route::post('/admins/{targetUser}/revoke', [PlatformAdminController::class, 'revokeAdmin'])->name('admins.revoke');
        Route::post('/api-clients/{client}/status', [PlatformAdminController::class, 'updateApiClientStatus'])->name('api-clients.status');
        Route::post('/webhooks/{subscription}/status', [PlatformAdminController::class, 'updateWebhookStatus'])->name('webhooks.status');
        Route::post('/security/rotate-password', [PlatformAdminController::class, 'rotatePassword'])->name('security.rotate-password');
    });
});

// Canonical tenant career portal URLs. Keep these after reserved/static application routes.
Route::get('/{tenantSlug}/jobs/{jobSlug}', [CareerPortalController::class, 'showJob'])
    ->where('tenantSlug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('careers.show');
Route::post('/{tenantSlug}/jobs/{jobSlug}/extract-resume', [CareerPortalController::class, 'extractResumeForApplication'])
    ->where('tenantSlug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('careers.extract-resume');
Route::post('/{tenantSlug}/jobs/{jobSlug}/apply', [CareerPortalController::class, 'submitPublicApplication'])
    ->where('tenantSlug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('careers.apply');
Route::get('/{tenantSlug}/applications/{applicationUuid}/resume-review', [CareerPortalController::class, 'showCandidateResumeReview'])
    ->where('tenantSlug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('careers.application.resume-review');
Route::post('/{tenantSlug}/applications/{applicationUuid}/resume-review', [CareerPortalController::class, 'submitCandidateResumeReview'])
    ->where('tenantSlug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('careers.application.resume-review.submit');
Route::post('/{tenantSlug}/applications/{applicationUuid}/resume-reprocess', [CareerPortalController::class, 'reprocessCandidateResume'])
    ->where('tenantSlug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('careers.application.resume-reprocess');
Route::get('/{tenantSlug}', [CareerPortalController::class, 'tenantIndex'])
    ->where('tenantSlug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('tenant.careers.index');
