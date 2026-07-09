<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleAsset extends Model
{
    protected $table = 'article_assets';

    protected $fillable = [
        'article_id',
        'asset_type',
        'disk',
        'file_path',
        'storage_key',
        'original_filename',
        'safe_original_filename',
        'title',
        'caption',
        'description',
        'sort_order',
        'file_size',
        'mime_type',
        'checksum_sha256',
        'scan_status',
        'scan_engine',
        'scanned_at',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    /**
     * Get the article this asset is attached to.
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
