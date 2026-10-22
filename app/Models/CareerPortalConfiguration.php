<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CareerPortalConfiguration extends Model
{
    public const SCOPE_GLOBAL = 'GLOBAL';

    public const SCOPE_TENANT = 'TENANT';

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_VALIDATED_DRAFT = 'VALIDATED_DRAFT';

    public const STATUS_PUBLISHED = 'PUBLISHED';

    public const STATUS_UNPUBLISHED = 'UNPUBLISHED';

    protected $fillable = [
        'uuid',
        'scope',
        'tenant_id',
        'status',
        'schema_version',
        'draft_version',
        'draft_payload',
        'validation_status',
        'validation_errors',
        'last_validated_at',
        'active_published_version_id',
        'published_payload',
        'published_version_number',
        'published_at',
        'published_by_user_id',
        'updated_by_user_id',
        'preview_token_hash',
        'preview_token_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'draft_version' => 'integer',
            'draft_payload' => 'array',
            'validation_errors' => 'array',
            'last_validated_at' => 'datetime',
            'active_published_version_id' => 'integer',
            'published_payload' => 'array',
            'published_version_number' => 'integer',
            'published_at' => 'datetime',
            'preview_token_expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CareerPortalConfiguration $config) {
            if (empty($config->uuid)) {
                $config->uuid = (string) Str::uuid();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function activePublishedVersion(): BelongsTo
    {
        return $this->belongsTo(CareerPortalConfigurationVersion::class, 'active_published_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CareerPortalConfigurationVersion::class, 'configuration_id')->orderByDesc('version_number');
    }

    public function publicationEvents(): HasMany
    {
        return $this->hasMany(CareerPortalPublicationEvent::class, 'configuration_id')->orderByDesc('id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
