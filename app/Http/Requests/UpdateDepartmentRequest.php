<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:departments,id',
            'head_id' => ['nullable', Rule::exists(User::class, 'id')],
            'deputy_head_id' => ['nullable', Rule::exists(User::class, 'id')],
            'users' => 'nullable|array',
            'users.*' => Rule::exists(User::class, 'id'),
        ];
    }
}
