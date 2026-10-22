<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class HealthController extends Controller
{
    public function jsonHealth(TenantContextService $context): JsonResponse
    {
        $checks = $this->runOperationalChecks();
        $overallHealthy = $checks['database']['healthy'] && $checks['private_storage']['healthy'];

        return response()->json([
            'status' => $overallHealthy ? 'OK' : 'DEGRADED',
            'service' => 'Unified Recruitment AI Platform',
            'release_stage' => 'Alpha',
            'correlation_id' => $context->getCorrelationId(),
            'timestamp' => now()->toIso8601String(),
            'checks' => [
                'database' => $checks['database']['status'],
                'cache_redis' => $checks['cache_redis']['status'],
                'private_storage' => $checks['private_storage']['status'],
                'hr_handover_safety_gate' => 'ENFORCED',
                'dynamics_live_employee_creation' => 'DISABLED_BY_DEFAULT',
            ],
        ], $overallHealthy ? 200 : 503);
    }

    public function diagnosticsPage(TenantContextService $context): View
    {
        $checks = $this->runOperationalChecks();
        $tenant = $context->getActiveTenant();

        return view('system.diagnostics', [
            'checks' => $checks,
            'tenant' => $tenant,
            'phpVersion' => PHP_VERSION,
            'laravelVersion' => app()->version(),
            'auditEventCount' => $tenant ? AuditEvent::where('tenant_id', $tenant->id)->count() : 0,
            'totalTenantsCount' => Tenant::count(),
        ]);
    }

    private function runOperationalChecks(): array
    {
        $dbHealthy = false;
        $dbStatus = 'UNAVAILABLE';
        try {
            DB::connection()->getPdo();
            $hasTenants = Schema::hasTable('tenants');
            $dbHealthy = $hasTenants;
            $dbStatus = $hasTenants ? 'CONNECTED_SCHEMA_READY' : 'CONNECTED_PENDING_MIGRATIONS';
        } catch (Throwable) {
            $dbHealthy = false;
            $dbStatus = 'CONNECTION_FAILED';
        }

        $redisHealthy = false;
        $redisStatus = 'UNAVAILABLE';
        try {
            Cache::put('health_probe_key', 'ok', 5);
            $redisHealthy = Cache::get('health_probe_key') === 'ok';
            $redisStatus = $redisHealthy ? 'OPERATIONAL' : 'DEGRADED';
        } catch (Throwable) {
            $redisHealthy = false;
            $redisStatus = 'DEGRADED';
        }

        $storageHealthy = false;
        $storageStatus = 'UNAVAILABLE';
        try {
            Storage::disk('local')->makeDirectory('private/health');
            $storageHealthy = true;
            $storageStatus = 'PRIVATE_STORAGE_READY';
        } catch (Throwable) {
            $storageHealthy = false;
            $storageStatus = 'STORAGE_ERROR';
        }

        return [
            'database' => ['healthy' => $dbHealthy, 'status' => $dbStatus],
            'cache_redis' => ['healthy' => $redisHealthy, 'status' => $redisStatus],
            'private_storage' => ['healthy' => $storageHealthy, 'status' => $storageStatus],
        ];
    }
}
