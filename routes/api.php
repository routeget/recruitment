<?php

declare(strict_types=1);

use App\Http\Controllers\ApiV1AndMcpController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'jsonHealth'])->name('api.health');

Route::prefix('v1')->group(function (): void {
    Route::get('/requisitions', [ApiV1AndMcpController::class, 'listRequisitions'])->name('api.v1.requisitions.index');
    Route::post('/requisitions', [ApiV1AndMcpController::class, 'createRequisition'])->name('api.v1.requisitions.store');

    Route::get('/jobs', [ApiV1AndMcpController::class, 'listJobs'])->name('api.v1.jobs.index');

    Route::get('/candidates', [ApiV1AndMcpController::class, 'listCandidates'])->name('api.v1.candidates.index');
    Route::post('/candidates', [ApiV1AndMcpController::class, 'createCandidate'])->name('api.v1.candidates.store');
    Route::get('/candidates/{candidateId}/resume-parses', [ApiV1AndMcpController::class, 'listCandidateResumeParsesApi'])->name('api.v1.candidates.resume-parses.index');
    Route::post('/candidates/{candidateId}/documents/{documentId}/parse', [ApiV1AndMcpController::class, 'parseCandidateDocumentApi'])->name('api.v1.candidates.documents.parse');
    Route::post('/candidates/{candidateId}/resume-parses/{parseJobId}/review', [ApiV1AndMcpController::class, 'reviewCandidateResumeParseApi'])->name('api.v1.candidates.resume-parses.review');

    Route::get('/applications', [ApiV1AndMcpController::class, 'listApplications'])->name('api.v1.applications.index');
    Route::post('/applications/{applicationId}/match', [ApiV1AndMcpController::class, 'evaluateApplicationMatchApi'])->name('api.v1.applications.match');

    Route::get('/hr-handovers', [ApiV1AndMcpController::class, 'listHrHandovers'])->name('api.v1.hr-handovers.index');
    Route::post('/hr-handovers/{caseId}/dispatch', [ApiV1AndMcpController::class, 'dispatchHrHandoverApi'])->name('api.v1.hr-handovers.dispatch');
});
