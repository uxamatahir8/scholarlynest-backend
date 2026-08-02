<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LifecycleCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return match ($this->route()?->getActionMethod()) {
            'screen' => ['article_version_id' => 'required|integer|exists:article_versions,id', 'decision' => 'required|in:pass,reject', 'reason' => 'nullable|string|max:10000'],
            'assignSubEditor' => ['article_version_id' => 'required|integer|exists:article_versions,id', 'sub_editor_id' => 'required|integer|exists:users,id', 'due_at' => 'nullable|date'],
            'recommend' => ['recommendation' => 'required|string|max:100', 'author_comments' => 'nullable|string|max:20000', 'internal_comments' => 'nullable|string|max:20000'],
            'inviteReviewer' => ['article_version_id' => 'required|integer|exists:article_versions,id', 'review_round_id' => 'required|integer|exists:article_review_rounds,id', 'round_number' => 'required|integer|min:1', 'reviewer_id' => 'nullable|integer|exists:users,id', 'name' => 'nullable|string|max:255', 'email' => 'nullable|email|max:255', 'due_at' => 'nullable|date'],
            'reviewResponse' => ['decision' => 'required|in:accept,decline', 'reason' => 'nullable|string|max:5000'],
            'saveReviewDraft' => ['recommendation' => 'nullable|string|max:100', 'author_comments' => 'nullable|string|max:30000', 'confidential_comments' => 'nullable|string|max:30000', 'questionnaire_responses' => 'nullable|array', 'questionnaire_responses.*.question_id' => 'required|integer|exists:review_questions,id', 'questionnaire_responses.*.answer' => 'nullable', 'questionnaire_responses.*.comment' => 'nullable|string|max:10000'],
            'submitReview' => ['recommendation' => 'required|string|max:100', 'author_comments' => 'required|string|max:30000', 'confidential_comments' => 'nullable|string|max:30000'],
            'editorialDecision' => ['article_version_id' => 'required|integer|exists:article_versions,id', 'decision' => 'required|in:accepted,rejected,minor_revision,major_revision', 'decision_source' => 'required|string|max:100', 'author_comments' => 'nullable|string|max:30000', 'internal_notes' => 'nullable|string|max:30000', 'revision_due_at' => 'nullable|date', 'pending_review_policy' => 'nullable|in:keep_open,close_pending', 'pending_review_override_reason' => 'nullable|string|max:10000'],
            'assignCopyEditor' => ['copy_editor_id' => 'required|integer|exists:users,id', 'due_at' => 'nullable|date'],
            'completeCopyediting' => ['copyedited_file_id' => 'required|integer|exists:article_files,id', 'notes' => 'nullable|string|max:20000'],
            'requestProof' => ['source_file_id' => 'required|integer|exists:article_files,id', 'due_at' => 'nullable|date'],
            'proofResponse' => ['decision' => 'required|in:approve,corrections', 'comments' => 'nullable|string|max:20000', 'article_file_id' => 'nullable|integer|exists:article_files,id'],
            'correctProof' => ['article_file_id' => 'required|integer|exists:article_files,id', 'notes' => 'nullable|string|max:20000'],
            'preparePublication' => ['magazine_issue_id' => 'nullable|integer|exists:magazine_issues,id', 'doi' => 'nullable|string|max:255', 'page_start' => 'nullable|integer|min:1', 'page_end' => 'nullable|integer|min:1', 'scheduled_for' => 'nullable|date'],
            'selectPublicationFiles' => ['selections' => 'required|array|min:1', 'selections.*.article_file_id' => 'required|integer|exists:article_files,id', 'selections.*.public_role' => 'required|in:primary_manuscript,figure,supplementary', 'selections.*.is_primary' => 'sometimes|boolean', 'selections.*.is_public' => 'sometimes|boolean'],
            'unpublish' => ['reason' => 'required|string|max:10000'],
            default => [],
        };
    }

    public function idempotencyKey(): string
    {
        return trim((string) $this->header('Idempotency-Key'));
    }
}
