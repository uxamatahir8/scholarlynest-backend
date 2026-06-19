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
            'plagiarism_status' => 'nullable|string|max:255',
            'plagiarism_score' => 'nullable|numeric|min:0|max:100',
            'plagiarism_report_path' => 'nullable|string|max:2048',
            'plagiarism_report' => 'nullable|file|mimes:pdf,doc,docx,txt|max:25600',
            'decision' => 'required|in:send_to_review,reject',
            'comments' => 'nullable|string',
        ];
    }
}
