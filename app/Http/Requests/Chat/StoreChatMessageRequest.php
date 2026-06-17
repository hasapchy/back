<?php

namespace App\Http\Requests\Chat;

use App\Services\Chat\EntityLinkShareService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreChatMessageRequest extends FormRequest
{
    public const MAX_FILE_SIZE_KB = 51200;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $metadata = $this->input('metadata');
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            if (is_array($decoded)) {
                $this->merge(['metadata' => $decoded]);
            }
        }
    }

    /**
     * @return void
     */
    protected function passedValidation(): void
    {
        $metadata = $this->input('metadata');
        if (! is_array($metadata)) {
            return;
        }

        $type = $metadata['type'] ?? null;
        if (is_string($type) && in_array($type, EntityLinkShareService::RESERVED_METADATA_TYPES, true)) {
            throw new HttpResponseException(response()->json([
                'error' => __('api.common.not_found'),
            ], 404));
        }
    }

    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:10000'],
            'metadata' => ['nullable', 'array'],
            'metadata.type' => ['nullable', 'string'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:'.self::MAX_FILE_SIZE_KB, 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg,gif,bmp,svg,zip,rar,7z,txt,md,mp3,wav,ogg,m4a,webm,mp4,avi,mov'],
            'parent_id' => ['nullable', 'integer', 'exists:chat_messages,id'],
        ];
    }
}
