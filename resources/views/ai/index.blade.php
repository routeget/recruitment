@extends('layouts.app')

@section('title', 'Provider-Neutral AI Gateway & Governance')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 fw-bold mb-1">Provider-Neutral AI Gateway &amp; Governance Console</h2>
        <p class="text-muted small mb-0">
            Supports Claude, Gemini, OpenAI, and DeterministicFake adapters with PII/secret redaction, prompt version registry, monthly budget caps, tenant kill-switches, and mandatory human review.
        </p>
    </div>
    <span class="badge bg-info text-dark">Human-in-the-Loop Enforced</span>
</div>

@if($errors->has('ai_gateway'))
    <div class="alert alert-danger">{{ $errors->first('ai_gateway') }}</div>
@endif

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
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
                            <label class="form-label small fw-semibold">Provider Adapter</label>
                            <select name="provider_key" class="form-select form-select-sm" required>
                                @foreach($providers as $prov)
                                    <option value="{{ $prov->provider_key }}">
                                        {{ strtoupper($prov->provider_key) }} — {{ $prov->model_name }} {{ $prov->kill_switch_engaged ? '[KILL SWITCH ON]' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Input Evidence / Context (Emails, SSNs, and Secrets are Automatically Redacted)</label>
                        <textarea name="raw_input_text" class="form-control form-control-sm" rows="3" required>Candidate Jane Doe (jane.doe@example.org, SSN 123-45-6789) has 8 years of production PHP 8.5, Laravel 13, MariaDB, Redis, and enterprise ERP integration experience.</textarea>
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
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold py-3">Provider Adapters, Monthly Budgets &amp; Kill-Switches</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Provider &amp; Model</th>
                            <th>Spend / Budget</th>
                            <th>Kill Switch</th>
                            <th>Update</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($providers as $prov)
                            <tr>
                                <form method="POST" action="{{ route('ai.providers.update', $prov->id) }}">
                                    @csrf
                                    <td>
                                        <div class="fw-semibold">{{ strtoupper($prov->provider_key) }}</div>
                                        <div class="small text-muted"><code>{{ $prov->model_name }}</code></div>
                                    </td>
                                    <td class="small">
                                        <div>Spent: ${{ number_format($prov->current_spend_minor / 100, 2) }}</div>
                                        <div class="input-group input-group-sm mt-1" style="width: 130px;">
                                            <span class="input-group-text">Cap</span>
                                            <input type="number" name="monthly_budget_minor" class="form-control" value="{{ $prov->monthly_budget_minor }}">
                                        </div>
                                    </td>
                                    <td>
                                        <input type="hidden" name="is_enabled" value="1">
                                        <input type="hidden" name="redaction_enabled" value="1">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="kill_switch_engaged" value="1" {{ $prov->kill_switch_engaged ? 'checked' : '' }}>
                                            <label class="form-check-label small text-danger">Kill Switch</label>
                                        </div>
                                    </td>
                                    <td>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Save</button>
                                    </td>
                                </form>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold py-3">AI Task Execution Ledger &amp; Human Review Sign-Off ({{ $taskRuns->count() }})</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Task &amp; Provider</th>
                    <th>Redacted Input Summary</th>
                    <th>Structured JSON Output</th>
                    <th>Schema &amp; Cost</th>
                    <th>Human Review</th>
                </tr>
            </thead>
            <tbody>
                @forelse($taskRuns as $run)
                    <tr>
                        <td>
                            <div class="fw-semibold"><code>{{ $run->task_type }}</code></div>
                            <div class="small text-muted">{{ strtoupper($run->provider_key) }} ({{ $run->model_name }}) — Prompt v{{ $run->prompt_version }}</div>
                        </td>
                        <td class="small" style="max-width: 260px;">
                            <code>{{ $run->redacted_input_summary }}</code>
                        </td>
                        <td class="small" style="max-width: 340px;">
                            <pre class="mb-0 bg-light p-2 rounded small" style="max-height: 110px; overflow-y: auto;">{{ json_encode($run->structured_output, JSON_PRETTY_PRINT) }}</pre>
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
