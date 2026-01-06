<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class MarkChatReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // If omitted, backend will mark as read up to the latest message in the chat.
            'last_message_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}


