@extends('layouts.app')

@section('title', 'Provider-Neutral AI Gateway & Governance')

@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">AI Gateway &amp; Governance</li>
            </ol>
        </nav>
        <h1 class="h3 fw-bold mb-1">Provider-Neutral AI Gateway &amp; Governance Console</h1>
        <p class="text-muted small mb-0">
            Centralized multi-provider AI orchestration supporting Anthropic Claude, Google Gemini, OpenAI, and the Built-In Structured Rule Analyzer with encrypted credentials, task-specific routing, PII redaction, and mandatory human review.
        </p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-dark">Engineering Workflows (Codex) Excluded from Runtime</span>
        <span class="badge bg-info text-dark">Human-in-the-Loop Enforced</span>
    </div>
</div>

@if($errors->has('ai_gateway'))
    <div class="alert alert-danger">{{ $errors->first('ai_gateway') }}</div>
@endif

{{-- 1. Provider Adapters, Encrypted Credentials & Connection Validation --}}
<div class="card stat-card mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <div>
            <span class="fw-bold">Runtime AI Provider Adapters, Encrypted Credentials &amp; Connection Health</span>
            <div class="small text-muted">API keys are encrypted at rest and write-only in the browser. Connection status reflects live credential and endpoint verification.</div>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-3">
            @foreach($providers as $prov)
                @php
                    $statusBadge = match($prov->connection_status) {
                        'CONNECTED' => 'bg-success',
                        'BUILT_IN_ENGINE' => 'bg-primary',
                        'AUTH_FAILED', 'COMPATIBILITY_ERROR' => 'bg-danger',
                        'RATE_LIMITED', 'DEGRADED' => 'bg-warning text-dark',
                        default => 'bg-secondary',
                    };
                    $supportedModels = is_array($prov->supported_models) && !empty($prov->supported_models)
                        ? $prov->supported_models
                        : [$prov->model_name];
                @endphp
                <div class="col-xl-6">
                    <div class="border rounded p-3 h-100 bg-light bg-opacity-50">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <div class="fw-bold">
                                    {{ $prov->display_name }}
                                    @if($prov->is_primary)
                                        <span class="badge bg-primary ms-1">Primary</span>
                                    @endif
                                </div>
                                <div class="small text-muted">
                                    Active Model: <code>{{ $prov->model_name === 'urap-deterministic-v1' ? 'Governed Rule Engine v1.0' : $prov->model_name }}</code> |
                                    Spend: <strong>${{ number_format($prov->current_spend_minor / 100, 2) }}</strong> / ${{ number_format($prov->monthly_budget_minor / 100, 2) }}
                                </div>
                            </div>
                            <div class="text-end">
                                <span class="badge {{ $statusBadge }}">{{ ucwords(strtolower(str_replace('_', ' ', $prov->connection_status ?? 'UNCONFIGURED'))) }}</span>
                                @if($prov->kill_switch_engaged)
                                    <span class="badge bg-danger ms-1">KILL SWITCH ENGAGED</span>
                                @endif
                                @if($prov->last_validated_at)
                                    <div class="text-muted" style="font-size: 0.72rem;">Verified {{ $prov->last_validated_at->diffForHumans() }}</div>
                                @endif
                            </div>
                        </div>

                        @if($prov->last_error_message)
                            <div class="alert alert-warning py-1 px-2 small mb-2">
                                <strong>{{ $prov->last_error_code ?? 'STATUS' }}:</strong> {{ $prov->last_error_message }}
                            </div>
                        @endif

                        <form method="POST" action="{{ route('ai.providers.update', $prov->id) }}" class="row g-2 align-items-end">
                            @csrf
                            <div class="col-md-5">
                                <label class="form-label small fw-semibold mb-1">Configured Model</label>
                                <select name="model_name" class="form-select form-select-sm">
                                    @foreach($supportedModels as $mName)
                                        <option value="{{ $mName }}" @selected($prov->model_name === $mName)>{{ $mName === 'urap-deterministic-v1' ? 'Governed Rule Engine v1.0' : $mName }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small fw-semibold mb-1">Fallback Provider</label>
                                <select name="fallback_provider_key" class="form-select form-select-sm">
                                    <option value="">None</option>
                                    @foreach($providers as $fbProv)
                                        @if($fbProv->provider_key !== $prov->provider_key)
                                            <option value="{{ $fbProv->provider_key }}" @selected($prov->fallback_provider_key === $fbProv->provider_key)>
                                                {{ $fbProv->display_name }}
                                            </option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label small fw-semibold mb-1">Monthly Cap (¢)</label>
                                <input type="number" name="monthly_budget_minor" class="form-control form-control-sm" value="{{ $prov->monthly_budget_minor }}" min="0">
                            </div>

                            @if($prov->provider_key !== 'deterministic_fake')
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold mb-1">
                                        Encrypted API Key
                                        @if($prov->api_key_masked)
                                            <span class="text-success">(Stored: <code>{{ $prov->api_key_masked }}</code>)</span>
                                        @else
                                            <span class="text-muted">(Not set)</span>
                                        @endif
                                    </label>
                                    <input type="password" name="api_key" class="form-control form-control-sm" placeholder="Enter new API key (write-only)..." autocomplete="new-password">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small fw-semibold mb-1">Timeout (s)</label>
                                    <input type="number" name="timeout_seconds" class="form-control form-control-sm" value="{{ $prov->timeout_seconds ?? 25 }}" min="2" max="120">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small fw-semibold mb-1">Max Retries</label>
                                    <input type="number" name="max_retries" class="form-control form-control-sm" value="{{ $prov->max_retries ?? 2 }}" min="0" max="5">
                                </div>
                            @endif

                            <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2 pt-1">
                                <div class="d-flex gap-3 align-items-center flex-wrap">
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" name="is_enabled" value="1" {{ $prov->is_enabled ? 'checked' : '' }}>
                                        <label class="form-check-label small">Enabled</label>
                                    </div>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" name="redaction_enabled" value="1" {{ $prov->redaction_enabled ? 'checked' : '' }}>
                                        <label class="form-check-label small">PII Redaction</label>
                                    </div>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" name="kill_switch_engaged" value="1" {{ $prov->kill_switch_engaged ? 'checked' : '' }}>
                                        <label class="form-check-label small text-danger fw-semibold">Kill Switch</label>
                                    </div>
                                    @if($prov->api_key_masked)
                                        <div class="form-check mb-0">
                                            <input class="form-check-input" type="checkbox" name="clear_api_key" value="1">
                                            <label class="form-check-label small text-warning">Revoke Key</label>
                                        </div>
                                    @endif
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-sm btn-primary">Save Settings</button>
                                </div>
                            </div>
                        </form>

                        <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                            <div class="small text-muted">
                                Capabilities:
                                @foreach((array) ($prov->capabilities ?? ['structured_json_output', 'resume.extract', 'candidate.match']) as $cap)
                                    <span class="badge bg-white text-dark border me-1">{{ $cap }}</span>
                                @endforeach
                            </div>
                            <form method="POST" action="{{ route('ai.providers.validate', $prov->id) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-dark">Validate Connection</button>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>

{{-- 2. Task-Specific Routing Matrix --}}
@if(isset($taskRoutes) && $taskRoutes->count() > 0)
<div class="card stat-card mb-4">
    <div class="card-header bg-white fw-bold py-3">
        Task-Specific AI Routing &amp; Quality Thresholds
        <span class="text-muted fw-normal small ms-2">Assign primary and fallback providers per recruitment workflow task</span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Workflow Task</th>
                    <th>Primary Provider &amp; Model</th>
                    <th>Fallback Provider</th>
                    <th>Temp / Max Tokens</th>
                    <th>Governance</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($taskRoutes as $routeItem)
                    <tr>
                        <form method="POST" action="{{ route('ai.routes.update', $routeItem->id) }}">
                            @csrf
                            <td>
                                <div class="fw-semibold">{{ $routeItem->display_name }}</div>
                                <code class="small">{{ $routeItem->task_type }}</code>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <select name="primary_provider_key" class="form-select form-select-sm" style="max-width: 175px;">
                                        @foreach($providers as $prov)
                                            <option value="{{ $prov->provider_key }}" @selected($routeItem->primary_provider_key === $prov->provider_key)>
                                                {{ $prov->display_name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <input type="text" name="primary_model_name" class="form-control form-control-sm" value="{{ $routeItem->primary_model_name }}" placeholder="Model name" style="max-width: 185px;">
                                </div>
                            </td>
                            <td>
                                <select name="fallback_provider_key" class="form-select form-select-sm" style="max-width: 185px;">
                                    <option value="">None</option>
                                    @foreach($providers as $prov)
                                        <option value="{{ $prov->provider_key }}" @selected($routeItem->fallback_provider_key === $prov->provider_key)>
                                            {{ $prov->display_name }}
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <div class="d-flex gap-1" style="max-width: 160px;">
                                    <input type="number" step="0.05" name="temperature" class="form-control form-control-sm" value="{{ $routeItem->temperature }}" min="0" max="1" title="Temperature">
                                    <input type="number" name="max_output_tokens" class="form-control form-control-sm" value="{{ $routeItem->max_output_tokens }}" min="128" max="8192" title="Max Output Tokens">
                                </div>
                            </td>
                            <td class="small">
                                <input type="hidden" name="require_structured_output" value="1">
                                <input type="hidden" name="require_human_review" value="1">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_enabled" value="1" {{ $routeItem->is_enabled ? 'checked' : '' }}>
                                    <label class="form-check-label small">Active Route</label>
                                </div>
                            </td>
                            <td>
                                <button type="submit" class="btn btn-sm btn-outline-primary">Save Route</button>
                            </td>
                        </form>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- 3. Execute Task & Prompt Version Registry --}}
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold py-3">Execute Governed Recruitment AI Task</div>
            <div class="card-body">
                <form method="POST" action="{{ route('ai.execute') }}">
                    @csrf
                    <div class="row g-2 mb-3">
                        <div class="col-md-7">
                            <label class="form-label small fw-semibold">Allowed Task Type</label>
                            <select name="task_type" class="form-select form-select-sm" required>
                                @foreach($allowedTasks as $taskKey => $meta)
                                    <option value="{{ $taskKey }}">{{ $meta['title'] }} ({{ $taskKey }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold">Provider Selection</label>
                            <select name="provider_key" class="form-select form-select-sm" required>
                                <option value="auto">AUTO (Task Route Policy)</option>
                                @foreach($providers as $prov)
                                    <option value="{{ $prov->provider_key }}">
                                        {{ $prov->display_name }} — {{ $prov->model_name === 'urap-deterministic-v1' ? 'Governed Rule Engine v1.0' : $prov->model_name }} {{ $prov->kill_switch_engaged ? '[KILL SWITCH ON]' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Input Evidence / Context (Emails, National IDs, and Secrets are Automatically Redacted)</label>
                        <textarea name="raw_input_text" class="form-control form-control-sm" rows="3" placeholder="Provide structured candidate skills, resume evidence, or job requirements for governed analysis..." required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Run Structured AI Task</button>
                </form>

                <hr>
                <div class="small text-muted">
                    <strong>Prohibited Autonomous Uses (Blocked by Policy):</strong>
                    @foreach($prohibitedTasks as $prohibited)
                        <code class="text-danger me-1">{{ $prohibited }}</code>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold py-3">Versioned System Prompt Registry ({{ $prompts->count() }})</div>
            <div class="card-body">
                <form method="POST" action="{{ route('ai.prompts.store') }}" class="row g-2 mb-3">
                    @csrf
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold mb-1">Task Type</label>
                        <select name="task_type" class="form-select form-select-sm" required>
                            @foreach($allowedTasks as $taskKey => $meta)
                                <option value="{{ $taskKey }}">{{ $taskKey }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold mb-1">New Version Tag</label>
                        <input type="text" name="version" class="form-control form-control-sm" placeholder="2.0.0" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold mb-1">System Prompt Instruction</label>
                        <textarea name="system_prompt" class="form-control form-control-sm" rows="2" placeholder="Zero-hallucination extraction instruction..." required></textarea>
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Publish Prompt Version</button>
                    </div>
                </form>

                <div class="table-responsive" style="max-height: 150px; overflow-y: auto;">
                    <table class="table table-sm align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>Task</th>
                                <th>Version</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($prompts->take(10) as $pr)
                                <tr>
                                    <td><code>{{ $pr->task_type }}</code></td>
                                    <td><code>v{{ $pr->version }}</code></td>
                                    <td>
                                        <span class="badge bg-{{ $pr->is_active ? 'success' : 'secondary' }}">
                                            {{ $pr->is_active ? 'Active' : 'Archived' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- 4. AI Task Execution Ledger --}}
<div class="card stat-card">
    <div class="card-header bg-white fw-semibold py-3">AI Task Execution Ledger &amp; Human Review Sign-Off ({{ $taskRuns->count() }})</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Task &amp; Provider</th>
                    <th>Redacted Input Summary</th>
                    <th>Structured Output Summary</th>
                    <th>Schema &amp; Cost</th>
                    <th>Human Review</th>
                </tr>
            </thead>
            <tbody>
                @forelse($taskRuns as $run)
                    <tr>
                        <td>
                            <div class="fw-semibold"><code>{{ $run->task_type }}</code></div>
                            <div class="small text-muted">
                                {{ $run->provider_display_name }} ({{ $run->model_display_name }}) — Prompt v{{ $run->prompt_version }}
                            </div>
                            <div class="mt-1">
                                <span class="badge bg-light text-dark border">{{ $run->execution_mode_display }}</span>
                                @if($run->fallback_used)
                                    <span class="badge bg-warning text-dark">Fallback Activated</span>
                                @endif
                            </div>
                        </td>
                        <td class="small" style="max-width: 260px;">
                            <div class="text-secondary">{{ $run->redacted_input_summary }}</div>
                        </td>
                        <td class="small" style="max-width: 360px;">
                            @php
                                $out = is_array($run->structured_output) ? $run->structured_output : [];
                            @endphp
                            <div class="d-flex flex-wrap gap-1 mb-1">
                                @if(isset($out['recommendation']))
                                    <span class="badge bg-primary">{{ $out['recommendation'] }}</span>
                                @endif
                                @if(isset($out['match_score']))
                                    <span class="badge bg-info text-dark">Match: {{ $out['match_score'] }}%</span>
                                @endif
                                @if(isset($out['confidence']))
                                    <span class="badge bg-light text-dark border">Confidence: {{ is_numeric($out['confidence']) ? round(((float) $out['confidence']) * 100).'%' : $out['confidence'] }}</span>
                                @endif
                                @if(!empty($out['full_name']))
                                    <span class="badge bg-light text-dark border">{{ $out['full_name'] }}</span>
                                @endif
                                @if(!empty($out['skills']) && is_array($out['skills']))
                                    <span class="badge bg-light text-dark border">{{ count($out['skills']) }} Skills Extracted</span>
                                @endif
                            </div>
                            <details class="small">
                                <summary class="text-primary fw-semibold" style="cursor: pointer;">Inspect Structured Output</summary>
                                <pre class="mb-0 mt-1 bg-light p-2 rounded border small" style="max-height: 110px; overflow-y: auto;">{{ json_encode($run->structured_output, JSON_PRETTY_PRINT) }}</pre>
                            </details>
                        </td>
                        <td class="small">
                            <span class="badge bg-{{ $run->schema_valid ? 'success' : 'danger' }}">{{ $run->schema_valid ? 'Schema Valid' : 'Invalid' }}</span>
                            <div class="text-muted mt-1">{{ $run->tokens_used }} tokens (${{ number_format($run->cost_minor / 100, 2) }})</div>
                        </td>
                        <td>
                            @if($run->human_reviewed)
                                <span class="badge bg-success">Reviewed by {{ $run->reviewer?->name ?? 'Reviewer' }}</span>
                            @else
                                <form method="POST" action="{{ route('ai.runs.review', $run->id) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-success">Confirm Human Review</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No AI tasks executed yet. Run a task above to inspect redacted inputs and structured outputs.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
