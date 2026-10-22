<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    use BelongsToTenant;

    public const STAGE_RECEIVED = 'RECEIVED';

    public const STAGE_ELIGIBILITY_REVIEW = 'ELIGIBILITY_REVIEW';

    public const STAGE_SCREENING = 'SCREENING';

    public const STAGE_SHORTLISTED = 'SHORTLISTED';

    public const STAGE_ASSESSMENT = 'ASSESSMENT';

    public const STAGE_INTERVIEW = 'INTERVIEW';

    public const STAGE_DECISION_PENDING = 'DECISION_PENDING';

    public const STAGE_OFFER_IN_PROGRESS = 'OFFER_IN_PROGRESS';

    public const STAGE_HIRED_PENDING_HR_HANDOVER = 'HIRED_PENDING_HR_HANDOVER';

    public const STAGE_REJECTED = 'REJECTED';

    public const STAGE_WITHDRAWN = 'WITHDRAWN';

    public const STAGE_ON_HOLD = 'ON_HOLD';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'application_number',
        'job_posting_id',
        'requisition_id',
        'candidate_id',
        'assigned_recruiter_user_id',
        'current_stage',
        'candidate_visible_status',
        'screening_score',
        'eligibility_verified',
        'screening_notes',
        'cover_note',
        'answers',
        'submitted_at',
        'withdrawn_at',
        'rejected_at',
        'rejection_reason',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'screening_score' => 'decimal:2',
            'eligibility_verified' => 'boolean',
            'answers' => 'array',
            'submitted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'rejected_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function assignedRecruiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_recruiter_user_id');
    }

    public function stageEvents(): HasMany
    {
        return $this->hasMany(ApplicationStageEvent::class)->orderByDesc('id');
    }
}
