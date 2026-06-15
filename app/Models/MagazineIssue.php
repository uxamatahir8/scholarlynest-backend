<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MagazineIssue extends Model
{
    protected $fillable = [
        'magazine_id',
        'volume_number',
        'issue_number',
        'special_title',
        'description',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function magazine(): BelongsTo
    {
        return $this->belongsTo(Magazine::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
