<?php

namespace App\Http\Requests;

use App\Rules\CashRegisterAccessRule;
use App\Rules\ProjectAccessRule;
use App\Rules\ClientAccessRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class StoreTransactionRequest extends FormRequest
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
            'type' => 'required|integer|in:1,0',
            'orig_amount' => 'required|numeric|min:0.01',
            'currency_id' => 'required|exists:currencies,id',
            'cash_id' => ['required', 'integer', new CashRegisterAccessRule()],
            'category_id' => 'required|exists:transaction_categories,id',
            'project_id' => ['nullable', 'sometimes', new ProjectAccessRule()],
            'client_id' => ['nullable', 'sometimes', new ClientAccessRule()],
            'order_id' => 'nullable|integer|exists:orders,id',
            'source_type' => 'nullable|string',
            'source_id' => 'nullable|integer',
            'note' => 'nullable|sometimes|string',
            'date' => 'nullable|sometimes|date',
            'is_debt' => 'nullable|boolean',
            'is_adjustment' => 'nullable|boolean',
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

