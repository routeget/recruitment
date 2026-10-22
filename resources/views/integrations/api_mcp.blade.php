@extends('layouts.app')

@section('title', 'REST API v1, Webhooks & MCP Server')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 fw-bold mb-1">REST API Platform (<code>/api/v1</code>), Signed Webhooks &amp; MCP Server (<code>/mcp/v1</code>)</h2>
        <p class="text-muted small mb-0">
            Manage scoped API clients, inspect idempotency records (<code>IDEMPOTENCY_CONFLICT</code> protection), configure HMAC-SHA256 webhooks, and review allowlisted MCP tools.
        </p>
    </div>
    <span class="badge bg-primary">API-First + Governed MCP</span>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold py-3">Create Tenant-Scoped API Client (<code>/api/v1</code> &amp; <code>/mcp/v1</code>)</div>
            <div class="card-body">
                <form method="POST" action="{{ route('integrations.api.clients.store') }}" class="mb-3">
                    @csrf
                    <div class="input-group input-group-sm">
                        <input type="text" name="name" class="form-control" placeholder="Enterprise ATS / ERP Integration Client" required>
                        <button type="submit" class="btn btn-primary">Generate API Client</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Client ID</th>
                                <th>Scopes</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($clients as $cli)
                                <tr>
                                    <td class="fw-semibold small">{{ $cli->name }}</td>
                                    <td><code class="small">{{ $cli->client_id }}</code></td>
                                    <td class="small">{{ implode(', ', $cli->scopes ?? []) }}</td>
                                    <td><span class="badge bg-success">Active</span></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-muted small text-center py-2">No API clients generated yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold py-3">Outbound HMAC-SHA256 Signed Webhooks</div>
            <div class="card-body">
                <form method="POST" action="{{ route('integrations.api.webhooks.store') }}" class="row g-2 mb-3">
                    @csrf
                    <div class="col-md-5">
                        <input type="text" name="name" class="form-control form-control-sm" placeholder="Downstream HR Analytics" required>
                    </div>
                    <div class="col-md-5">
                        <input type="url" name="target_url" class="form-control form-control-sm" placeholder="https://hooks.example.org/urap" required>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-outline-primary btn-sm w-100">Add</button>
                    </div>
                </form>

                <div class="small fw-semibold mb-1">Recent Signed Deliveries ({{ $deliveries->count() }})</div>
                <ul class="list-group list-group-flush small">
                    @forelse($deliveries as $del)
                        <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                            <div>
                                <code>{{ $del->event_type }}</code> ({{ $del->event_id }})
                                <div class="text-muted">Sig: <code>{{ substr($del->signature_header, 0, 28) }}...</code></div>
                            </div>
                            <span class="badge bg-success">{{ $del->status }} (HTTP {{ $del->response_status }})</span>
                        </li>
                    @empty
                        <li class="list-group-item px-0 text-muted">No webhook deliveries recorded yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold py-3">Allowlisted MCP Server Tools (<code>/mcp/v1/tools</code> &amp; <code>/mcp/v1/invoke</code>)</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tool Name</th>
                            <th>Category</th>
                            <th>Required Permission</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($mcpTools as $name => $meta)
                            <tr>
                                <td><code>{{ $name }}</code></td>
                                <td><span class="badge bg-secondary">{{ $meta['category'] }}</span></td>
                                <td><code>{{ $meta['required_permission'] }}</code></td>
                                <td class="small">{{ $meta['description'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white small text-muted">
                <strong>Prohibited MCP Tools (Hard-Blocked):</strong>
                @foreach($prohibitedMcpTools as $blocked)
                    <code class="text-danger me-1">{{ $blocked }}</code>
                @endforeach
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold py-3">API Idempotency Ledger (<code>Idempotency-Key</code>)</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Idempotency Key</th>
                            <th>Endpoint</th>
                            <th>HTTP Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($idempotencyRecords as $rec)
                            <tr>
                                <td><code class="small">{{ $rec->idempotency_key }}</code></td>
                                <td class="small"><code>{{ $rec->method }} {{ $rec->path }}</code></td>
                                <td><span class="badge bg-info text-dark">{{ $rec->response_status }}</span></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted small py-3">No API idempotency records stored yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
