<?php

namespace App\Http\Requests;

use App\Constants\ArticleStatus;
use App\Models\Advertisement;
use App\Models\Article;
use App\Models\Magazine;
use App\Models\Media;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdvertisementRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'image_media_id' => ['required', 'integer', Rule::exists('media', 'id')->where(fn ($q) => $q->where('scan_status', 'clean'))],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'redirect_url' => ['nullable', 'url', 'max:2048'],
            'placement' => ['required', Rule::in(Advertisement::PLACEMENTS)],
            'status' => ['required', Rule::in(Advertisement::STATUSES)],
            'priority' => ['sometimes', 'integer', 'min:-100000', 'max:100000'],
            'open_in_new_tab' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'targets' => ['required', 'array', 'min:1', 'max:100'],
            'targets.*.target_area' => ['required', Rule::in(['website', 'publication', 'article'])],
            'targets.*.target_mode' => ['required', Rule::in(['single_page', 'all_pages', 'specific_pages', 'all_articles', 'specific_articles'])],
            'targets.*.publication_type' => ['nullable', Rule::in(['magazine', 'journal'])],
            'targets.*.publication_id' => ['nullable', 'integer', 'exists:magazines,id'],
            'targets.*.page_key' => ['nullable', 'string', 'max:255'],
            'targets.*.article_id' => ['nullable', 'integer', 'exists:articles,id'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            foreach ($this->input('targets', []) as $index => $target) {
                $prefix = "targets.$index";
                $area = $target['target_area'] ?? null;
                $mode = $target['target_mode'] ?? null;
                if ($area === 'website' && ($mode !== 'single_page' || empty($target['page_key']))) {
                    $validator->errors()->add("$prefix.page_key", 'Website targets require a page.');
                }
                if (in_array($area, ['publication', 'article'], true)) {
                    $publication = Magazine::find($target['publication_id'] ?? null);
                    if (!$publication || $publication->publication_type !== ($target['publication_type'] ?? null)) {
                        $validator->errors()->add("$prefix.publication_id", 'The publication does not match the selected publication type.');
                    }
                    if ($mode === 'specific_pages' && empty($target['page_key'])) {
                        $validator->errors()->add("$prefix.page_key", 'A page is required for a specific-page target.');
                    }
                    if ($mode === 'specific_articles') {
                        $valid = Article::whereKey($target['article_id'] ?? null)
                            ->where('magazine_id', $target['publication_id'] ?? null)
                            ->whereIn('status', ArticleStatus::queryValues(ArticleStatus::PUBLISHED))
                            ->exists();
                        if (!$valid) $validator->errors()->add("$prefix.article_id", 'The article must be published and belong to the selected publication.');
                    }
                }
            }
        }];
    }
}
