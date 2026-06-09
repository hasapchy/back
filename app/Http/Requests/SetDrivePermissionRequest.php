<?php

namespace App\Http\Requests;

use App\Models\DrivePermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetDrivePermissionRequest extends FormRequest
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
        $resourceType = (string) $this->input('resource_type');

        return [
            'resource_type' => ['required', Rule::in([DrivePermission::RESOURCE_FOLDER, DrivePermission::RESOURCE_FILE])],
            'resource_id' => 'required|integer',
            'subject_id' => ['required', 'integer', 'exists:users,id'],
            'ability' => ['required', Rule::in(DrivePermission::abilitiesForResourceType($resourceType))],
        ];
    }
}
