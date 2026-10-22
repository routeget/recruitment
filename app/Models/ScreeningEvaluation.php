<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScreeningEvaluation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'application_id',
        'evaluator_user_id',
        'evaluation_source',
        'criteria_breakdown',
        'overall_score',
        'recommendation',
        'missing_evidence_flags',
        'human_reviewed',
        'reviewed_by_user_id',
        'reviewed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'criteria_breakdown' => 'array',
            'overall_score' => 'decimal:2',
            'missing_evidence_flags' => 'array',
            'human_reviewed' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_user_id');
    }
}
