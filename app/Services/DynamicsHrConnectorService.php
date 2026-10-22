<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DynamicsIntegrationConfig;
use App\Models\HrValidationCase;
use App\Models\User;

class DynamicsHrConnectorService
{
    public const DEFAULT_FIELD_OWNERSHIP_MATRIX = [
        ['field' => 'requisition_id / application_id', 'master_system' => 'URAP', 'sync_direction' => 'URAP -> Dynamics (Reference)', 'mutability' => 'Immutable after handover'],
        ['field' => 'candidate_first_name / last_name / email', 'master_system' => 'URAP (Pre-Hire) -> Dynamics (Post-Hire)', 'sync_direction' => 'URAP -> Dynamics on Handover', 'mutability' => 'HR-controlled in Dynamics after hire'],
        ['field' => 'legal_entity_code / company', 'master_system' => 'DYNAMICS_365_FINANCE_HR', 'sync_direction' => 'Dynamics <-> URAP Validated', 'mutability' => 'Material change invalidates HR validation'],
        ['field' => 'position_code / department_code', 'master_system' => 'DYNAMICS_365_FINANCE_HR', 'sync_direction' => 'Dynamics <-> URAP Validated', 'mutability' => 'Material change invalidates HR validation'],
        ['field' => 'base_salary_minor / currency_code', 'master_system' => 'URAP (OfferVersion) -> Dynamics', 'sync_direction' => 'URAP -> Dynamics on Handover', 'mutability' => 'Material change invalidates HR validation'],
        ['field' => 'start_date', 'master_system' => 'URAP (OfferVersion) -> Dynamics', 'sync_direction' => 'URAP -> Dynamics on Handover', 'mutability' => 'Material change invalidates HR validation'],
        ['field' => 'external_employee_id / worker_number', 'master_system' => 'DYNAMICS_365_FINANCE_HR', 'sync_direction' => 'Dynamics -> URAP (ExternalEmployeeReference)', 'mutability' => 'System-assigned by Dynamics 365'],
    ];

    public function __construct(
        private readonly AuditService $auditService,
        private readonly OfferAndHrHandoverService $handoverService,
    ) {}

    public function ensureConfigForTenant(int $tenantId): DynamicsIntegrationConfig
    {
        return DynamicsIntegrationConfig::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId],
            [
                'environment_url' => 'https://sandbox.operations.dynamics.com',
                'connection_mode' => 'alpha_sandbox',
                'real_employee_creation_enabled' => false,
                'legal_entity_mappings' => [
                    'USMF' => 'Contoso Entertainment System USA (Sandbox)',
                    'DEMF' => 'Contoso Entertainment System Germany (Sandbox)',
                ],
                'field_ownership_matrix' => self::DEFAULT_FIELD_OWNERSHIP_MATRIX,
                'last_health_check_at' => now(),
                'last_health_status' => 'SANDBOX_READY_LIVE_DISABLED',
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateConfig(DynamicsIntegrationConfig $config, array $data, User $actor): DynamicsIntegrationConfig
    {
        $config->update([
            'environment_url' => trim((string) ($data['environment_url'] ?? $config->environment_url)),
            'connection_mode' => (string) ($data['connection_mode'] ?? $config->connection_mode),
            'real_employee_creation_enabled' => (bool) ($data['real_employee_creation_enabled'] ?? false),
            'last_health_check_at' => now(),
            'last_health_status' => ! empty($data['real_employee_creation_enabled'])
                ? 'AWAITING_OAUTH_FAIL_CLOSED'
                : 'SANDBOX_READY_LIVE_DISABLED',
        ]);

        $this->auditService->record(
            module: 'dynamics_connector',
            action: 'dynamics.config_updated',
            entityType: 'dynamics_integration_config',
            entityId: (string) $config->id,
            metadata: [
                'connection_mode' => $config->connection_mode,
                'real_employee_creation_enabled' => $config->real_employee_creation_enabled,
            ],
            tenantId: (int) $config->tenant_id,
            actorUserId: (int) $actor->id,
        );

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    public function dryRunValidateCase(HrValidationCase $case, User $actor): array
    {
        $payload = $this->handoverService->buildCanonicalHandoverPayload($case);
        $payloadHash = hash('sha256', (string) json_encode($payload));

        $checks = [
            'checklist_complete' => $case->isChecklistComplete(),
            'not_invalidated' => $case->invalidated_at === null,
            'has_explicit_hr_approval' => $case->approvals()->where('decision', 'approved')->exists(),
            'legal_entity_mapped' => ! empty($payload['legal_entity_code']),
            'position_code_present' => ! empty($payload['position_code']),
        ];

        $ready = ! in_array(false, $checks, true);

        $this->auditService->record(
            module: 'dynamics_connector',
            action: 'dynamics.dry_run_validated',
            entityType: 'hr_validation_case',
            entityId: (string) $case->id,
            metadata: [
                'ready' => $ready,
                'payload_hash' => $payloadHash,
                'checks' => $checks,
            ],
            tenantId: (int) $case->tenant_id,
            actorUserId: (int) $actor->id,
        );

        return [
            'ready' => $ready,
            'payload_hash' => $payloadHash,
            'checks' => $checks,
            'canonical_payload' => $payload,
        ];
    }
}
