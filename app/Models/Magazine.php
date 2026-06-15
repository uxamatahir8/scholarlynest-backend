<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;

class Magazine extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'title',
        'slug',
        'cover_image',
        'description',
        'about_text',
        'seo_title',
        'seo_description',
        'seo_keywords',
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
}
