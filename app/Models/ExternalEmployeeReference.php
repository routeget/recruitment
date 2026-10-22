<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalEmployeeReference extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'candidate_id',
        'application_id',
        'hr_handover_id',
        'external_system',
        'external_employee_id',
        'external_worker_number',
        'legal_entity_code',
        'synced_at',
        'sync_status',
        'raw_reference_payload',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
            'raw_reference_payload' => 'array',
        ];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function handover(): BelongsTo
    {
        return $this->belongsTo(HrHandover::class, 'hr_handover_id');
    }
}
