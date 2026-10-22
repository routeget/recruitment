<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HiringDecision extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'application_id',
        'requisition_id',
        'decision',
        'decided_by_user_id',
        'rationale',
        'evidence_summary',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence_summary' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function decisionMaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
