<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadDriveFilesRequest extends FormRequest
{
    /**
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'folder_id' => 'nullable|integer',
            'file_paths' => 'nullable|array',
            'file_paths.*' => 'nullable|string|max:500',
        ];
    }
}
