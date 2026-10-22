<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiPromptVersion;
use App\Models\AiProviderConfig;
use App\Models\AiTaskRoute;
use App\Models\AiTaskRun;
use App\Services\AiGatewayService;
use App\Services\TenantContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

class AiGatewayController extends Controller
{
    public function __construct(
        private readonly AiGatewayService $aiService,
        private readonly TenantContextService $tenantContext,
    ) {}

    public function index(): View
    {
        $tenantId = (int) $this->tenantContext->requireTenantId();
        $this->aiService->ensureDefaultsForTenant($tenantId);

        $providers = AiProviderConfig::query()->orderByDesc('is_primary')->orderBy('provider_key')->get();
        $taskRoutes = AiTaskRoute::query()->orderBy('task_type')->get();
        $prompts = AiPromptVersion::query()->orderBy('task_type')->orderByDesc('id')->get();
        $taskRuns = AiTaskRun::query()->with(['user', 'reviewer'])->latest('id')->limit(25)->get();

        return view('ai.index', [
            'providers' => $providers,
            'taskRoutes' => $taskRoutes,
            'prompts' => $prompts,
            'taskRuns' => $taskRuns,
            'allowedTasks' => AiGatewayService::ALLOWED_TASKS,
            'prohibitedTasks' => AiGatewayService::PROHIBITED_TASKS,
        ]);
    }

    public function executeTask(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'task_type' => ['required', 'string', 'max:64'],
            'provider_key' => ['required', 'string', 'max:64'],
            'raw_input_text' => ['required', 'string', 'min:10', 'max:15000'],
        ]);

        $tenantId = (int) $this->tenantContext->requireTenantId();

        try {
            $run = $this->aiService->executeTask(
                $validated['task_type'],
                $validated['provider_key'],
                $validated['raw_input_text'],
                $request->user(),
                $tenantId,
            );

            return redirect()
                ->route('ai.index')
                ->with('status', "AI task '{$run->task_type}' executed via {$run->provider_key} ({$run->model_name}) with PII redaction and schema validation.");
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()
                ->route('ai.index')
                ->withErrors(['ai_gateway' => $e->getMessage()]);
        }
    }

    public function reviewTaskRun(Request $request, string $id): RedirectResponse
    {
        $run = AiTaskRun::withoutGlobalScopes()->findOrFail($id);
        $this->tenantContext->assertModelBelongsToActiveTenant($run, 'AiTaskRun');

        $this->aiService->reviewTaskRun($run, $request->user());

        return redirect()
            ->route('ai.index')
            ->with('status', "AI Task Run #{$run->id} marked as human-reviewed.");
    }

    public function updateProviderConfig(Request $request, string $id): RedirectResponse
    {
        $config = AiProviderConfig::withoutGlobalScopes()->findOrFail($id);
        $this->tenantContext->assertModelBelongsToActiveTenant($config, 'AiProviderConfig');

        $validated = $request->validate([
            'model_name' => ['nullable', 'string', 'max:80'],
            'api_key' => ['nullable', 'string', 'max:512'],
            'clear_api_key' => ['nullable', 'boolean'],
            'endpoint_url' => ['nullable', 'url', 'max:255'],
            'fallback_provider_key' => ['nullable', 'string', 'max:40'],
            'timeout_seconds' => ['nullable', 'integer', 'min:2', 'max:120'],
            'max_retries' => ['nullable', 'integer', 'min:0', 'max:5'],
            'rate_limit_rpm' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'is_primary' => ['nullable', 'boolean'],
            'kill_switch_engaged' => ['nullable', 'boolean'],
            'is_enabled' => ['nullable', 'boolean'],
            'monthly_budget_minor' => ['required', 'integer', 'min:0'],
            'redaction_enabled' => ['nullable', 'boolean'],
        ]);

        try {
            $this->aiService->updateProviderConfig($config, $validated, $request->user());

            return redirect()
                ->route('ai.index')
                ->with('status', "AI provider '{$config->provider_key}' configuration and governance settings updated.");
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()
                ->route('ai.index')
                ->withErrors(['ai_gateway' => $e->getMessage()]);
        }
    }

    public function validateProviderConnection(Request $request, string $id): RedirectResponse
    {
        $config = AiProviderConfig::withoutGlobalScopes()->findOrFail($id);
        $this->tenantContext->assertModelBelongsToActiveTenant($config, 'AiProviderConfig');

        $result = $this->aiService->validateProviderConnection($config, $request->user());

        if ($result['ok']) {
            return redirect()
                ->route('ai.index')
                ->with('status', "Connection validated for '{$config->display_name}' ({$result['status']}): {$result['message']}");
        }

        return redirect()
            ->route('ai.index')
            ->withErrors(['ai_gateway' => "Connection validation for '{$config->display_name}' returned {$result['status']}: {$result['message']}"]);
    }

    public function updateTaskRoute(Request $request, string $id): RedirectResponse
    {
        $route = AiTaskRoute::withoutGlobalScopes()->findOrFail($id);
        $this->tenantContext->assertModelBelongsToActiveTenant($route, 'AiTaskRoute');

        $validated = $request->validate([
            'primary_provider_key' => ['required', 'string', 'max:40'],
            'primary_model_name' => ['nullable', 'string', 'max:80'],
            'fallback_provider_key' => ['nullable', 'string', 'max:40'],
            'fallback_model_name' => ['nullable', 'string', 'max:80'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'max_output_tokens' => ['nullable', 'integer', 'min:128', 'max:8192'],
            'timeout_seconds' => ['nullable', 'integer', 'min:2', 'max:120'],
            'max_retries' => ['nullable', 'integer', 'min:0', 'max:5'],
            'quality_threshold' => ['nullable', 'integer', 'min:0', 'max:100'],
            'require_structured_output' => ['nullable', 'boolean'],
            'require_human_review' => ['nullable', 'boolean'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        try {
            $this->aiService->updateTaskRoute($route, $validated, $request->user());

            return redirect()
                ->route('ai.index')
                ->with('status', "AI task route '{$route->task_type}' updated.");
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()
                ->route('ai.index')
                ->withErrors(['ai_gateway' => $e->getMessage()]);
        }
    }

    public function storePromptVersion(Request $request): RedirectResponse
    {
        $tenantId = (int) $this->tenantContext->requireTenantId();

        $validated = $request->validate([
            'task_type' => ['required', 'string', 'max:64'],
            'version' => ['required', 'string', 'max:32'],
            'system_prompt' => ['required', 'string', 'min:10', 'max:4000'],
            'output_schema_json' => ['nullable', 'string', 'max:4000'],
        ]);

        try {
            $prompt = $this->aiService->createPromptVersion($tenantId, $validated, $request->user());

            return redirect()
                ->route('ai.index')
                ->with('status', "Activated prompt version {$prompt->version} for task '{$prompt->task_type}'.");
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()
                ->route('ai.index')
                ->withErrors(['ai_gateway' => $e->getMessage()]);
        }
    }
}
