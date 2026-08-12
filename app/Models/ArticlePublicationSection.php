<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticlePublicationSection extends Model
{
    public const KEYS = [
        'introduction',
        'materials_and_methods',
        'discussion',
        'supporting_information',
        'acknowledgements',
        'references',
    ];

    protected $fillable = [
        'article_id',
        'section_key',
        'title',
        'content_html',
        'content_text',
        'sort_order',
        'media_upload_session_id',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
