<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MoveDriveFilesRequest extends FormRequest
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
            'file_ids' => 'required|array|min:1',
            'file_ids.*' => 'integer|distinct',
            'target_folder_id' => 'nullable|integer',
        ];
    }
}
