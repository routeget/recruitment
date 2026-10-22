<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class CareerPortalPublicationEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'configuration_id',
        'version_id',
        'restored_from_version_id',
        'tenant_id',
        'action',
        'actor_user_id',
        'actor_identifier',
        'correlation_id',
        'change_summary',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CareerPortalPublicationEvent $event) {
            if (empty($event->uuid)) {
                $event->uuid = (string) Str::uuid();
            }
            if (empty($event->created_at)) {
                $event->created_at = now();
            }
        });

        static::updating(function () {
            throw new LogicException('CAREER_PORTAL_PUBLICATION_EVENT_IMMUTABLE: Publication events are append-only.');
        });

        static::deleting(function () {
            throw new LogicException('CAREER_PORTAL_PUBLICATION_EVENT_IMMUTABLE: Publication events are append-only.');
        });
    }

    public function configuration(): BelongsTo
    {
        return $this->belongsTo(CareerPortalConfiguration::class, 'configuration_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(CareerPortalConfigurationVersion::class, 'version_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
