<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewQuestionOption extends Model
{
    protected $fillable = ['review_question_id', 'label', 'value', 'sort_order'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(ReviewQuestion::class, 'review_question_id');
    }
}
