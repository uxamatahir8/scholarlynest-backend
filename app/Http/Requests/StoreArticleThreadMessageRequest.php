<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreArticleThreadMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:'.config('article_threads.max_message_length', 10000)],
            'parent_message_id' => ['nullable', 'integer'],
            'mentions' => ['array', 'max:20'], 'mentions.*' => ['integer', 'distinct'],
            'attachment_ids' => ['array', 'max:'.config('article_threads.max_attachments', 10)],
            'attachment_ids.*' => ['integer', 'distinct'],
            'client_request_id' => ['nullable', 'string', 'max:100'],
        ];
    }
}
