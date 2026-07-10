<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    public const STATUSES = ['submitted', 'in_review', 'waiting_for_user', 'resolved', 'closed'];
    public const ISSUE_TYPES = ['technical_issue', 'account_issue', 'article_submission', 'reviewer_issue', 'payment_billing', 'publication_issue', 'other'];

    protected $fillable = [
        'ticket_number',
        'user_id',
        'issue_type',
        'title',
        'details',
        'status',
        'priority',
        'last_reply_at',
        'last_replied_by_id',
        'closed_at',
        'closed_by_id',
    ];

    protected $casts = [
        'last_reply_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (SupportTicket $ticket) {
            if (!$ticket->ticket_number) {
                $year = optional($ticket->created_at)->format('Y') ?: now()->format('Y');
                $ticket->forceFill([
                    'ticket_number' => sprintf('SUP-%s-%06d', $year, $ticket->id),
                ])->saveQuietly();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lastRepliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_replied_by_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(SupportTicketActivity::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole('super_admin') || $user->hasRole('admin') || $user->hasPermission('support_ticket_management')) {
            return $query;
        }

        return $query->where('user_id', $user->id);
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return $status ? $query->where('status', $status) : $query;
    }

    public function scopeIssueType(Builder $query, ?string $issueType): Builder
    {
        return $issueType ? $query->where('issue_type', $issueType) : $query;
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (!$search) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($search) {
            $builder->where('ticket_number', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%");
        });
    }

    public function scopeLatestActivity(Builder $query): Builder
    {
        return $query->orderByDesc('last_reply_at')->orderByDesc('updated_at');
    }
}
