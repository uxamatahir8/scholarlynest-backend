<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewQuestion extends Model
{
    public const TYPES = ['radio', 'checkbox', 'dropdown', 'single_line', 'textarea'];

    protected $fillable = [
        'review_questionnaire_version_id',
        'prompt',
        'response_type',
        'is_required',
        'sort_order',
    ];

    protected $casts = ['is_required' => 'boolean'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(ReviewQuestionnaireVersion::class, 'review_questionnaire_version_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ReviewQuestionOption::class)->orderBy('sort_order')->orderBy('id');
    }
}
