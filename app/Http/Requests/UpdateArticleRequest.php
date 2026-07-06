<?php

namespace App\Http\Requests;

use App\Constants\ArticleStatus;
use App\Http\Requests\Concerns\ValidatesArticleSubmission;
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
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(fn ($validator) => $this->articleAfterValidation($validator, false));
    }
}
