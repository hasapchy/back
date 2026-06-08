<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesCompanyRoundingFields;
use App\Http\Requests\Concerns\ValidatesCompanyTransactionCategoryBindings;
use App\Http\Validation\UiThemeRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    use ValidatesCompanyRoundingFields;
    use ValidatesCompanyTransactionCategoryBindings;
    /**
     * Определить, авторизован ли пользователь для выполнения этого запроса
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Получить правила валидации
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        $companyId = $this->route('id');

        return [
            'name' => "required|string|max:255|unique:companies,name,{$companyId}",
            'full_name' => 'nullable|string|max:500',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:64',
            'registration_number' => 'nullable|string|max:128',
            'email' => 'nullable|string|max:255',
            'warehouse_number' => 'nullable|string|max:128',
            'logo' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp,svg|max:10240',
            'show_deleted_transactions' => 'nullable|boolean',
            'display_decimals' => 'nullable|integer|min:0|max:5',
            'rounding_enabled' => 'nullable|boolean',
            'rounding_direction' => 'nullable|in:standard,up,down,custom',
            'rounding_custom_threshold' => 'nullable|numeric|min:0|max:1',
            'rounding_quantity_decimals' => 'nullable|integer|min:0|max:5',
            'rounding_quantity_enabled' => 'nullable|boolean',
            'rounding_quantity_direction' => 'nullable|in:standard,up,down,custom',
            'rounding_quantity_custom_threshold' => 'nullable|numeric|min:0|max:1',
            'skip_project_order_balance' => 'nullable|boolean',
            'work_schedule' => 'nullable|array',
            'transaction_category_bindings' => 'nullable|array',
        ] + UiThemeRules::rules() + $this->roundingModuleValidationRules();
    }

    /**
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $data = $this->all();

        foreach ([
            'show_deleted_transactions',
            'rounding_quantity_enabled',
            'skip_project_order_balance',
        ] as $field) {
            if (isset($data[$field])) {
                $data[$field] = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (isset($data['rounding_quantity_custom_threshold']) && $data['rounding_quantity_custom_threshold'] === '') {
            $data['rounding_quantity_custom_threshold'] = null;
        }

        $data = $this->normalizeRoundingFields($data);

        if (isset($data['rounding_quantity_enabled']) && ! $data['rounding_quantity_enabled']) {
            $data['rounding_quantity_direction'] = null;
            $data['rounding_quantity_custom_threshold'] = null;
        }

        if (array_key_exists('ui_theme', $data) && $data['ui_theme'] === '') {
            $data['ui_theme'] = null;
        }
        if (isset($data['ui_theme']) && is_string($data['ui_theme'])) {
            $decoded = json_decode($data['ui_theme'], true);
            $data['ui_theme'] = is_array($decoded) ? $decoded : null;
        }

        $this->merge($data);
    }

    /**
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->assertCompanyTransactionCategoryBindings(
                $validator,
                $this->input('transaction_category_bindings'),
            );
        });
    }

    /**
     * Обработать неудачную валидацию
     *
     * @param Validator $validator
     * @return void
     */
    protected function failedValidation(Validator $validator)
    {
        throw new \Illuminate\Validation\ValidationException($validator);
    }
}
