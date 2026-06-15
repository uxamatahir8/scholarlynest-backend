<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleFile extends Model
{
    protected $fillable = [
        'article_id',
        'article_version_id',
        'uploaded_by',
        'file_type',
        'file_path',
        'original_name',
        'mime_type',
        'size',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class, 'article_version_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
