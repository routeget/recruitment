@extends('layouts.app')

@section('title', 'Recruitment Analytics & Operational Funnel')

@section('content')
<div class="mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-1 small">
            <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item active" aria-current="page">Analytics &amp; Funnel Reports</li>
        </ol>
    </nav>
    <h1 class="h3 fw-bold mb-0">Recruitment Funnel, Source Quality &amp; Handover Backlog Analytics</h1>
    <p class="text-muted small mb-0">Computed directly from authorized tenant-scoped database records ({{ $tenant?->name }}).</p>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-bold">Application Pipeline Funnel</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Stage</th>
                            <th class="text-end">Applications</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($stageBreakdown as $stage => $count)
                            <tr>
                                <td><code>{{ $stage }}</code></td>
                                <td class="text-end fw-bold">{{ $count }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="text-center text-muted py-3">No applications</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-bold">Candidate Source Attribution</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Source Channel</th>
                            <th class="text-end">Candidates</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($sourceBreakdown as $source => $count)
                            <tr>
                                <td><code>{{ $source }}</code></td>
                                <td class="text-end fw-bold">{{ $count }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="text-center text-muted py-3">No candidates</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-bold">Offers, HR Handover &amp; AI Governance</div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-8 text-muted">Total Offers Authored</dt>
                    <dd class="col-4 text-end fw-bold">{{ $offersSummary['total_offers'] }}</dd>

                    <dt class="col-8 text-muted">Accepted Offers</dt>
                    <dd class="col-4 text-end fw-bold text-success">{{ $offersSummary['accepted_offers'] }}</dd>

                    <dt class="col-8 text-muted">Handovers Blocked by Safety Gate</dt>
                    <dd class="col-4 text-end fw-bold text-warning">{{ $offersSummary['handover_blocked'] }}</dd>

                    <dt class="col-8 text-muted">Handovers Approved / Dispatched</dt>
                    <dd class="col-4 text-end fw-bold text-primary">{{ $offersSummary['handover_ready_or_queued'] }}</dd>

                    <dt class="col-8 text-muted">Governed AI Task Runs</dt>
                    <dd class="col-4 text-end fw-bold">{{ $aiSummary['total_runs'] }}</dd>

                    <dt class="col-8 text-muted">Estimated AI Usage Cost (USD)</dt>
                    <dd class="col-4 text-end fw-bold">${{ number_format($aiSummary['total_cost_usd'], 4) }}</dd>
                </dl>
            </div>
        </div>
    </div>
</div>
@endsection
