<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HrHandover extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'hr_validation_case_id',
        'approval_id',
        'idempotency_key',
        'status',
        'target_system',
        'payload_snapshot',
        'payload_hash',
        'external_request_id',
        'external_employee_id',
        'error_code',
        'error_detail',
        'attempt_count',
        'requires_reconciliation',
        'last_reconciled_at',
        'dispatched_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_snapshot' => 'array',
            'attempt_count' => 'integer',
            'requires_reconciliation' => 'boolean',
            'last_reconciled_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function validationCase(): BelongsTo
    {
        return $this->belongsTo(HrValidationCase::class, 'hr_validation_case_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(HrHandoverApproval::class, 'approval_id');
    }

    public function externalEmployeeReference(): HasOne
    {
        return $this->hasOne(ExternalEmployeeReference::class, 'hr_handover_id');
    }
}
