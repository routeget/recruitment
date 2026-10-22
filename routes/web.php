<?php

use App\Http\Controllers\AuditGovernanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\RequisitionController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'jsonHealth'])->name('health');
Route::get('/setup/status', [AuthController::class, 'setupStatus'])->name('setup.status');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/tenant/switch', [AuthController::class, 'switchTenant'])->name('tenant.switch');

    Route::get('/', [DashboardController::class, 'index'])->name('home');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/system/diagnostics', [HealthController::class, 'diagnosticsPage'])->name('system.diagnostics');

    // Increment 2: Organization Setup
    Route::get('/organization', [OrganizationController::class, 'index'])
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

    // Increment 1: IAM & Audit
    Route::get('/admin/tenants-iam', [AuditGovernanceController::class, 'iamIndex'])
        ->middleware('permission:iam.manage')
        ->name('admin.iam.index');
    Route::post('/admin/tenants-iam/members', [AuditGovernanceController::class, 'storeTenantMember'])
        ->middleware('permission:iam.manage')
        ->name('admin.iam.members.store');
    Route::post('/admin/platform/support-context', [AuditGovernanceController::class, 'grantPlatformSupportContext'])
        ->name('admin.iam.support-context');

    Route::get('/admin/audit-events', [AuditGovernanceController::class, 'auditIndex'])
        ->middleware('permission:audit.view')
        ->name('admin.audit.index');
});
