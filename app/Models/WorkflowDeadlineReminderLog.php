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
        'recipient_user_id',
        'reminder_type',
        'due_date',
        'due_date_version',
        'notification_event_id',
        'escalated_to_user_id',
        'delivery_status',
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

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function notificationEvent(): BelongsTo
    {
        return $this->belongsTo(NotificationEvent::class);
    }
}
