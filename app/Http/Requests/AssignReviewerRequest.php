<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignReviewerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reviewer_id' => 'required|exists:users,id',
            'sub_editor_assignment_id' => 'nullable|exists:sub_editor_assignments,id',
            'due_date' => 'nullable|date',
        ];
    }
}
