<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleThreadMessageAttachment extends Model
{
    protected $fillable = ['message_id', 'article_file_id', 'safe_filename', 'mime_type', 'size', 'checksum', 'scan_status', 'uploaded_by', 'visibility_classification'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ArticleThreadMessage::class, 'message_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(ArticleFile::class, 'article_file_id');
    }
}
