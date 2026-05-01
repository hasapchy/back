<?php

namespace App\Http\Requests;

use App\Enums\WhReceiptStatus;
use App\Http\Requests\Concerns\ValidatesOrderClientBalance;
use App\Rules\CashRegisterAccessRule;
use App\Rules\ClientAccessRule;
use App\Rules\ProjectAccessRule;
use App\Rules\WarehouseAccessRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreWarehouseReceiptRequest extends FormRequest
{
    use ValidatesOrderClientBalance;

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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', new ClientAccessRule()],
            'warehouse_id' => ['required', 'integer', new WarehouseAccessRule()],
            'cash_id' => ['nullable', 'integer', new CashRegisterAccessRule()],
            'date' => 'nullable|date',
            'note' => 'nullable|string',
            'project_id' => ['nullable', 'integer', new ProjectAccessRule()],
            'products' => 'required|array',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:0',
            'products.*.price' => 'required|numeric|min:0',
            'client_balance_id' => $this->orderClientBalanceIdRules(),
            'is_legacy' => 'sometimes|boolean',
            'is_simple' => 'sometimes|boolean',
            'status' => ['sometimes', 'nullable', 'string', Rule::in(WhReceiptStatus::values())],
        ];
    }

    /**
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            if ($this->boolean('is_simple') && $this->boolean('is_legacy')) {
                $v->errors()->add('is_simple', __('warehouse_receipt.simple_incompatible_with_legacy'));
            }
        });
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
