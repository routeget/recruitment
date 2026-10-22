<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Requisition extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_SUBMITTED = 'SUBMITTED';

    public const STATUS_IN_APPROVAL = 'IN_APPROVAL';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_OPEN = 'OPEN';

    public const STATUS_ON_HOLD = 'ON_HOLD';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'requisition_number',
        'title',
        'department_id',
        'location_id',
        'legal_entity_id',
        'hiring_manager_user_id',
        'recruiter_user_id',
        'created_by_user_id',
        'headcount',
        'filled_headcount',
        'employment_type',
        'requisition_reason',
        'budget_reference',
        'salary_min',
        'salary_max',
        'currency_code',
        'target_start_date',
        'justification',
        'status',
        'current_version',
        'approved_version',
        'approved_by_user_id',
        'approved_at',
        'hold_reason',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'headcount' => 'integer',
            'filled_headcount' => 'integer',
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'target_start_date' => 'date',
            'current_version' => 'integer',
            'approved_version' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function hiringManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hiring_manager_user_id');
    }

    public function recruiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recruiter_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RequisitionVersion::class)->orderByDesc('version_number');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(RequisitionApproval::class)->orderByDesc('id');
    }
}
