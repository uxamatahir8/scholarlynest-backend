<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowIdempotencyKey extends Model
{
    protected $fillable = ['article_id', 'actor_id', 'command', 'idempotency_key', 'request_hash', 'response_status', 'response_payload'];

    protected $casts = ['response_payload' => 'array'];
}
