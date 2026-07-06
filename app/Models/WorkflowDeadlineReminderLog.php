<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowDeadlineReminderLog extends Model
{
    protected $fillable = [
        'article_id',
        'assignment_type',
        'assignment_id',
        'reminder_type',
        'due_date',
        'sent_at',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
