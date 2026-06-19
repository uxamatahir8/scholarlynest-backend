<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scorecard' => 'required|array',
            'recommendation' => 'required|in:accept,minor_revision,major_revision,reject',
            'comments_for_author' => 'nullable|string',
            'confidential_comments' => 'nullable|string',
            'reviewed_manuscript' => 'nullable|file|mimes:pdf,doc,docx|max:25600',
        ];
    }
}
