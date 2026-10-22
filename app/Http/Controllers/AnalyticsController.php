<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\Requisition;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function index(TenantContextService $context): View
    {
        $tenantId = $context->getActiveTenantId();

        $stageBreakdown = Application::select('current_stage', DB::raw('count(*) as total'))
            ->groupBy('current_stage')
            ->pluck('total', 'current_stage')
            ->all();

        $sourceBreakdown = Candidate::select('source_channel', DB::raw('count(*) as total'))
            ->groupBy('source_channel')
            ->pluck('total', 'source_channel')
            ->all();

        $requisitionStatusBreakdown = Requisition::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $offersSummary = [
            'total_offers' => Schema::hasTable('offers') ? DB::table('offers')->where('tenant_id', $tenantId)->count() : 0,
            'accepted_offers' => Schema::hasTable('offers') ? DB::table('offers')->where('tenant_id', $tenantId)->whereIn('status', ['ACCEPTED', 'accepted'])->count() : 0,
            'handover_blocked' => Schema::hasTable('hr_handovers') ? DB::table('hr_handovers')->where('tenant_id', $tenantId)->whereIn('status', ['BLOCKED', 'blocked_pending_validation', 'failed_retryable'])->count() : 0,
            'handover_ready_or_queued' => Schema::hasTable('hr_handovers') ? DB::table('hr_handovers')->where('tenant_id', $tenantId)->whereIn('status', ['READY', 'QUEUED', 'SUCCEEDED', 'ready_for_dispatch', 'queued', 'succeeded', 'reconciled'])->count() : 0,
        ];

        $aiSummary = [
            'total_runs' => Schema::hasTable('ai_task_runs') ? DB::table('ai_task_runs')->where('tenant_id', $tenantId)->count() : 0,
            'total_cost_usd' => Schema::hasTable('ai_task_runs') ? ((float) DB::table('ai_task_runs')->where('tenant_id', $tenantId)->sum('cost_minor')) / 100.0 : 0.0,
        ];

        return view('analytics.index', [
            'tenant' => $context->getActiveTenant(),
            'stageBreakdown' => $stageBreakdown,
            'sourceBreakdown' => $sourceBreakdown,
            'requisitionStatusBreakdown' => $requisitionStatusBreakdown,
            'offersSummary' => $offersSummary,
            'aiSummary' => $aiSummary,
        ]);
    }
}
