<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;

class StoreCompanyRequest extends FormRequest
{
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
        return [
            'name' => 'required|string|max:255|unique:central.companies,name',
            'logo' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp,svg|max:10240',
            'show_deleted_transactions' => 'nullable|boolean',
            'rounding_decimals' => 'nullable|integer|min:0|max:5',
            'rounding_enabled' => 'nullable|boolean',
            'rounding_direction' => 'nullable|in:standard,up,down,custom',
            'rounding_custom_threshold' => 'nullable|numeric|min:0|max:1',
            'rounding_quantity_decimals' => 'nullable|integer|min:0|max:5',
            'rounding_quantity_enabled' => 'nullable|boolean',
            'rounding_quantity_direction' => 'nullable|in:standard,up,down,custom',
            'rounding_quantity_custom_threshold' => 'nullable|numeric|min:0|max:1',
            'skip_project_order_balance' => 'nullable|boolean',
            'work_schedule' => 'nullable|array',
        ];
    }

    /**
     * Подготовить данные для валидации
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $data = $this->all();

        if (isset($data['show_deleted_transactions'])) {
            $data['show_deleted_transactions'] = filter_var($data['show_deleted_transactions'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['rounding_enabled'])) {
            $data['rounding_enabled'] = filter_var($data['rounding_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['rounding_quantity_enabled'])) {
            $data['rounding_quantity_enabled'] = filter_var($data['rounding_quantity_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['skip_project_order_balance'])) {
            $data['skip_project_order_balance'] = filter_var($data['skip_project_order_balance'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($data['rounding_custom_threshold']) && $data['rounding_custom_threshold'] === '') {
            $data['rounding_custom_threshold'] = null;
        }
        if (isset($data['rounding_quantity_custom_threshold']) && $data['rounding_quantity_custom_threshold'] === '') {
            $data['rounding_quantity_custom_threshold'] = null;
        }

        if (isset($data['rounding_enabled'])) {
            $roundingEnabled = $data['rounding_enabled'];
            if ($roundingEnabled === false || $roundingEnabled === 'false' || $roundingEnabled === '0' || $roundingEnabled === 0) {
                $data['rounding_direction'] = null;
                $data['rounding_custom_threshold'] = null;
            }
        }

        if (isset($data['rounding_quantity_enabled'])) {
            $roundingQuantityEnabled = $data['rounding_quantity_enabled'];
            if ($roundingQuantityEnabled === false || $roundingQuantityEnabled === 'false' || $roundingQuantityEnabled === '0' || $roundingQuantityEnabled === 0) {
                $data['rounding_quantity_direction'] = null;
                $data['rounding_quantity_custom_threshold'] = null;
            }
        }

        $this->merge($data);
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

