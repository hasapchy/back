<?php

namespace App\Http\Requests;

use App\Enums\ListFilterPresetSource;
use App\Support\ListFilterPresetAppearance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserFilterPresetRequest extends FormRequest
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
            'source' => ['required', 'string', Rule::in(ListFilterPresetSource::values())],
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['required', 'string', Rule::in(ListFilterPresetAppearance::iconValues())],
            'color' => ['required', 'string', Rule::in(ListFilterPresetAppearance::colorValues())],
            'filters' => ['required', 'array'],
        ];
    }
}
