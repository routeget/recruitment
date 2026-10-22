<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CareerPortalAsset extends Model
{
    protected $fillable = [
        'uuid',
        'tenant_id',
        'asset_key',
        'title',
        'alt_text',
        'public_path',
        'mime_type',
        'width',
        'height',
        'size_bytes',
        'focal_point_x',
        'focal_point_y',
        'approval_status',
        'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'size_bytes' => 'integer',
            'focal_point_x' => 'integer',
            'focal_point_y' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CareerPortalAsset $asset) {
            if (empty($asset->uuid)) {
                $asset->uuid = (string) Str::uuid();
            }
        });
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
