<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesArticleSubmission;
use Illuminate\Foundation\Http\FormRequest;

class StoreArticleRequest extends FormRequest
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
        return $this->articleRules();
    }

    public function withValidator($validator): void
    {
        $validator->after(fn ($validator) => $this->articleAfterValidation($validator));
    }
}
