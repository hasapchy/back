<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class StoreChatMessageRequest extends FormRequest
{
    public const MAX_FILE_SIZE_KB = 51200;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:10000'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:'.self::MAX_FILE_SIZE_KB, 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg,gif,bmp,svg,zip,rar,7z,txt,md,mp3,wav,ogg,m4a,webm,mp4,avi,mov'],
            'parent_id' => ['nullable', 'integer', 'exists:chat_messages,id'],
        ];
    }
}



