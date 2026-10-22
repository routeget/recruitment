<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class CareerPortalConfigurationVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'configuration_id',
        'tenant_id',
        'version_number',
        'status',
        'schema_version',
        'snapshot',
        'validation_summary',
        'change_summary',
        'created_by_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'schema_version' => 'integer',
            'snapshot' => 'array',
            'validation_summary' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CareerPortalConfigurationVersion $version) {
            if (empty($version->uuid)) {
                $version->uuid = (string) Str::uuid();
            }
            if (empty($version->created_at)) {
                $version->created_at = now();
            }
        });

        static::updating(function () {
            throw new LogicException('CAREER_PORTAL_VERSION_IMMUTABLE: Published career portal configuration versions are immutable and cannot be modified.');
        });

        static::deleting(function () {
            throw new LogicException('CAREER_PORTAL_VERSION_IMMUTABLE: Published career portal configuration versions are immutable and cannot be deleted.');
        });
    }

    public function configuration(): BelongsTo
    {
        return $this->belongsTo(CareerPortalConfiguration::class, 'configuration_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
