<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleReviewerPreference extends Model
{
    public const SUGGESTED = 'suggested';
    public const OPPOSED = 'opposed';

    protected $fillable = [
        'article_id',
        'created_by_author_id',
        'type',
        'name',
        'email',
        'affiliation',
        'designation',
        'reason',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_author_id');
    }
}
