<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class CmsPage extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'slug',
        'title',
        'content_text',
        'content_html',
        'is_active',
        'seo_title',
        'seo_description',
        'seo_keywords',
     ];
}
