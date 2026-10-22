<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferVersion extends Model
{
    use BelongsToTenant, HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'offer_id',
        'version_number',
        'job_title',
        'legal_entity_id',
        'department_id',
        'location_id',
        'employment_type',
        'base_salary_minor',
        'currency_code',
        'bonus_terms',
        'equity_terms',
        'start_date',
        'probation_months',
        'document_storage_key',
        'snapshot_hash',
        'approved_by_user_id',
        'approved_at',
        'created_by_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'base_salary_minor' => 'integer',
            'start_date' => 'date',
            'probation_months' => 'integer',
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
