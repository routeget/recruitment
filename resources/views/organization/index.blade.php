@extends('layouts.app')

@section('title', 'Organization Setup — ' . $tenant->name)

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Organization Setup</li>
            </ol>
        </nav>
        <h1 class="h3 fw-bold mb-0">Organization Structure &amp; Reference Entities</h1>
        <p class="text-muted small mb-0">Manage tenant-scoped Legal Entities, Departments, Cost Centres, and Locations.</p>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-bold">Legal Entities ({{ $legalEntities->count() }})</div>
            <div class="card-body">
                <form method="POST" action="{{ route('organization.legal-entities.store') }}" class="mb-3">
                    @csrf
                    <div class="row g-2 mb-2">
                        <div class="col-4">
                            <label for="le_code" class="form-label small fw-semibold">Code</label>
                            <input type="text" id="le_code" name="code" class="form-control form-control-sm" required placeholder="USMF">
                        </div>
                        <div class="col-8">
                            <label for="le_name" class="form-label small fw-semibold">Legal Entity Name</label>
                            <input type="text" id="le_name" name="name" class="form-control form-control-sm" required placeholder="Acme US Operations LLC">
                        </div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-4">
                            <label for="le_country" class="form-label small fw-semibold">Country</label>
                            <input type="text" id="le_country" name="country_code" class="form-control form-control-sm" value="US" required maxlength="2">
                        </div>
                        <div class="col-4">
                            <label for="le_currency" class="form-label small fw-semibold">Currency</label>
                            <input type="text" id="le_currency" name="currency_code" class="form-control form-control-sm" value="USD" required maxlength="3">
                        </div>
                        <div class="col-4">
                            <label for="le_dyn" class="form-label small fw-semibold">D365 Co.</label>
                            <input type="text" id="le_dyn" name="dynamics_company_code" class="form-control form-control-sm" placeholder="USMF">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary w-100">Save Legal Entity</button>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Curr / D365</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($legalEntities as $le)
                                <tr>
                                    <td><code>{{ $le->code }}</code></td>
                                    <td class="small">{{ $le->name }}</td>
                                    <td class="small">{{ $le->currency_code }} / <code>{{ $le->dynamics_company_code }}</code></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-bold">Departments ({{ $departments->count() }})</div>
            <div class="card-body">
                <form method="POST" action="{{ route('organization.departments.store') }}" class="mb-3">
                    @csrf
                    <div class="row g-2 mb-2">
                        <div class="col-4">
                            <label for="dept_code" class="form-label small fw-semibold">Code</label>
                            <input type="text" id="dept_code" name="code" class="form-control form-control-sm" required placeholder="ENG">
                        </div>
                        <div class="col-8">
                            <label for="dept_name" class="form-label small fw-semibold">Department Name</label>
                            <input type="text" id="dept_name" name="name" class="form-control form-control-sm" required placeholder="AI Platform Engineering">
                        </div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-7">
                            <label for="dept_le" class="form-label small fw-semibold">Legal Entity</label>
                            <select id="dept_le" name="legal_entity_id" class="form-select form-select-sm">
                                <option value="">Any / Global</option>
                                @foreach($legalEntities as $le)
                                    <option value="{{ $le->id }}">{{ $le->code }} — {{ $le->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-5">
                            <label for="dept_cc" class="form-label small fw-semibold">Cost Centre</label>
                            <input type="text" id="dept_cc" name="cost_centre_code" class="form-control form-control-sm" placeholder="CC-4100">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary w-100">Save Department</button>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Cost Centre</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($departments as $dept)
                                <tr>
                                    <td><code>{{ $dept->code }}</code></td>
                                    <td class="small">{{ $dept->name }}</td>
                                    <td class="small"><code>{{ $dept->cost_centre_code ?? '—' }}</code></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-bold">Locations ({{ $locations->count() }})</div>
            <div class="card-body">
                <form method="POST" action="{{ route('organization.locations.store') }}" class="mb-3">
                    @csrf
                    <div class="row g-2 mb-2">
                        <div class="col-4">
                            <label for="loc_code" class="form-label small fw-semibold">Code</label>
                            <input type="text" id="loc_code" name="code" class="form-control form-control-sm" required placeholder="NYC-HQ">
                        </div>
                        <div class="col-8">
                            <label for="loc_name" class="form-label small fw-semibold">Location Name</label>
                            <input type="text" id="loc_name" name="name" class="form-control form-control-sm" required placeholder="New York Headquarters">
                        </div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-5">
                            <label for="loc_city" class="form-label small fw-semibold">City</label>
                            <input type="text" id="loc_city" name="city" class="form-control form-control-sm" required placeholder="New York">
                        </div>
                        <div class="col-3">
                            <label for="loc_country" class="form-label small fw-semibold">Country</label>
                            <input type="text" id="loc_country" name="country_code" class="form-control form-control-sm" value="US" required maxlength="2">
                        </div>
                        <div class="col-4">
                            <label for="loc_mode" class="form-label small fw-semibold">Work Mode</label>
                            <select id="loc_mode" name="work_mode" class="form-select form-select-sm" required>
                                <option value="HYBRID">HYBRID</option>
                                <option value="ONSITE">ONSITE</option>
                                <option value="REMOTE">REMOTE</option>
                            </select>
                        </div>
                    </div>
                    <input type="hidden" name="timezone" value="{{ $tenant->default_timezone ?? 'UTC' }}">
                    <button type="submit" class="btn btn-sm btn-primary w-100">Save Location</button>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>City</th>
                                <th>Mode</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($locations as $loc)
                                <tr>
                                    <td><code>{{ $loc->code }}</code></td>
                                    <td class="small">{{ $loc->city }}, {{ $loc->country_code }}</td>
                                    <td><span class="badge bg-light text-dark border">{{ $loc->work_mode }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
