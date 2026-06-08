<?php

namespace App\Http\Requests;

use App\Enums\ListFilterPresetSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetDefaultUserFilterPresetRequest extends FormRequest
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
            'preset_id' => ['nullable', 'integer'],
        ];
    }
}
