<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleTransferRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'article_id',
        'from_magazine_id',
        'to_magazine_id',
        'requested_by_user_id',
        'responded_by_user_id',
        'status',
        'editor_comments',
        'author_rejection_reason',
        'previous_article_status',
        'next_article_status',
        'requested_at',
        'responded_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function fromMagazine(): BelongsTo
    {
        return $this->belongsTo(Magazine::class, 'from_magazine_id');
    }

    public function toMagazine(): BelongsTo
    {
        return $this->belongsTo(Magazine::class, 'to_magazine_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_user_id');
    }
}
