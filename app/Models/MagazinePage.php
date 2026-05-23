<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;

class MagazinePage extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'magazine_id',
        'title',
        'slug',
        'content',
        'sort_order',
    ];

    /**
     * Get the magazine that owns this page.
     */
    public function magazine(): BelongsTo
    {
        return $this->belongsTo(Magazine::class);
    }
}
