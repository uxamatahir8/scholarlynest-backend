<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubEditorAssignment extends Model
{
    protected $fillable = [
        'article_id',
        'sub_editor_id',
        'assigned_by',
        'status',
        'due_date',
        'completed_at',
        'recommendation',
        'comments',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function subEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sub_editor_id');
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
