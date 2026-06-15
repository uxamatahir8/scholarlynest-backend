<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MagazineIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'magazine_id' => 'required|exists:magazines,id',
            'volume_number' => 'required|integer|min:1',
            'issue_number' => 'required|integer|min:1',
            'special_title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_published' => 'nullable|boolean',
            'published_at' => 'nullable|date',
        ];
    }
}
