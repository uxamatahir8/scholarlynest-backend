<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PublicationRecord extends Model
{
    protected $fillable = [
        'article_id', 'magazine_id', 'article_version_id', 'accepted_file_set_id', 'proof_round_id',
        'publication_mode', 'primary_publication_file_id', 'magazine_issue_id', 'status', 'active_marker',
        'doi', 'page_start', 'page_end', 'online_publication_date', 'print_publication_date', 'scheduled_for',
        'published_at', 'unpublished_at', 'unpublished_by', 'unpublish_reason', 'created_by', 'updated_by', 'published_by',
        'publication_failed_at', 'publication_failure_code', 'publication_failure_message', 'idempotency_key',
    ];

    protected $casts = [
        'online_publication_date' => 'date', 'print_publication_date' => 'date',
        'scheduled_for' => 'datetime', 'published_at' => 'datetime', 'unpublished_at' => 'datetime', 'publication_failed_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function magazine(): BelongsTo
    {
        return $this->belongsTo(Magazine::class);
    }

    public function primaryFile(): BelongsTo
    {
        return $this->belongsTo(ArticleFile::class, 'primary_publication_file_id');
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
