<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Services\Media\MediaStorageService;
use App\Traits\Auditable;

class Magazine extends Model
{
    use HasFactory, Auditable;

    public const TYPE_MAGAZINE = 'magazine';
    public const TYPE_JOURNAL = 'journal';

    protected $appends = [
        'cover_image_url',
        'main_image_url',
        'banner_image_url',
    ];

    protected $hidden = [
        'cover_image',
        'banner_image',
    ];

    protected $fillable = [
        'title',
        'slug',
        'cover_image',
        'banner_image',
        'description',
        'about_text',
        'seo_title',
        'seo_description',
        'seo_keywords',
        'is_active',
        'publication_type',
    ];

    public function isMagazine(): bool
    {
        return $this->publication_type === self::TYPE_MAGAZINE;
    }

    public function isJournal(): bool
    {
        return $this->publication_type === self::TYPE_JOURNAL;
    }

    public function publicationTypeLabel(): string
    {
        return $this->isJournal() ? 'Journal' : 'Magazine';
    }

    public function publicRoutePrefix(): string
    {
        return $this->isJournal() ? 'journals' : 'magazines';
    }

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the pages associated with this magazine.
     */
    public function pages(): HasMany
    {
        return $this->hasMany(MagazinePage::class)->orderBy('sort_order', 'asc');
    }

    /**
     * Get the articles associated with this magazine.
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(MagazineIssue::class);
    }

    /**
     * Get the tags associated with this magazine.
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * Get the editors associated with this magazine.
     */
    public function editors(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'magazine_user', 'magazine_id', 'user_id')
            ->withPivot(['role', 'assigned_by'])
            ->withTimestamps();
    }

    public function getCoverImageUrlAttribute(): ?string
    {
        return $this->mediaUrl($this->cover_image);
    }

    public function getMainImageUrlAttribute(): ?string
    {
        return $this->cover_image_url;
    }

    public function getBannerImageUrlAttribute(): ?string
    {
        return $this->mediaUrl($this->banner_image);
    }

    private function mediaUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'data:')) {
            return $path;
        }

        if (str_starts_with($path, '/images/') || str_starts_with($path, 'images/')) {
            return str_starts_with($path, '/') ? $path : '/' . $path;
        }

        return app(MediaStorageService::class)->publicOrTemporaryUrl($path);
    }
}
