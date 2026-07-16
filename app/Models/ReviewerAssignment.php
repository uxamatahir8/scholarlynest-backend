<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewerAssignment extends Model
{
    protected $fillable = [
        'article_id',
        'sub_editor_assignment_id',
        'reviewer_id',
        'invitee_name',
        'invitee_email',
        'invite_token_hash',
        'invited_at',
        'invite_expires_at',
        'assigned_by',
        'status',
        'due_date',
        'accepted_at',
        'declined_at',
        'decline_reason',
        'account_created_at',
        'questionnaire_instance_id',
        'completed_at',
        'recommendation',
        'comments_for_author',
        'confidential_comments',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'invited_at' => 'datetime',
        'invite_expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
        'account_created_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function subEditorAssignment(): BelongsTo
    {
        return $this->belongsTo(SubEditorAssignment::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function questionnaireInstance(): BelongsTo
    {
        return $this->belongsTo(ReviewQuestionnaireInstance::class, 'questionnaire_instance_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
