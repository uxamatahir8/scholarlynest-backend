<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;

class Article extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'magazine_id',
        'magazine_issue_id',
        'user_id',
        'title',
        'subtitle',
        'slug',
        'abstract',
        'keywords',
        'full_text',
        'pdf_path',
        'featured_image',
        'doi',
        'status',
        'rejection_reason',
        'plagiarism_status',
        'plagiarism_score',
        'plagiarism_report_path',
        'screened_at',
        'screened_by',
        'clicks',
        'impressions',
        'published_at',
        'published_year',
        'published_month',
        'page_start',
        'page_end',
        'seo_title',
        'seo_description',
        'seo_keywords',
    ];

    protected $casts = [
        'keywords' => 'array',
        'plagiarism_score' => 'decimal:2',
        'screened_at' => 'datetime',
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

    public function issue(): BelongsTo
    {
        return $this->belongsTo(MagazineIssue::class, 'magazine_issue_id');
    }

    public function screener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'screened_by');
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

    public function files(): HasMany
    {
        return $this->hasMany(ArticleFile::class);
    }

    public function subEditorAssignments(): HasMany
    {
        return $this->hasMany(SubEditorAssignment::class);
    }

    public function reviewerAssignments(): HasMany
    {
        return $this->hasMany(ReviewerAssignment::class);
    }

    public function editorialDecisions(): HasMany
    {
        return $this->hasMany(EditorialDecision::class);
    }

    public function productionAssignments(): HasMany
    {
        return $this->hasMany(ProductionAssignment::class);
    }

    public function postPublicationActions(): HasMany
    {
        return $this->hasMany(PostPublicationAction::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ArticleVersion::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(ArticleAuditLog::class);
    }
}
