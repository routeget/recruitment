<?php

use App\Http\Controllers\AuditGovernanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
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
