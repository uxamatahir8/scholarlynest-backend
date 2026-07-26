<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArticleVersion extends Model
{
    protected $fillable = [
        'article_id',
        'parent_version_id',
        'manuscript_file_id',
        'created_by',
        'version_number',
        'revision_number',
        'revision_tracking_code',
        'label',
        'source',
        'status_snapshot',
        'screening_status',
        'screened_at',
        'screened_by',
        'submitted_at',
        'locked_at',
        'accepted_marker',
        'accepted_at',
        'accepted_by',
        'metadata_snapshot',
        'file_snapshot',
        'change_summary',
        'author_response',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'screened_at' => 'datetime',
        'submitted_at' => 'datetime',
        'locked_at' => 'datetime',
        'metadata_snapshot' => 'array',
        'file_snapshot' => 'array',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_version_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    public function manuscriptFile(): BelongsTo
    {
        return $this->belongsTo(ArticleFile::class, 'manuscript_file_id');
    }

    public function accepter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function threads(): HasMany
    {
        return $this->hasMany(ArticleThread::class);
    }
}
