<?php

namespace App\Http\Requests;

use App\Enums\WhPurchaseStatus;
use App\Rules\ClientAccessRule;
use App\Rules\WarehouseAccessRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateWarehousePurchaseRequest extends FormRequest
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
            'supplier_id' => ['sometimes', 'integer', new ClientAccessRule()],
            'warehouse_id' => ['sometimes', 'integer', new WarehouseAccessRule()],
            'client_balance_id' => 'nullable|integer|exists:client_balances,id',
            'date' => 'nullable|date',
            'note' => 'nullable|string',
            'status' => ['sometimes', 'nullable', 'string', Rule::in(WhPurchaseStatus::values())],
            'products' => 'sometimes|array|min:1',
            'products.*.product_id' => 'required_with:products|integer|exists:products,id',
            'products.*.quantity' => 'required_with:products|numeric|gt:0',
            'products.*.price' => 'required_with:products|numeric|min:0',
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
