<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ArticleThread extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'article_id', 'article_version_id', 'context_type', 'context_id', 'thread_type',
        'privacy_classification', 'title', 'status', 'default_key', 'created_by',
        'locked_by', 'locked_at', 'archived_by', 'archived_at', 'closed_at',
        'last_message_at', 'message_count', 'metadata',
    ];

    protected $casts = [
        'locked_at' => 'datetime', 'archived_at' => 'datetime', 'closed_at' => 'datetime',
        'last_message_at' => 'datetime', 'metadata' => 'array', 'message_count' => 'integer',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class, 'article_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ArticleThreadParticipant::class, 'thread_id');
    }

    public function activeParticipants(): HasMany
    {
        return $this->participants()->whereNull('removed_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ArticleThreadMessage::class, 'thread_id');
    }

    public function readStates(): HasMany
    {
        return $this->hasMany(ArticleThreadReadState::class, 'thread_id');
    }
}
