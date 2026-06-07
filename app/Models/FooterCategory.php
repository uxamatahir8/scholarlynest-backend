<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;

class FooterCategory extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'name',
        'sort_order',
    ];

    /**
     * Get the pages associated with the category.
     */
    public function pages(): HasMany
    {
        return $this->hasMany(FooterPage::class);
    }
}
