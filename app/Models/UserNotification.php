<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotification extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'notification_event_id', 'recipient_user_id', 'type', 'category', 'priority',
        'severity', 'privacy_variant', 'template_version', 'title_key', 'body_key', 'rendered_title',
        'rendered_body', 'render_data', 'article_id',
        'magazine_id', 'subject_type', 'subject_id', 'deep_link_key', 'deep_link_params',
        'group_key', 'deduplication_key', 'read_at', 'dismissed_at', 'archived_at',
        'in_app_visible', 'email_mode', 'digest_frequency', 'email_queued_at', 'digest_sent_at',
        'action_status', 'action_key', 'action_expires_at', 'action_completed_at',
        'action_cancelled_at', 'superseded_by_id', 'expires_at',
    ];

    protected $casts = [
        'render_data' => 'array',
        'template_version' => 'integer',
        'deep_link_params' => 'array',
        'in_app_visible' => 'boolean',
        'email_queued_at' => 'immutable_datetime',
        'digest_sent_at' => 'immutable_datetime',
        'read_at' => 'immutable_datetime',
        'dismissed_at' => 'immutable_datetime',
        'archived_at' => 'immutable_datetime',
        'action_expires_at' => 'immutable_datetime',
        'action_completed_at' => 'immutable_datetime',
        'action_cancelled_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(NotificationEvent::class, 'notification_event_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
