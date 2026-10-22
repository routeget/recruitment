<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrValidationCase extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'application_id',
        'offer_version_id',
        'status',
        'checklist_version',
        'identity_verified_at',
        'right_to_work_verified_at',
        'background_check_status',
        'compensation_validated_at',
        'position_mapping_validated_at',
        'legal_entity_validated_at',
        'start_date_confirmed_at',
        'position_code',
        'assigned_to_hr_validator_id',
        'completed_at',
        'invalidated_at',
        'invalidation_reason',
    ];

    protected function casts(): array
    {
        return [
            'checklist_version' => 'integer',
            'identity_verified_at' => 'datetime',
            'right_to_work_verified_at' => 'datetime',
            'compensation_validated_at' => 'datetime',
            'position_mapping_validated_at' => 'datetime',
            'legal_entity_validated_at' => 'datetime',
            'start_date_confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    public function isChecklistComplete(): bool
    {
        return $this->identity_verified_at !== null
            && $this->right_to_work_verified_at !== null
            && in_array($this->background_check_status, ['passed', 'waived'], true)
            && $this->compensation_validated_at !== null
            && $this->position_mapping_validated_at !== null
            && $this->legal_entity_validated_at !== null
            && $this->start_date_confirmed_at !== null
            && $this->invalidated_at === null;
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function offerVersion(): BelongsTo
    {
        return $this->belongsTo(OfferVersion::class);
    }

    public function assignedValidator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_hr_validator_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(HrHandoverApproval::class)->orderByDesc('decided_at');
    }

    public function handovers(): HasMany
    {
        return $this->hasMany(HrHandover::class)->orderByDesc('created_at');
    }
}
