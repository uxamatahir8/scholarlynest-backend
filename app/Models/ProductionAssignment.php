<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionAssignment extends Model
{
    protected $fillable = [
        'article_id',
        'article_version_id',
        'accepted_file_set_id',
        'user_id',
        'role',
        'assigned_by',
        'status',
        'due_date',
        'completed_at',
        'revoked_at',
        'notes',
        'idempotency_key',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'completed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class, 'article_version_id');
    }

    public function acceptedFileSet(): BelongsTo
    {
        return $this->belongsTo(ArticleAcceptedFileSet::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
