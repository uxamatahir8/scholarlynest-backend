<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationEvent extends Model
{
    protected $fillable = [
        'event_uuid', 'deduplication_key', 'event_type', 'schema_version', 'actor_id',
        'article_id', 'magazine_id', 'subject_type', 'subject_id', 'article_audit_log_id',
        'payload', 'occurred_at', 'available_at', 'processing_at', 'processed_at',
        'attempt_count', 'last_error', 'permanently_failed_at', 'failure_code',
    ];

    protected $casts = [
        'payload' => 'array',
        'schema_version' => 'integer',
        'attempt_count' => 'integer',
        'occurred_at' => 'immutable_datetime',
        'available_at' => 'immutable_datetime',
        'processing_at' => 'immutable_datetime',
        'processed_at' => 'immutable_datetime',
        'permanently_failed_at' => 'immutable_datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function magazine(): BelongsTo
    {
        return $this->belongsTo(Magazine::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function articleAuditLog(): BelongsTo
    {
        return $this->belongsTo(ArticleAuditLog::class);
    }

    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }
}
