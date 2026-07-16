<?php

namespace App\Models;

use App\Services\Media\MediaStorageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Advertisement extends Model
{
    use SoftDeletes;

    public const PLACEMENTS = ['header_banner', 'sidebar_sticky', 'content_top', 'content_middle', 'content_bottom', 'footer_banner'];
    public const STATUSES = ['draft', 'active', 'inactive', 'expired'];

    protected $fillable = ['title', 'image_media_id', 'alt_text', 'redirect_url', 'placement', 'status', 'priority', 'open_in_new_tab', 'starts_at', 'ends_at', 'created_by', 'updated_by'];
    protected $casts = ['open_in_new_tab' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'priority' => 'integer'];

    public function targets() { return $this->hasMany(AdvertisementTarget::class); }
    public function image() { return $this->belongsTo(Media::class, 'image_media_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    public function publicPayload(): array
    {
        $key = $this->image?->storage_key;
        return [
            'id' => $this->id,
            'title' => $this->title,
            'image_url' => $key ? app(MediaStorageService::class)->publicOrTemporaryUrl($key) : null,
            'redirect_url' => $this->redirect_url,
            'alt_text' => $this->alt_text ?: $this->title,
            'placement' => $this->placement,
            'open_in_new_tab' => $this->open_in_new_tab,
        ];
    }
}
