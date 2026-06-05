<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDriveFolderRequest extends FormRequest
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
            'name' => 'required|string|max:191',
            'parent_id' => 'nullable|integer',
            'icon' => ['nullable', 'string', 'max:120', Rule::in(config('drive.folder_icons'))],
            'icon_color' => 'nullable|string|max:7',
        ];
    }
}
