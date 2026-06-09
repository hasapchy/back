<?php

namespace App\Http\Requests;

use App\Support\ListFilterPresetAppearance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserFilterPresetRequest extends FormRequest
{
    /**
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'icon' => ['sometimes', 'required', 'string', Rule::in(ListFilterPresetAppearance::iconValues())],
            'color' => ['sometimes', 'required', 'string', Rule::in(ListFilterPresetAppearance::colorValues())],
            'filters' => ['sometimes', 'required', 'array'],
        ];
    }
}
