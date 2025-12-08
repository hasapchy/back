<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class StoreOrderRequest extends FormRequest
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
            'client_id'            => 'required|integer|exists:clients,id',
            'project_id'           => 'nullable|integer|exists:projects,id',
            'cash_id'              => 'nullable|integer|exists:cash_registers,id',
            'warehouse_id'         => 'required|integer|exists:warehouses,id',
            'currency_id'          => 'nullable|integer|exists:currencies,id',
            'category_id'          => 'required|integer|exists:categories,id',
            'discount'             => 'nullable|numeric|min:0',
            'discount_type'        => 'nullable|in:fixed,percent|required_with:discount',
            'description'          => 'nullable|string',
            'date'                 => 'nullable|date',
            'note'                 => 'nullable|string',
            'products'              => 'sometimes|array',
            'products.*.product_id' => 'required_with:products|integer|exists:products,id',
            'products.*.quantity'   => 'required_with:products|numeric|min:0',
            'products.*.price'      => 'required_with:products|numeric|min:0',
            'products.*.width'      => 'nullable|numeric|min:0',
            'products.*.height'     => 'nullable|numeric|min:0',
            'temp_products'         => 'sometimes|array',
            'temp_products.*.name' => 'required_with:temp_products|string|max:255',
            'temp_products.*.description' => 'nullable|string',
            'temp_products.*.quantity'    => 'required_with:temp_products|numeric|min:0',
            'temp_products.*.price'       => 'required_with:temp_products|numeric|min:0',
            'temp_products.*.unit_id'     => 'nullable|exists:units,id',
            'temp_products.*.width'      => 'nullable|numeric|min:0',
            'temp_products.*.height'     => 'nullable|numeric|min:0',
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

