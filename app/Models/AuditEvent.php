<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class AuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'correlation_id',
        'tenant_id',
        'actor_user_id',
        'actor_type',
        'actor_identifier',
        'module',
        'action',
        'entity_type',
        'entity_id',
        'outcome',
        'error_code',
        'ip_address',
        'user_agent',
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
        static::creating(function (AuditEvent $event) {
            if (empty($event->uuid)) {
                $event->uuid = (string) Str::uuid();
            }
            if (empty($event->created_at)) {
                $event->created_at = now();
            }
        });

        static::updating(function () {
            throw new LogicException('AuditEvent records are append-only and immutable.');
        });

        static::deleting(function () {
            throw new LogicException('AuditEvent records are append-only and cannot be deleted.');
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
