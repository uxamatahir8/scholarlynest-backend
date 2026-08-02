<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArticleReviewRound extends Model
{
    public const OPEN = 'open';

    public const PENDING = 'pending';

    public const CLOSED = 'closed';

    protected $fillable = [
        'article_id', 'article_version_id', 'round_number', 'status',
        'opened_by', 'opened_at', 'closed_at',
    ];

    protected $casts = ['opened_at' => 'datetime', 'closed_at' => 'datetime'];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class, 'article_version_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ReviewerAssignment::class, 'review_round_id');
    }
}
