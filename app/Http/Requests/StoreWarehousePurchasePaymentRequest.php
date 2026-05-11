<?php

namespace App\Http\Requests;

use App\Rules\CashRegisterAccessRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreWarehousePurchasePaymentRequest extends FormRequest
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
            'cash_id' => ['required', 'integer', new CashRegisterAccessRule()],
            'amount' => 'required|numeric|gt:0',
            'currency_id' => 'nullable|integer|exists:currencies,id',
            'date' => 'nullable|date',
            'note' => 'nullable|string',
        ];
    }

    /**
     * @param  Validator  $validator
     * @return void
     */
    protected function failedValidation(Validator $validator)
    {
        throw (new ValidationException($validator))
            ->errorBag($this->errorBag)
            ->redirectTo($this->getRedirectUrl());
    }
}
