<?php

namespace App\Http\Requests;

use App\Models\DrivePermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDrivePermissionsRequest extends FormRequest
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
            'resource_type' => ['required', Rule::in([DrivePermission::RESOURCE_FOLDER, DrivePermission::RESOURCE_FILE])],
            'resource_id' => 'required|integer',
        ];
    }
}
