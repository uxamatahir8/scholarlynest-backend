<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArticleVersion extends Model
{
    protected $fillable = [
        'article_id',
        'created_by',
        'version_number',
        'status_snapshot',
        'metadata_snapshot',
        'change_summary',
    ];

    protected $casts = [
        'metadata_snapshot' => 'array',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ArticleFile::class);
    }
}
