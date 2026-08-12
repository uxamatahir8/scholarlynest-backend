<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreArticleThreadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'min:3', 'max:180'], 'article_version_id' => ['nullable', 'integer']];
    }
}
