<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PublishArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('publication_sections'))) {
            $decoded = json_decode($this->input('publication_sections'), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->merge(['publication_sections' => $decoded]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'magazine_issue_id' => 'nullable|exists:magazine_issues,id',
            'doi' => 'nullable|string|max:255',
            'published_year' => 'required|integer|min:2000|max:' . now()->year,
            'published_month' => 'required|string|max:50',
            'page_start' => 'nullable|integer|min:1',
            'page_end' => 'nullable|integer|min:1|gte:page_start',
            'publication_pdf_upload_id' => 'nullable|string|exists:media_upload_sessions,id',
            'final_source_file_id' => 'nullable|integer|exists:article_files,id',
            'article_type' => 'nullable|string|max:120',
            'article_category' => 'nullable|string|max:120',
            'open_access_label' => 'nullable|string|max:120',
            'is_peer_reviewed' => 'nullable|boolean',
            'academic_editor' => 'nullable|string|max:255',
            'received_at' => 'nullable|date',
            'accepted_at' => 'nullable|date',
            'published_at' => 'nullable|date',
            'license_statement' => 'nullable|string|max:10000',
            'data_availability_statement' => 'nullable|string|max:10000',
            'funding_statement' => 'nullable|string|max:10000',
            'competing_interests_statement' => 'nullable|string|max:10000',
            'abbreviations' => 'nullable|string|max:10000',
            'citation_text' => 'nullable|string|max:10000',
            'publication_sections' => 'nullable|array',
            'publication_sections.*.section_key' => 'nullable|string|max:120',
            'publication_sections.*.title' => 'nullable|string|max:255',
            'publication_sections.*.content_html' => 'nullable|string',
            'publication_sections.*.sort_order' => 'nullable|integer|min:1|max:1000',
            'publication_sections.*.media_upload_session_id' => 'nullable|string|exists:media_upload_sessions,id',
            'publication_file_settings' => 'nullable|array',
            'publication_file_settings.*.file_id' => 'required|integer|exists:article_files,id',
            'publication_file_settings.*.show_on_article' => 'nullable|boolean',
            'publication_file_settings.*.show_in_downloads' => 'nullable|boolean',
            'publication_file_settings.*.include_in_package' => 'nullable|boolean',
        ];
    }
}
