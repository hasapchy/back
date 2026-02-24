<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class UpdateChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:10000'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*.path' => ['required', 'string'],
            'files.*.name' => ['nullable', 'string', 'max:255'],
            'files.*.mime_type' => ['nullable', 'string', 'max:100'],
        ];
    }
}
