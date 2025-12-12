<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'supervisor_id' => 'required|exists:users,id',
            'executor_id' => 'required|exists:users,id',
            'project_id' => 'nullable|exists:projects,id',
            'status' => 'nullable|in:pending,in_progress,completed,postponed',
            'deadline' => 'nullable|date',
            'files' => 'nullable|array',
            'comments' => 'nullable|array',
        ];

        // При обновлении не требуем title
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['title'] = 'sometimes|required|string|max:255';
        }

        return $rules;
    }
}
