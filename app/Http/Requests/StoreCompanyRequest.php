<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\PreparesCompanyRequestData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;

class StoreCompanyRequest extends FormRequest
{
    use PreparesCompanyRequestData;
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
            'name' => 'required|string|max:255|unique:companies,name',
            'full_name' => 'nullable|string|max:500',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:64',
            'registration_number' => 'nullable|string|max:128',
            'email' => 'nullable|string|max:255',
            'warehouse_number' => 'nullable|string|max:128',
            'logo' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp,svg|max:10240',
            'show_deleted_transactions' => 'nullable|boolean',
            'rounding_decimals' => 'nullable|integer|min:0|max:2',
            'rounding_enabled' => 'nullable|boolean',
            'rounding_direction' => 'nullable|in:standard,up,down,custom',
            'rounding_custom_threshold' => 'nullable|numeric|min:0|max:1',
            'rounding_orders_enabled' => 'nullable|boolean',
            'rounding_contracts_enabled' => 'nullable|boolean',
            'rounding_warehouse_enabled' => 'nullable|boolean',
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
        $this->prepareCompanyRequestData();
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

