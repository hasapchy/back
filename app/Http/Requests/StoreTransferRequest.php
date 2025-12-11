<?php

namespace App\Http\Requests;

use App\Rules\CashRegisterAccessRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class StoreTransferRequest extends FormRequest
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
            'cash_id_from' => ['required', 'integer', new CashRegisterAccessRule()],
            'cash_id_to' => ['required', 'integer', new CashRegisterAccessRule()],
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|sometimes|string',
            'exchange_rate' => 'nullable|sometimes|numeric|min:0.000001',
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
