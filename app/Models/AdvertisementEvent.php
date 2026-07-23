<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdvertisementEvent extends Model
{
    protected $fillable = ['advertisement_id', 'article_id', 'publication_id', 'event_type', 'placement', 'sidebar_side', 'session_hash'];
}
