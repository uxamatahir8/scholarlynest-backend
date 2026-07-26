<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleThreadMessageRevision extends Model
{
    public $timestamps = false;

    protected $fillable = ['message_id', 'edited_by', 'previous_body', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ArticleThreadMessage::class, 'message_id');
    }
}
