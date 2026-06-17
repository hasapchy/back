<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectDriveFolderRequest extends FormRequest
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
            'preset_keys' => ['nullable', 'array'],
            'preset_keys.*' => ['string', Rule::in(array_keys($this->presetMap()))],
            'custom_names' => ['nullable', 'array'],
            'custom_names.*' => ['string', 'max:191'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function presetMap(): array
    {
        return [
            'invoices' => 'Инвойсы',
            'contracts' => 'Контракты',
            'acts' => 'Фактуры',
            'requests' => 'Запросы',
            'offers' => 'Предложения',
        ];
    }
}
