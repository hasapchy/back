<?php

namespace App\Http\Requests;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $department = Department::query()->find($this->route('id'));

        if (! $department) {
            return true;
        }

        return $this->user()->can('update', $department);
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:departments,id',
            'head_id' => 'nullable|exists:users,id',
            'deputy_head_id' => 'nullable|exists:users,id',
            'users' => 'nullable|array',
            'users.*' => 'exists:users,id',
        ];
    }
}
