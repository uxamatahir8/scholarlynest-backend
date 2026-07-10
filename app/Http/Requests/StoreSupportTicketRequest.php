<?php

namespace App\Http\Requests;

use App\Models\SupportTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'issue_type' => ['required', 'string', Rule::in(SupportTicket::ISSUE_TYPES)],
            'title' => ['required', 'string', 'max:180'],
            'details' => ['required', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['required', 'string', 'exists:media_upload_sessions,id'],
        ];
    }
}
