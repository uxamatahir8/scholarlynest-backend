<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DirectPublicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $common = [
            'magazine_id' => ['sometimes', 'integer', 'exists:magazines,id'],
            'magazine_issue_id' => ['nullable', 'integer', 'exists:magazine_issues,id'],
            'title' => ['sometimes', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'abstract' => ['sometimes', 'string'],
            'keywords' => ['sometimes', 'array', 'max:30'],
            'keywords.*' => ['string', 'max:100'],
            'article_type' => ['nullable', 'string', 'max:120'],
            'article_category' => ['nullable', 'string', 'max:120'],
            'subject_area' => ['nullable', 'string', 'max:120'],
            'language' => ['nullable', 'string', 'max:80'],
            'open_access_label' => ['nullable', 'string', 'max:120'],
            'academic_editor' => ['nullable', 'string', 'max:255'],
            'received_at' => ['nullable', 'date'],
            'ethical_approval_statement' => ['nullable', 'string'],
            'conflict_of_interest_statement' => ['nullable', 'string'],
            'funding_statement' => ['nullable', 'string'],
            'data_availability_statement' => ['nullable', 'string'],
            'author_contribution_statement' => ['nullable', 'string'],
            'license_statement' => ['nullable', 'string'],
            'citation_text' => ['nullable', 'string'],
            'abbreviations' => ['nullable', 'string'],
            'doi' => ['nullable', 'string', 'max:255'],
            'page_start' => ['nullable', 'integer', 'min:1'],
            'page_end' => ['nullable', 'integer', 'gte:page_start'],
            'online_publication_date' => ['nullable', 'date'],
            'print_publication_date' => ['nullable', 'date'],
            'authors' => ['sometimes', 'array', 'min:1'],
            'authors.*.name' => ['required_with:authors', 'string', 'max:255'],
            'authors.*.email' => ['required_with:authors', 'email', 'max:255'],
            'authors.*.affiliation' => ['required_with:authors', 'string', 'max:255'],
            'authors.*.department' => ['nullable', 'string', 'max:255'],
            'authors.*.country' => ['nullable', 'string', 'max:120'],
            'authors.*.orcid' => ['nullable', 'string', 'max:40'],
            'authors.*.is_corresponding' => ['sometimes', 'boolean'],
            'publication_sections' => ['sometimes', 'array', 'max:100'],
            'publication_sections.*.section_key' => ['nullable', 'string', 'max:120'],
            'publication_sections.*.title' => ['nullable', 'string', 'max:255'],
            'publication_sections.*.content_html' => ['nullable', 'string'],
            'publication_sections.*.sort_order' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'publication_sections.*.media_upload_session_id' => ['nullable', 'string', 'exists:media_upload_sessions,id'],
        ];

        return match ($this->route()?->getActionMethod()) {
            'store' => array_merge($common, [
                'magazine_id' => ['required', 'integer', 'exists:magazines,id'],
                'title' => ['required', 'string', 'max:255'],
                'abstract' => ['sometimes', 'nullable', 'string'],
                'authors' => ['sometimes', 'array'],
            ]),
            'attachFile' => [
                'upload_id' => ['required', 'uuid'],
                'purpose' => ['required', Rule::in(array_keys(config('media_uploads.direct_publication_purposes', [])))],
                'file_title' => ['nullable', 'string', 'max:255'],
            ],
            'selectPrimary' => ['article_file_id' => ['required', 'integer', 'exists:article_files,id']],
            'publicAssets' => [
                'publication_file_settings' => ['present', 'array'],
                'publication_file_settings.*.file_id' => ['required', 'integer', 'distinct', 'exists:article_files,id'],
                'publication_file_settings.*.show_on_article' => ['sometimes', 'boolean'],
                'publication_file_settings.*.show_in_downloads' => ['sometimes', 'boolean'],
                'publication_file_settings.*.include_in_package' => ['sometimes', 'boolean'],
            ],
            'schedule' => ['scheduled_at' => ['required', 'date', 'after:now'], 'confirmed' => ['accepted']],
            'publish' => ['confirmed' => ['accepted']],
            'unpublish' => ['reason' => ['required', 'string', 'min:10', 'max:2000']],
            default => $common,
        };
    }
}
