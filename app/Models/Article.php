<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Traits\Auditable;

class Article extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'magazine_id',
        'user_id',
        'title',
        'slug',
        'abstract',
        'full_text',
        'pdf_path',
        'featured_image',
        'status',
        'rejection_reason',
        'clicks',
        'impressions',
        'published_at',
        'seo_title',
        'seo_description',
        'seo_keywords',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    /**
     * Get the magazine that this article belongs to.
     */
    public function magazine(): BelongsTo
    {
        return $this->belongsTo(Magazine::class);
    }

    /**
     * Get the user author that created this article.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the tags associated with this article.
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'article_tag');
    }

    /**
     * Get the share clicks associated with this article.
     */
    public function shareClicks()
    {
        return $this->hasMany(ArticleShareClick::class);
    }

    /**
     * Get the co-authors/collaborators associated with this article.
     */
    public function articleAuthors()
    {
        return $this->hasMany(ArticleAuthor::class);
    }

    /**
     * Get the supplementary assets associated with this article.
     */
    public function assets()
    {
        return $this->hasMany(ArticleAsset::class);
    }
}
