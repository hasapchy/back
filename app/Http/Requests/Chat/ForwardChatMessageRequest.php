<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class ForwardChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_chat_id' => ['required', 'integer', 'exists:chats,id'],
            'hide_sender_name' => ['sometimes', 'boolean'],
        ];
    }
}
