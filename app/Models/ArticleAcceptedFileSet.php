<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArticleAcceptedFileSet extends Model
{
    public const POLICY_CARRY_FORWARD = 'carry_forward_latest_per_purpose';
    public const POLICY_VERSION_LOCAL = 'accepted_version_only';

    protected $fillable = [
        'article_id',
        'article_version_id',
        'accepted_by',
        'accepted_at',
        'selection_policy',
        'superseded_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class, 'article_version_id');
    }

    public function accepter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ArticleAcceptedFileSetItem::class, 'accepted_file_set_id');
    }
}
