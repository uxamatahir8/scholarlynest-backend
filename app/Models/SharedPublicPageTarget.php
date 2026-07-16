<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SharedPublicPageTarget extends Model
{
    protected $fillable = ['shared_public_page_id', 'publication_id', 'publication_type'];

    public function page(): BelongsTo
    {
        return $this->belongsTo(SharedPublicPage::class, 'shared_public_page_id');
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Magazine::class, 'publication_id');
    }
}
