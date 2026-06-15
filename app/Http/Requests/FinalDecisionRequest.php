<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinalDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => 'required|in:accepted,rejected,minor_revision,major_revision',
            'decision_source' => 'required|in:editor_personal_review,sub_editor_recommendation,reviewer_recommendation,mixed_editorial_decision',
            'comments_for_author' => 'nullable|string',
            'internal_notes' => 'nullable|string',
        ];
    }
}
