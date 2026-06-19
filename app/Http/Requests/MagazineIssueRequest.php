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
            'issue_month' => 'nullable|string|max:50',
            'issue_year' => 'nullable|integer|min:1900|max:' . (now()->year + 5),
            'special_title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'cover_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:5120',
            'status' => 'nullable|in:draft,published,unpublished',
            'is_published' => 'nullable|boolean',
            'published_at' => 'nullable|date',
        ];
    }
}
