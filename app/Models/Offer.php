<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Offer extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'application_id',
        'current_version_id',
        'status',
        'expires_at',
        'accepted_version_id',
        'accepted_at',
        'declined_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(OfferVersion::class, 'current_version_id');
    }

    public function acceptedVersion(): BelongsTo
    {
        return $this->belongsTo(OfferVersion::class, 'accepted_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(OfferVersion::class)->orderBy('version_number');
    }

    public function hrValidationCase(): HasOne
    {
        return $this->hasOne(HrValidationCase::class, 'application_id', 'application_id');
    }
}
