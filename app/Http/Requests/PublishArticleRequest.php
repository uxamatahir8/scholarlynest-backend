<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PublishArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'magazine_issue_id' => 'nullable|exists:magazine_issues,id',
            'doi' => 'nullable|string|max:255',
            'published_year' => 'required|integer|min:2000|max:' . now()->year,
            'published_month' => 'required|string|max:50',
            'page_start' => 'nullable|integer|min:1',
            'page_end' => 'nullable|integer|min:1|gte:page_start',
            'publication_pdf_upload_id' => 'nullable|string|exists:media_upload_sessions,id',
        ];
    }
}
