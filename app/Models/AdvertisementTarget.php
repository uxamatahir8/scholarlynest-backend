<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdvertisementTarget extends Model
{
    protected $fillable = ['target_area', 'target_mode', 'publication_type', 'publication_id', 'page_key', 'article_id'];
    public function advertisement() { return $this->belongsTo(Advertisement::class); }
    public function publication() { return $this->belongsTo(Magazine::class, 'publication_id'); }
    public function article() { return $this->belongsTo(Article::class); }
}
