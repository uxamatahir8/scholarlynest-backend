<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicationFileSelection extends Model
{
    protected $fillable = ['publication_record_id', 'article_file_id', 'public_role', 'is_primary', 'is_public', 'primary_marker', 'selected_by'];

    protected $casts = ['is_primary' => 'boolean', 'is_public' => 'boolean'];

    public function publicationRecord(): BelongsTo
    {
        return $this->belongsTo(PublicationRecord::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(ArticleFile::class, 'article_file_id');
    }

    public function selector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_by');
    }
}
