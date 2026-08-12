<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubEditorAssignment extends Model
{
    protected $fillable = [
        'article_id',
        'article_version_id',
        'round_number',
        'sub_editor_id',
        'assigned_by',
        'status',
        'due_date',
        'completed_at',
        'recommendation',
        'comments',
        'author_comments',
        'internal_comments',
        'accepted_at',
        'declined_at',
        'revoked_at',
        'superseded_by_id',
        'idempotency_key',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'completed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function subEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sub_editor_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class, 'article_version_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function reviewerAssignments(): HasMany
    {
        return $this->hasMany(ReviewerAssignment::class);
    }
}
