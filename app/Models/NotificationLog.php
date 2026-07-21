<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'notification_event_id',
        'user_notification_id',
        'user_id',
        'recipient_email',
        'subject',
        'channel',
        'purpose',
        'deduplication_key',
        'privacy_variant',
        'payload',
        'status',
        'retry_count',
        'error_message',
        'queued_at',
        'sending_at',
        'sent_at',
        'failed_at',
        'provider',
        'provider_message_id',
        'last_error_code',
        'last_error_summary',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'payload' => 'array',
        'retry_count' => 'integer',
        'queued_at' => 'datetime',
        'sending_at' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /**
     * Get the user associated with the notification log.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(NotificationEvent::class, 'notification_event_id');
    }

    public function userNotification(): BelongsTo
    {
        return $this->belongsTo(UserNotification::class);
    }
}
