<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformSetting extends Model
{
    protected $fillable = [
        'key',
        'section',
        'value_json',
        'value_type',
        'classification',
        'sensitivity',
        'version',
        'updated_by_user_id',
        'last_change_reason',
    ];

    protected function casts(): array
    {
        return [
            'value_json' => 'array',
            'version' => 'integer',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function extractTypedValue(): mixed
    {
        $payload = $this->value_json;
        if (! is_array($payload) || ! array_key_exists('value', $payload)) {
            return null;
        }

        return match ($this->value_type) {
            'boolean' => (bool) $payload['value'],
            'integer' => (int) $payload['value'],
            'float' => (float) $payload['value'],
            default => (string) $payload['value'],
        };
    }
}
