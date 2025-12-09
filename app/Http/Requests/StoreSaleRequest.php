<?php

namespace App\Http\Requests;

use App\Rules\CashRegisterAccessRule;
use App\Rules\WarehouseAccessRule;
use App\Rules\ProjectAccessRule;
use App\Rules\ClientAccessRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class StoreSaleRequest extends FormRequest
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
            'client_id'     => ['required', 'integer', new ClientAccessRule()],
            'project_id'    => ['nullable', 'integer', new ProjectAccessRule()],
            'type'          => 'required|in:cash,balance',
            'cash_id'       => ['nullable', 'integer', new CashRegisterAccessRule()],
            'warehouse_id'  => ['required', 'integer', new WarehouseAccessRule()],
            'currency_id'   => 'nullable|integer|exists:currencies,id',
            'discount'      => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:fixed,percent|required_with:discount',
            'date'          => 'nullable|date',
            'note'          => 'nullable|string',
            'products'      => 'required|array',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity'   => 'required|numeric|min:1',
            'products.*.price'      => 'required|numeric|min:0',
        ];
    }

    /**
     * Обработать неудачную валидацию
     *
     * @param Validator $validator
     * @return void
     */
    protected function failedValidation(Validator $validator)
    {
        throw (new ValidationException($validator))
            ->errorBag($this->errorBag)
            ->redirectTo($this->getRedirectUrl());
    }
}
