<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\TenantMembership;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(TenantContextService $context): View
    {
        $user = Auth::user();
        $tenant = $context->getActiveTenant();
        $tenantId = $tenant?->id;

        $metrics = [
            'active_members' => $tenantId ? TenantMembership::where('tenant_id', $tenantId)->where('status', 'ACTIVE')->count() : 0,
            'departments' => ($tenantId && Schema::hasTable('departments')) ? DB::table('departments')->where('tenant_id', $tenantId)->count() : 0,
            'open_requisitions' => ($tenantId && Schema::hasTable('requisitions'))
                ? DB::table('requisitions')->where('tenant_id', $tenantId)->whereIn('status', ['APPROVED', 'OPEN', 'IN_APPROVAL', 'SUBMITTED'])->count()
                : 0,
            'published_jobs' => ($tenantId && Schema::hasTable('job_postings'))
                ? DB::table('job_postings')->where('tenant_id', $tenantId)->where('status', 'PUBLISHED')->count()
                : 0,
            'candidates' => ($tenantId && Schema::hasTable('candidates'))
                ? DB::table('candidates')->where('tenant_id', $tenantId)->count()
                : 0,
            'active_applications' => ($tenantId && Schema::hasTable('applications'))
                ? DB::table('applications')->where('tenant_id', $tenantId)->whereNotIn('current_stage', ['REJECTED', 'WITHDRAWN'])->count()
                : 0,
            'accepted_offers' => ($tenantId && Schema::hasTable('offers'))
                ? DB::table('offers')->where('tenant_id', $tenantId)->whereIn('status', ['ACCEPTED', 'accepted'])->count()
                : 0,
            'pending_hr_handovers' => ($tenantId && Schema::hasTable('hr_validation_cases'))
                ? DB::table('hr_validation_cases')->where('tenant_id', $tenantId)->whereNotIn('status', ['handed_over', 'HANDED_OVER', 'cancelled'])->count()
                : 0,
        ];

        $recentAuditEvents = $tenantId
            ? AuditEvent::where('tenant_id', $tenantId)->orderByDesc('id')->limit(10)->get()
            : collect();

        $userMemberships = $user
            ? $user->memberships()->where('status', 'ACTIVE')->with(['tenant', 'role'])->get()
            : collect();

        return view('dashboard.index', [
            'tenant' => $tenant,
            'user' => $user,
            'metrics' => $metrics,
            'recentAuditEvents' => $recentAuditEvents,
            'userMemberships' => $userMemberships,
        ]);
    }
}
