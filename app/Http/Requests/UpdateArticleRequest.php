<?php

namespace App\Http\Requests;

use App\Constants\ArticleStatus;
use App\Http\Requests\Concerns\ValidatesArticleSubmission;
use App\Models\Article;
use Illuminate\Foundation\Http\FormRequest;

class UpdateArticleRequest extends FormRequest
{
    use ValidatesArticleSubmission;

    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $this->prepareArticleSubmissionForValidation();
    }

    public function rules(): array
    {
        return array_merge($this->articleRules(true), [
            'status' => 'nullable|' . ArticleStatus::validationRuleWithLegacy(),
            'terms_accepted' => 'nullable|boolean',
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->articleAfterValidation($validator, false);
            $article = Article::find($this->route('id'));
            if ($article
                && ArticleStatus::normalize($article->status) === ArticleStatus::DRAFT
                && ArticleStatus::normalize($this->input('status')) === ArticleStatus::SUBMITTED
                && !$this->truthy($this->input('terms_accepted'))) {
                $validator->errors()->add('terms_accepted', 'You must accept the terms and conditions before submitting.');
            }
        });
    }
}
