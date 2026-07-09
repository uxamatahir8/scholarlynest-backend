<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewQuestionResponse extends Model
{
    protected $fillable = ['review_questionnaire_instance_id', 'review_question_id', 'answer'];

    protected $casts = ['answer' => 'array'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(ReviewQuestion::class, 'review_question_id');
    }
}
