<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class CreateGroupChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'creator_ids' => ['required', 'array', 'min:1', 'max:50'],
            'creator_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ];
    }
}



