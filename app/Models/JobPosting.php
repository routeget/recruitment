<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobPosting extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_PUBLISHED = 'PUBLISHED';

    public const STATUS_UNPUBLISHED = 'UNPUBLISHED';

    public const STATUS_EXPIRED = 'EXPIRED';

    public const STATUS_CLOSED = 'CLOSED';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'requisition_id',
        'slug',
        'code',
        'title',
        'department_id',
        'location_id',
        'employment_type',
        'work_mode',
        'experience_level',
        'category_slug',
        'summary',
        'responsibilities',
        'qualifications',
        'skills_required',
        'compensation_min',
        'compensation_max',
        'currency_code',
        'show_compensation_publicly',
        'is_featured',
        'status',
        'current_version',
        'approved_version',
        'approved_by_user_id',
        'approved_at',
        'published_at',
        'expires_at',
        'closed_at',
        'external_channels_config',
        'seo_title',
        'seo_description',
    ];

    protected function casts(): array
    {
        return [
            'skills_required' => 'array',
            'compensation_min' => 'decimal:2',
            'compensation_max' => 'decimal:2',
            'show_compensation_publicly' => 'boolean',
            'is_featured' => 'boolean',
            'current_version' => 'integer',
            'approved_version' => 'integer',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
            'external_channels_config' => 'array',
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(JobPostingVersion::class)->orderByDesc('version_number');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function isPubliclyActive(): bool
    {
        if ($this->status !== self::STATUS_PUBLISHED) {
            return false;
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function toJobPostingSchemaJsonLd(): array
    {
        $schema = [
            '@context' => 'https://schema.org/',
            '@type' => 'JobPosting',
            'title' => $this->title,
            'description' => $this->summary."\n\nResponsibilities:\n".$this->responsibilities."\n\nQualifications:\n".$this->qualifications,
            'identifier' => [
                '@type' => 'PropertyValue',
                'name' => $this->tenant?->name,
                'value' => $this->code,
            ],
            'datePosted' => ($this->published_at ?? $this->created_at)?->toIso8601String(),
            'employmentType' => $this->employment_type,
            'hiringOrganization' => [
                '@type' => 'Organization',
                'name' => $this->tenant?->name,
            ],
            'jobLocation' => [
                '@type' => 'Place',
                'address' => [
                    '@type' => 'PostalAddress',
                    'addressLocality' => $this->location?->city,
                    'addressCountry' => $this->location?->country_code,
                ],
            ],
        ];

        if ($this->expires_at) {
            $schema['validThrough'] = $this->expires_at->toIso8601String();
        }

        if ($this->show_compensation_publicly && $this->compensation_min && $this->compensation_max) {
            $schema['baseSalary'] = [
                '@type' => 'MonetaryAmount',
                'currency' => $this->currency_code,
                'value' => [
                    '@type' => 'QuantitativeValue',
                    'minValue' => (float) $this->compensation_min,
                    'maxValue' => (float) $this->compensation_max,
                    'unitText' => 'YEAR',
                ],
            ];
        }

        return $schema;
    }
}
