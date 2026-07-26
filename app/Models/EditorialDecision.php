<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EditorialDecision extends Model
{
    protected $fillable = [
        'article_id',
        'article_version_id',
        'round_number',
        'decision_by',
        'decision',
        'decision_source',
        'decision_date',
        'comments_for_author',
        'internal_notes',
        'revision_due_at',
        'idempotency_key',
        'corrects_decision_id',
    ];

    protected $casts = [
        'decision_date' => 'datetime',
        'revision_due_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decision_by');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class, 'article_version_id');
    }

    public function correctedDecision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_decision_id');
    }
}
