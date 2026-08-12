<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewQuestionnaireVersion extends Model
{
    protected $fillable = [
        'review_questionnaire_id',
        'version_number',
        'is_active',
        'published_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(ReviewQuestionnaire::class, 'review_questionnaire_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ReviewQuestion::class)->orderBy('sort_order')->orderBy('id');
    }
}
