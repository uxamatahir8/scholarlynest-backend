<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ArticleThreadMessage extends Model
{
    use SoftDeletes;

    protected $fillable = ['thread_id', 'sender_id', 'parent_message_id', 'message_type', 'body', 'body_format', 'audience_variant', 'is_system', 'event_key', 'client_request_id', 'edited_at'];

    protected $casts = ['is_system' => 'boolean', 'edited_at' => 'datetime'];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ArticleThread::class, 'thread_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_message_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ArticleThreadMessageAttachment::class, 'message_id');
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(ArticleThreadMention::class, 'message_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ArticleThreadMessageRevision::class, 'message_id');
    }
}
