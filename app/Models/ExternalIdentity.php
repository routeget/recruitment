<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalIdentity extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_SUSPENDED = 'SUSPENDED';

    public const STATUS_UNLINKED = 'UNLINKED';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'user_id',
        'connection_id',
        'provider',
        'entra_tenant_id',
        'entra_object_id',
        'subject',
        'preferred_username',
        'email_claim',
        'display_name_claim',
        'status',
        'linked_by_user_id',
        'linked_at',
        'last_authenticated_at',
        'last_claims_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
            'last_authenticated_at' => 'datetime',
            'last_claims_snapshot' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ExternalIdentityConnection::class, 'connection_id');
    }

    public function linkedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }
}
