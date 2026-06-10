<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleAsset extends Model
{
    protected $table = 'article_assets';

    protected $fillable = [
        'article_id',
        'file_path',
        'original_filename',
        'file_size',
        'mime_type',
    ];

    /**
     * Get the article this asset is attached to.
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
