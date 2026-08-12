<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleThreadReadState extends Model
{
    protected $fillable = ['thread_id', 'user_id', 'last_read_message_id', 'last_read_at'];

    protected $casts = ['last_read_at' => 'datetime'];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ArticleThread::class, 'thread_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
