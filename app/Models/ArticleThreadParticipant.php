<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleThreadParticipant extends Model
{
    protected $fillable = ['thread_id', 'user_id', 'participant_role', 'access_level', 'added_by', 'added_at', 'removed_by', 'removed_at', 'muted_at', 'notification_preference', 'metadata'];

    protected $casts = ['added_at' => 'datetime', 'removed_at' => 'datetime', 'muted_at' => 'datetime', 'metadata' => 'array'];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ArticleThread::class, 'thread_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
