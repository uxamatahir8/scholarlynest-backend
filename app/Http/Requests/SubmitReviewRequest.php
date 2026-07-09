<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['scorecard', 'questionnaire_responses'] as $key) {
            if (!is_string($this->input($key))) {
                continue;
            }

            $decoded = json_decode($this->input($key), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->merge([$key => $decoded]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'scorecard' => 'required|array',
            'recommendation' => 'required|in:accept,minor_revision,major_revision,reject',
            'comments_for_author' => 'nullable|string',
            'confidential_comments' => 'nullable|string',
            'questionnaire_responses' => 'nullable|array',
            'questionnaire_responses.*.question_id' => 'required_with:questionnaire_responses|integer|exists:review_questions,id',
            'questionnaire_responses.*.answer' => 'nullable',
            'reviewed_manuscript' => 'nullable|file|mimes:pdf,doc,docx|max:25600',
            'reviewed_manuscript_upload_id' => 'nullable|string|exists:media_upload_sessions,id',
        ];
    }
}
