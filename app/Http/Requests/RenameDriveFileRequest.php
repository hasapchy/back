<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RenameDriveFileRequest extends FormRequest
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
        ];
    }
}
