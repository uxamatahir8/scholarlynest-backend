<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleThreadMention extends Model
{
    public $timestamps = false;

    protected $fillable = ['message_id', 'mentioned_user_id', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ArticleThreadMessage::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentioned_user_id');
    }
}
