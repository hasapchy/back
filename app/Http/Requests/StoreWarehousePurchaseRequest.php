<?php

namespace App\Http\Requests;

use App\Enums\WhPurchaseStatus;
use App\Rules\ClientAccessRule;
use App\Rules\WarehouseAccessRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreWarehousePurchaseRequest extends FormRequest
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
            'supplier_id' => ['required', 'integer', new ClientAccessRule()],
            'warehouse_id' => ['required', 'integer', new WarehouseAccessRule()],
            'client_balance_id' => 'nullable|integer|exists:client_balances,id',
            'date' => 'nullable|date',
            'note' => 'nullable|string',
            'status' => ['sometimes', 'nullable', 'string', Rule::in(WhPurchaseStatus::values())],
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity' => 'required|numeric|gt:0',
            'products.*.price' => 'required|numeric|min:0',
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
