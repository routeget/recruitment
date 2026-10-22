<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ApiClient;
use App\Models\ApiIdempotencyRecord;
use App\Models\ExternalEmployeeReference;
use App\Models\HrHandover;
use App\Models\HrValidationCase;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\ApiAndMcpPlatformService;
use App\Services\DynamicsHrConnectorService;
use App\Services\TenantContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IntegrationsAndDynamicsController extends Controller
{
    public function __construct(
        private readonly ApiAndMcpPlatformService $apiMcpService,
        private readonly DynamicsHrConnectorService $dynamicsService,
        private readonly TenantContextService $tenantContext,
    ) {}

    public function apiAndMcpIndex(): View
    {
        $clients = ApiClient::query()->latest('id')->get();
        $idempotencyRecords = ApiIdempotencyRecord::query()->latest('id')->limit(20)->get();
        $webhooks = WebhookSubscription::query()->latest('id')->get();
        $deliveries = WebhookDelivery::query()->with('subscription')->latest('id')->limit(20)->get();

        return view('integrations.api_mcp', [
            'clients' => $clients,
            'idempotencyRecords' => $idempotencyRecords,
            'webhooks' => $webhooks,
            'deliveries' => $deliveries,
            'mcpTools' => ApiAndMcpPlatformService::ALLOWLISTED_MCP_TOOLS,
            'prohibitedMcpTools' => ApiAndMcpPlatformService::PROHIBITED_MCP_TOOLS,
        ]);
    }

    public function storeApiClient(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['nullable', 'array'],
        ]);

        $tenantId = (int) $this->tenantContext->requireTenantId();
        $scopes = $validated['scopes'] ?? ['requisitions.view', 'jobs.manage', 'candidates.view', 'applications.manage', 'mcp.use'];

        $created = $this->apiMcpService->createApiClient($validated['name'], $scopes, $tenantId, $request->user());

        return redirect()
            ->route('integrations.api.index')
            ->with(
                'status',
                "API Client '{$created['client']->client_id}' created. One-time Bearer token: {$created['client']->client_id}:{$created['raw_secret']}"
            );
    }

    public function storeWebhook(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'target_url' => ['required', 'url', 'max:255'],
        ]);

        $tenantId = (int) $this->tenantContext->requireTenantId();
        $events = ['application.submitted', 'offer.accepted', 'hr.handover.succeeded'];

        try {
            $sub = $this->apiMcpService->createWebhookSubscription($validated['name'], $validated['target_url'], $events, $tenantId, $request->user());
            $this->apiMcpService->dispatchSignedWebhook($sub, 'webhook.ping', ['message' => 'Initial signed webhook verification ping'], $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['target_url' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('integrations.api.index')
            ->with('status', "Webhook subscription '{$sub->name}' registered and signed verification ping queued for outbound delivery.");
    }

    public function dynamicsIndex(): View
    {
        $tenantId = (int) $this->tenantContext->requireTenantId();
        $config = $this->dynamicsService->ensureConfigForTenant($tenantId);

        $validationCases = HrValidationCase::query()->with(['application.candidate', 'offerVersion'])->latest('id')->get();
        $handovers = HrHandover::query()->with(['externalEmployeeReference'])->latest('id')->limit(20)->get();
        $employeeRefs = ExternalEmployeeReference::query()->with('candidate')->latest('id')->get();

        return view('dynamics.index', [
            'config' => $config,
            'validationCases' => $validationCases,
            'handovers' => $handovers,
            'employeeRefs' => $employeeRefs,
        ]);
    }

    public function updateDynamicsConfig(Request $request): RedirectResponse
    {
        $tenantId = (int) $this->tenantContext->requireTenantId();
        $config = $this->dynamicsService->ensureConfigForTenant($tenantId);

        $validated = $request->validate([
            'environment_url' => ['required', 'url', 'max:255'],
            'connection_mode' => ['required', 'string', 'in:alpha_sandbox,dry_run,live_configured'],
            'real_employee_creation_enabled' => ['nullable', 'boolean'],
        ]);

        $this->dynamicsService->updateConfig($config, $validated, $request->user());

        return redirect()
            ->route('dynamics.index')
            ->with('status', 'Dynamics 365 Finance HR connector configuration updated.');
    }

    public function dryRunValidate(Request $request, string $caseId): RedirectResponse
    {
        $case = HrValidationCase::query()->findOrFail($caseId);
        $result = $this->dynamicsService->dryRunValidateCase($case, $request->user());

        $summary = $result['ready'] ? 'READY FOR HANDOVER' : 'BLOCKED BY GATE CHECKS';

        return redirect()
            ->route('dynamics.index')
            ->with('status', "Dynamics 365 Dry-Run Validation for Case #{$case->id}: {$summary} (Payload SHA-256: ".substr($result['payload_hash'], 0, 14).'...).');
    }
}
