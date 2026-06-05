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
        $subjectIdRules = ['required', 'integer'];
        if ($this->input('subject_type') === 'user') {
            $subjectIdRules[] = 'exists:users,id';
        } elseif ($this->input('subject_type') === 'role') {
            $subjectIdRules[] = 'exists:roles,id';
        }

        return [
            'resource_type' => ['required', Rule::in([DrivePermission::RESOURCE_FOLDER, DrivePermission::RESOURCE_FILE])],
            'resource_id' => 'required|integer',
            'subject_type' => 'required|in:user,role',
            'subject_id' => $subjectIdRules,
            'ability' => 'required|in:view,upload,rename,delete,share',
            'effect' => 'required|in:allow,deny',
        ];
    }
}
