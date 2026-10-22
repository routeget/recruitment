<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResumeParseJob extends Model
{
    use BelongsToTenant;

    public const STATUS_UPLOADED = 'UPLOADED';

    public const STATUS_QUEUED = 'QUEUED';

    public const STATUS_PROCESSING = 'PROCESSING';

    public const STATUS_REVIEW_REQUIRED = 'REVIEW_REQUIRED';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_RETRY_SCHEDULED = 'RETRY_SCHEDULED';

    public const SCHEMA_VERSION = 'RESUME_SCHEMA_v1.0';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'candidate_id',
        'candidate_document_id',
        'application_id',
        'ai_task_run_id',
        'idempotency_key',
        'status',
        'schema_version',
        'provider_key',
        'model_name',
        'execution_mode',
        'ocr_used',
        'is_scanned_document',
        'prompt_injection_detected',
        'security_flags',
        'extracted_text_length',
        'parsed_data',
        'corrected_data',
        'validation_errors',
        'missing_fields',
        'error_code',
        'error_message',
        'attempt_count',
        'max_attempts',
        'next_retry_at',
        'candidate_reviewed',
        'reviewed_by_user_id',
        'reviewed_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'ocr_used' => 'boolean',
            'is_scanned_document' => 'boolean',
            'prompt_injection_detected' => 'boolean',
            'security_flags' => 'array',
            'extracted_text_length' => 'integer',
            'parsed_data' => 'array',
            'corrected_data' => 'array',
            'validation_errors' => 'array',
            'missing_fields' => 'array',
            'attempt_count' => 'integer',
            'max_attempts' => 'integer',
            'next_retry_at' => 'datetime',
            'candidate_reviewed' => 'boolean',
            'reviewed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CandidateDocument::class, 'candidate_document_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function aiTaskRun(): BelongsTo
    {
        return $this->belongsTo(AiTaskRun::class, 'ai_task_run_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * Returns the authoritative resume data (human-corrected data if reviewed, otherwise parsed data).
     *
     * @return array<string, mixed>
     */
    public function getEffectiveResumeData(): array
    {
        if (! empty($this->corrected_data) && is_array($this->corrected_data)) {
            return $this->corrected_data;
        }

        return is_array($this->parsed_data) ? $this->parsed_data : [];
    }
}
