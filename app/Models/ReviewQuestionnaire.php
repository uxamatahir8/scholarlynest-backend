<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewQuestionnaire extends Model
{
    protected $fillable = ['name', 'is_active', 'created_by'];

    protected $casts = ['is_active' => 'boolean'];

    public function versions(): HasMany
    {
        return $this->hasMany(ReviewQuestionnaireVersion::class);
    }
}
