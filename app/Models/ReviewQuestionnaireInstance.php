<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewQuestionnaireInstance extends Model
{
    protected $fillable = [
        'article_id',
        'reviewer_assignment_id',
        'reviewer_id',
        'review_questionnaire_version_id',
        'submitted_at',
    ];

    protected $casts = ['submitted_at' => 'datetime'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(ReviewQuestionnaireVersion::class, 'review_questionnaire_version_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(ReviewQuestionResponse::class);
    }
}
