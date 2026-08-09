<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProofRound extends Model
{
    protected $fillable = [
        'article_id', 'article_version_id', 'accepted_file_set_id', 'production_assignment_id',
        'round_number', 'status', 'source_file_id', 'author_file_id', 'corrected_file_id',
        'author_comments', 'production_notes', 'requested_at', 'responded_at',
        'approved_at', 'approved_by', 'idempotency_key', 'active_marker',
    ];

    protected $casts = [
        'requested_at' => 'datetime', 'responded_at' => 'datetime',
        'approved_at' => 'datetime',
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

    public function productionAssignment(): BelongsTo
    {
        return $this->belongsTo(ProductionAssignment::class);
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(ArticleFile::class, 'source_file_id');
    }

    public function authorFile(): BelongsTo
    {
        return $this->belongsTo(ArticleFile::class, 'author_file_id');
    }

    public function correctedFile(): BelongsTo
    {
        return $this->belongsTo(ArticleFile::class, 'corrected_file_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
