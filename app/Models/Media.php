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
        'url',
        'disk',
        'mime_type',
        'size',
        'model_type',
        'model_id'
    ];

    /**
     * Get the parent model that owns the media.
     */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }
}
