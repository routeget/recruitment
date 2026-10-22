<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterviewScorecard extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'interview_id',
        'application_id',
        'interviewer_user_id',
        'competency_ratings',
        'overall_rating',
        'recommendation',
        'strengths_evidence',
        'concerns_evidence',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'competency_ratings' => 'array',
            'overall_rating' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewer_user_id');
    }
}
