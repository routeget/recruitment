<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Candidate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'user_id',
        'candidate_code',
        'first_name',
        'last_name',
        'email',
        'phone',
        'location_city',
        'country_code',
        'current_title',
        'years_experience',
        'skills',
        'parsed_resume_data',
        'source_channel',
        'talent_pool_tag',
        'consent_given',
        'consent_version',
        'consent_given_at',
        'privacy_notice_acknowledged',
        'dsar_status',
        'retention_expires_at',
        'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'years_experience' => 'integer',
            'skills' => 'array',
            'parsed_resume_data' => 'array',
            'consent_given' => 'boolean',
            'consent_given_at' => 'datetime',
            'privacy_notice_acknowledged' => 'boolean',
            'retention_expires_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CandidateDocument::class)->orderByDesc('id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class)->orderByDesc('id');
    }
}
