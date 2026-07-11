<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Services\Media\MediaStorageService;
use App\Traits\Auditable;

class Article extends Model
{
    use HasFactory, Auditable;

    protected $appends = [
        'featured_image_url',
        'pdf_url',
    ];

    protected $fillable = [
        'magazine_id',
        'tracking_code',
        'magazine_issue_id',
        'user_id',
        'title',
        'subtitle',
        'slug',
        'abstract',
        'keywords',
        'article_category',
        'article_type',
        'subject_area',
        'language',
        'ethical_approval_statement',
        'conflict_of_interest_statement',
        'funding_statement',
        'data_availability_statement',
        'author_contribution_statement',
        'full_text',
        'pdf_path',
        'featured_image',
        'doi',
        'open_access_label',
        'is_peer_reviewed',
        'academic_editor',
        'received_at',
        'accepted_at',
        'license_statement',
        'competing_interests_statement',
        'abbreviations',
        'citation_text',
        'status',
        'terms_accepted_at',
        'terms_accepted_by',
        'terms_acceptance_ip',
        'rejection_reason',
        'plagiarism_status',
        'plagiarism_score',
        'plagiarism_report_path',
        'screened_at',
        'screened_by',
        'clicks',
        'impressions',
        'published_at',
        'author_final_approved_at',
        'author_final_approved_by',
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
        'terms_accepted_at' => 'datetime',
        'author_final_approved_at' => 'datetime',
        'received_at' => 'date',
        'accepted_at' => 'date',
        'is_peer_reviewed' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::created(function (Article $article) {
            if (!$article->tracking_code) {
                $year = optional($article->created_at)->format('Y') ?: now()->format('Y');
                $article->forceFill([
                    'tracking_code' => sprintf('SN-%s-%06d', $year, $article->id),
                ])->saveQuietly();
            }
        });
    }

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

    public function finalApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_final_approved_by');
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

    public function images(): HasMany
    {
        return $this->hasMany(ArticleAsset::class)->where('asset_type', 'image')->orderBy('sort_order')->orderBy('id');
    }

    public function supplementaryAssets(): HasMany
    {
        return $this->hasMany(ArticleAsset::class)->where(function ($query) {
            $query->whereNull('asset_type')->orWhere('asset_type', 'supplementary');
        });
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

    public function reviewerPreferences(): HasMany
    {
        return $this->hasMany(ArticleReviewerPreference::class);
    }

    public function publicationSections(): HasMany
    {
        return $this->hasMany(ArticlePublicationSection::class);
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

    public function latestVersion(): HasOne
    {
        return $this->hasOne(ArticleVersion::class)->ofMany('version_number', 'max');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(ArticleAuditLog::class);
    }

    public function transferRequests(): HasMany
    {
        return $this->hasMany(ArticleTransferRequest::class);
    }

    public function pendingTransferRequest()
    {
        return $this->hasOne(ArticleTransferRequest::class)
            ->where('status', ArticleTransferRequest::STATUS_PENDING)
            ->latestOfMany();
    }

    public function getFeaturedImageUrlAttribute(): ?string
    {
        return app(MediaStorageService::class)->publicOrTemporaryUrl($this->featured_image);
    }

    public function getPdfUrlAttribute(): ?string
    {
        return app(MediaStorageService::class)->temporaryUrl($this->pdf_path);
    }
}
