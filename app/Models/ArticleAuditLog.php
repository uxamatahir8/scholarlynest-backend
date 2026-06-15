<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleAuditLog extends Model
{
    protected $fillable = [
        'article_id',
        'actor_id',
        'event',
        'from_status',
        'to_status',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
