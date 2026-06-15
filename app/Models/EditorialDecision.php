<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EditorialDecision extends Model
{
    protected $fillable = [
        'article_id',
        'decision_by',
        'decision',
        'decision_source',
        'decision_date',
        'comments_for_author',
        'internal_notes',
    ];

    protected $casts = [
        'decision_date' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decision_by');
    }
}
