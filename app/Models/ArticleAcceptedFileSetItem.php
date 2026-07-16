<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleAcceptedFileSetItem extends Model
{
    public const ROLE_MANUSCRIPT = 'manuscript';
    public const ROLE_ADDITIONAL = 'additional';
    public const ROLE_SUPPLEMENTARY = 'supplementary';

    protected $fillable = [
        'accepted_file_set_id',
        'article_file_id',
        'source_version_id',
        'accepted_role',
    ];

    public function acceptedFileSet(): BelongsTo
    {
        return $this->belongsTo(ArticleAcceptedFileSet::class, 'accepted_file_set_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(ArticleFile::class, 'article_file_id');
    }

    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class, 'source_version_id');
    }
}
