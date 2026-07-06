<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewerAssignment extends Model
{
    protected $fillable = [
        'article_id',
        'sub_editor_assignment_id',
        'reviewer_id',
        'assigned_by',
        'status',
        'due_date',
        'accepted_at',
        'completed_at',
        'scorecard',
        'recommendation',
        'comments_for_author',
        'confidential_comments',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'accepted_at' => 'datetime',
        'completed_at' => 'datetime',
        'scorecard' => 'array',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function subEditorAssignment(): BelongsTo
    {
        return $this->belongsTo(SubEditorAssignment::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
