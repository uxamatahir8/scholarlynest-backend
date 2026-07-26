<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PublicationRecord extends Model
{
    protected $fillable = [
        'article_id', 'article_version_id', 'accepted_file_set_id', 'proof_round_id',
        'magazine_issue_id', 'status', 'doi', 'page_start', 'page_end', 'scheduled_for',
        'published_at', 'unpublished_at', 'unpublish_reason', 'created_by', 'published_by',
        'idempotency_key',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime', 'published_at' => 'datetime', 'unpublished_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class, 'article_version_id');
    }

    public function acceptedFileSet(): BelongsTo
    {
        return $this->belongsTo(ArticleAcceptedFileSet::class);
    }

    public function proofRound(): BelongsTo
    {
        return $this->belongsTo(ProofRound::class);
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(MagazineIssue::class, 'magazine_issue_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function files(): HasMany
    {
        return $this->hasMany(PublicationFileSelection::class);
    }
}
