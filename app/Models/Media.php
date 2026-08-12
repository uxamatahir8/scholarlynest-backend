<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Media extends Model
{
    use SoftDeletes, Auditable;

    protected $fillable = [
        'filename',
        'safe_original_name',
        'url',
        'storage_key',
        'disk',
        'mime_type',
        'size',
        'checksum_sha256',
        'scan_status',
        'scan_engine',
        'scanned_at',
        'model_type',
        'model_id'
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
    ];

    /**
     * Get the parent model that owns the media.
     */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }
}
