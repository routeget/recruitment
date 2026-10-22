<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiPromptVersion;
use App\Models\AiProviderConfig;
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
        $prompts = AiPromptVersion::query()->orderBy('task_type')->get();
        $taskRuns = AiTaskRun::query()->with(['user', 'reviewer'])->latest('id')->limit(25)->get();

        return view('ai.index', [
            'providers' => $providers,
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
            'provider_key' => ['required', 'string', 'in:claude,gemini,openai,deterministic_fake'],
            'raw_input_text' => ['required', 'string', 'min:10', 'max:5000'],
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
        $run = AiTaskRun::query()->findOrFail($id);
        $this->aiService->reviewTaskRun($run, $request->user());

        return redirect()
            ->route('ai.index')
            ->with('status', "AI Task Run #{$run->id} marked as human-reviewed.");
    }

    public function updateProviderConfig(Request $request, string $id): RedirectResponse
    {
        $config = AiProviderConfig::query()->findOrFail($id);

        $validated = $request->validate([
            'kill_switch_engaged' => ['nullable', 'boolean'],
            'is_enabled' => ['nullable', 'boolean'],
            'monthly_budget_minor' => ['required', 'integer', 'min:0'],
            'redaction_enabled' => ['nullable', 'boolean'],
        ]);

        $this->aiService->updateProviderConfig($config, $validated, $request->user());

        return redirect()
            ->route('ai.index')
            ->with('status', "AI provider '{$config->provider_key}' governance settings updated.");
    }
}
