<?php

namespace App\Models;

use App\Services\Media\MediaStorageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MagazineIssue extends Model
{
    protected $appends = [
        'cover_image_url',
    ];

    protected $fillable = [
        'magazine_id',
        'volume_number',
        'issue_number',
        'issue_month',
        'issue_year',
        'special_title',
        'description',
        'cover_image',
        'status',
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

    public function publicationRecords(): HasMany
    {
        return $this->hasMany(PublicationRecord::class);
    }

    public function getCoverImageUrlAttribute(): ?string
    {
        return app(MediaStorageService::class)->publicOrTemporaryUrl($this->cover_image);
    }
}
