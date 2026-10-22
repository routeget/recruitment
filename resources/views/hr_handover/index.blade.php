@extends('layouts.app')

@section('title', 'HR Validation & Handover Safety Gate')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 fw-bold mb-1">Mandatory HR Validation &amp; Employee Handover Safety Gate</h2>
        <p class="text-muted small mb-0">
            Enforces non-negotiable safety rules <code>HRH-01</code> through <code>HRH-08</code>: Accepted Offer Version &rarr; Completed Versioned HR Checklist &rarr; Explicit HR Approver Sign-off &rarr; Atomic Snapshot + Transactional Outbox &rarr; Idempotent Dynamics 365 Finance HR Handover.
        </p>
    </div>
    <span class="badge bg-danger">Mandatory Safety Gate Active</span>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold py-3">Active HR Validation Cases ({{ $cases->count() }})</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Candidate &amp; Offer Version</th>
                    <th>Gate Status</th>
                    <th>Checklist Verification (Step 1)</th>
                    <th>Explicit HR Approval (Step 2)</th>
                    <th>Handover Dispatch (Step 3)</th>
                </tr>
            </thead>
            <tbody>
                @forelse($cases as $case)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $case->application?->candidate?->first_name }} {{ $case->application?->candidate?->last_name }}</div>
                            <div class="small text-muted">
                                {{ $case->offerVersion?->job_title }} (Offer v{{ $case->offerVersion?->version_number }})
                            </div>
                            <div class="small text-muted">
                                Salary: {{ $case->offerVersion?->currency_code }} {{ number_format(($case->offerVersion?->base_salary_minor ?? 0) / 100, 2) }} | Start: {{ $case->offerVersion?->start_date?->format('Y-m-d') }}
                            </div>
                            @if($case->invalidated_at)
                                <div class="alert alert-danger py-1 px-2 mt-1 mb-0 small">
                                    <strong>Invalidated ({{ $case->invalidated_at->format('H:i:s') }}):</strong> {{ $case->invalidation_reason }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <span class="badge bg-{{ $case->status === 'handed_over' ? 'success' : ($case->status === 'approved_for_handover' ? 'primary' : ($case->invalidated_at ? 'danger' : 'warning text-dark')) }}">
                                {{ $case->status }}
                            </span>
                            <div class="small text-muted mt-1">Checklist v{{ $case->checklist_version }}</div>
                        </td>
                        <td>
                            <form method="POST" action="{{ route('hr-handover.checklist', $case->id) }}" class="small">
                                @csrf
                                <div class="row g-1 mb-1">
                                    <div class="col-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="identity_verified" value="1" id="id_{{ $case->id }}" {{ $case->identity_verified_at ? 'checked' : '' }}>
                                            <label class="form-check-label" for="id_{{ $case->id }}">Identity</label>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="right_to_work_verified" value="1" id="rtw_{{ $case->id }}" {{ $case->right_to_work_verified_at ? 'checked' : '' }}>
                                            <label class="form-check-label" for="rtw_{{ $case->id }}">Right to Work</label>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="compensation_validated" value="1" id="comp_{{ $case->id }}" {{ $case->compensation_validated_at ? 'checked' : '' }}>
                                            <label class="form-check-label" for="comp_{{ $case->id }}">Compensation</label>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="position_mapping_validated" value="1" id="pos_{{ $case->id }}" {{ $case->position_mapping_validated_at ? 'checked' : '' }}>
                                            <label class="form-check-label" for="pos_{{ $case->id }}">Position Map</label>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="legal_entity_validated" value="1" id="le_{{ $case->id }}" {{ $case->legal_entity_validated_at ? 'checked' : '' }}>
                                            <label class="form-check-label" for="le_{{ $case->id }}">Legal Entity</label>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="start_date_confirmed" value="1" id="sd_{{ $case->id }}" {{ $case->start_date_confirmed_at ? 'checked' : '' }}>
                                            <label class="form-check-label" for="sd_{{ $case->id }}">Start Date</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex gap-1 mb-1">
                                    <select name="background_check_status" class="form-select form-select-sm">
                                        @foreach(['pending', 'passed', 'waived', 'failed'] as $bg)
                                            <option value="{{ $bg }}" {{ $case->background_check_status === $bg ? 'selected' : '' }}>BG: {{ $bg }}</option>
                                        @endforeach
                                    </select>
                                    <input type="text" name="position_code" class="form-control form-control-sm" value="{{ $case->position_code ?? 'POS-DYN-100' }}" placeholder="Position Code">
                                </div>
                                <button type="submit" class="btn btn-sm btn-outline-primary w-100">Save HR Checklist</button>
                            </form>
                        </td>
                        <td>
                            @php
                                $activeApproval = $case->approvals->firstWhere('decision', 'approved');
                            @endphp
                            @if($activeApproval)
                                <div class="small text-success fw-semibold mb-1">Approved by {{ $activeApproval->approver?->name }}</div>
                                <div class="small text-muted mb-1">Hash: <code>{{ substr($activeApproval->approved_payload_hash, 0, 12) }}...</code></div>
                            @else
                                <div class="small text-muted mb-1">No active approval</div>
                            @endif
                            <form method="POST" action="{{ route('hr-handover.approve', $case->id) }}">
                                @csrf
                                <input type="text" name="comment" class="form-control form-control-sm mb-1" placeholder="HR Approver sign-off note">
                                <button type="submit" class="btn btn-sm btn-success w-100">Explicit HR Approver Sign-Off</button>
                            </form>
                        </td>
                        <td>
                            <form method="POST" action="{{ route('hr-handover.dispatch', $case->id) }}">
                                @csrf
                                <input type="text" name="idempotency_key" class="form-control form-control-sm mb-1" value="hr-handover-{{ substr($case->id, 0, 8) }}-v{{ $case->checklist_version }}">
                                <select name="simulated_outcome" class="form-select form-select-sm mb-1">
                                    <option value="succeeded">Execute Dynamics 365 Sandbox Handover</option>
                                    <option value="ambiguous_timeout">Simulate Ambiguous Timeout (HRH-06)</option>
                                </select>
                                <button type="submit" class="btn btn-sm btn-dark w-100">Dispatch Handover</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No HR validation cases open yet. Accept an offer first to open a validation case.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold py-3">HR Handover Execution &amp; Reconciliation Ledger</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Idempotency Key</th>
                            <th>Status</th>
                            <th>External Employee Ref</th>
                            <th>Attempts</th>
                            <th>Reconciliation Control</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($handovers as $ho)
                            <tr>
                                <td><code class="small">{{ $ho->idempotency_key }}</code></td>
                                <td>
                                    <span class="badge bg-{{ in_array($ho->status, ['succeeded', 'reconciled'], true) ? 'success' : ($ho->requires_reconciliation ? 'danger' : 'secondary') }}">
                                        {{ $ho->status }}
                                    </span>
                                </td>
                                <td class="small">
                                    @if($ho->external_employee_id)
                                        <code>{{ $ho->external_employee_id }}</code>
                                    @elseif($ho->error_code)
                                        <span class="text-danger">{{ $ho->error_code }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>{{ $ho->attempt_count }}</td>
                                <td>
                                    @if($ho->requires_reconciliation)
                                        <form method="POST" action="{{ route('hr-handover.reconcile', $ho->id) }}" class="d-flex gap-1">
                                            @csrf
                                            <select name="found_in_external_system" class="form-select form-select-sm">
                                                <option value="1">Worker Found (Link Existing)</option>
                                                <option value="0">Not Found (Clear for Retry)</option>
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-warning">Reconcile</button>
                                        </form>
                                    @else
                                        <span class="text-muted small">Verified</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-3">No handover executions recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold py-3">External Employee References &amp; Outbox ({{ $employeeRefs->count() }} synced)</div>
            <div class="card-body">
                <h6 class="small fw-bold text-uppercase text-muted">Dynamics 365 Finance HR Worker References</h6>
                <ul class="list-group list-group-flush small mb-3">
                    @forelse($employeeRefs as $ref)
                        <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                            <div>
                                <strong>{{ $ref->candidate?->first_name }} {{ $ref->candidate?->last_name }}</strong>
                                <div class="text-muted">Entity: {{ $ref->legal_entity_code }} | Worker: <code>{{ $ref->external_worker_number }}</code></div>
                            </div>
                            <span class="badge bg-success">{{ $ref->external_employee_id }}</span>
                        </li>
                    @empty
                        <li class="list-group-item px-0 text-muted">No external employee references created yet.</li>
                    @endforelse
                </ul>

                <h6 class="small fw-bold text-uppercase text-muted">Transactional Outbox Events ({{ $outboxEvents->count() }})</h6>
                <ul class="list-group list-group-flush small">
                    @forelse($outboxEvents as $evt)
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span><code>{{ $evt->event_type }}</code> ({{ $evt->idempotency_key }})</span>
                            <span class="badge bg-{{ $evt->status === 'dispatched' ? 'success' : 'warning text-dark' }}">{{ $evt->status }}</span>
                        </li>
                    @empty
                        <li class="list-group-item px-0 text-muted">No outbox events queued yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
