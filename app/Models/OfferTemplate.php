<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OfferTemplate extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'code',
        'name',
        'body_template',
        'compensation_rules',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'compensation_rules' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
