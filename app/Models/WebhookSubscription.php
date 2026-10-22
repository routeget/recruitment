<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookSubscription extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'name',
        'target_url',
        'signing_secret',
        'subscribed_events',
        'is_active',
    ];

    protected $hidden = [
        'signing_secret',
    ];

    protected function casts(): array
    {
        return [
            'subscribed_events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class)->latest();
    }
}
