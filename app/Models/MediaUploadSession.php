<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MediaUploadSession extends Model
{
    use HasUuids;

    public const STATUS_INITIATED = 'initiated';
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_UPLOADED_PENDING_SCAN = 'uploaded_pending_scan';
    public const STATUS_SCANNING = 'scanning';
    public const STATUS_CLEAN = 'clean';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SCAN_FAILED = 'scan_failed';
    public const STATUS_ABORTED = 'aborted';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'user_id',
        'client_upload_id',
        'purpose',
        'attachable_type',
        'attachable_id',
        'original_filename',
        'safe_display_filename',
        'expected_size_bytes',
        'declared_mime_type',
        'expected_checksum_sha256',
        'disk',
        's3_incoming_key',
        's3_clean_key',
        's3_upload_id',
        'upload_mode',
        'status',
        'uploaded_part_manifest',
        'metadata',
        'expires_at',
        'completed_at',
        'scan_requested_at',
        'scanned_at',
        'detected_mime_type',
        'checksum_sha256',
        'scan_engine',
        'scan_status',
        'failure_reason',
    ];

    protected $casts = [
        'uploaded_part_manifest' => 'array',
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'scan_requested_at' => 'datetime',
        'scanned_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_CLEAN,
            self::STATUS_REJECTED,
            self::STATUS_SCAN_FAILED,
            self::STATUS_ABORTED,
            self::STATUS_EXPIRED,
        ], true);
    }
}
