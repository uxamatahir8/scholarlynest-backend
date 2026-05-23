<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleShareClick extends Model
{
    protected $fillable = [
        'article_id',
        'platform',
        'clicks',
    ];

    /**
     * Get the article that this share click belongs to.
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
