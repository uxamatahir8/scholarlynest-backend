<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;

class FooterPage extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'footer_category_id',
        'title',
        'slug',
        'content',
        'is_visible',
        'sort_order',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
    ];

    /**
     * Get the category that owns the page.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FooterCategory::class, 'footer_category_id');
    }
}
