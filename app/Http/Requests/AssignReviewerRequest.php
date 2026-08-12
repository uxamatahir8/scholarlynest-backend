<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignReviewerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reviewer_id' => 'nullable|exists:users,id',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'affiliation' => 'nullable|string|max:255',
            'suggested_preference_id' => 'nullable|exists:article_reviewer_preferences,id',
            'article_version_id' => 'nullable|integer|exists:article_versions,id',
            'review_round_id' => 'nullable|integer|exists:article_review_rounds,id',
            'round_number' => 'nullable|integer|min:1',
            'idempotency_key' => 'nullable|string|max:100',
            'sub_editor_assignment_id' => 'nullable|exists:sub_editor_assignments,id',
            'due_date' => 'nullable|date',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('reviewer_id') && ! $this->filled('email') && ! $this->filled('suggested_preference_id')) {
                $validator->errors()->add('reviewer_id', 'Select an existing reviewer or provide reviewer invitation details.');
            }
        });
    }
}
