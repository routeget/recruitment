<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateDocument extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'candidate_id',
        'application_id',
        'document_type',
        'original_filename',
        'storage_disk',
        'storage_path',
        'mime_type',
        'file_size_bytes',
        'sha256_checksum',
        'malware_scan_status',
        'uploaded_by_user_id',
        'retention_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'retention_expires_at' => 'datetime',
        ];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
