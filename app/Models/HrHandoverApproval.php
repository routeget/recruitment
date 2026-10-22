<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrHandoverApproval extends Model
{
    use BelongsToTenant, HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'hr_validation_case_id',
        'approver_user_id',
        'decision',
        'comment',
        'approved_payload_snapshot',
        'approved_payload_hash',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'approved_payload_snapshot' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function validationCase(): BelongsTo
    {
        return $this->belongsTo(HrValidationCase::class, 'hr_validation_case_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
