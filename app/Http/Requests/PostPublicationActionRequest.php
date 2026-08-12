<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostPublicationActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action_type' => 'required|in:correction,retraction,update,archive,unpublish',
            'reason' => 'required|string',
            'notice_text' => 'required|string',
            'approved_by' => 'nullable|exists:users,id',
        ];
    }
}
