<?php

namespace App\Http\Requests;

use App\Rules\ClientAccessRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class UpdateInvoiceRequest extends FormRequest
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
            'client_id' => ['required', 'integer', new ClientAccessRule()],
            'invoice_date' => 'nullable|date',
            'note' => 'nullable|string',
            'status' => 'nullable|string|in:new,in_progress,paid,cancelled',
            'order_ids' => 'nullable|array',
            'order_ids.*' => 'integer|exists:orders,id',
            'products' => 'nullable|array',
            'products.*.product_name' => 'required_with:products|string|max:255',
            'products.*.quantity' => 'required_with:products|numeric|min:0.01',
            'products.*.price' => 'required_with:products|numeric|min:0',
            'products.*.total_price' => 'required_with:products|numeric|min:0',
            'total_amount' => 'nullable|numeric|min:0',
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
