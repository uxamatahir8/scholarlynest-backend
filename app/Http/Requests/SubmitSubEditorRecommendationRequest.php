<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitSubEditorRecommendationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recommendation' => 'required|in:accept,minor_revision,major_revision,reject',
            'comments' => 'nullable|string',
            'internal_notes' => 'nullable|string',
        ];
    }
}
