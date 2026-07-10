<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScreenArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => 'required|in:send_to_review,reject',
            'comments' => 'nullable|string',
        ];
    }
}
