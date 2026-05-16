<?php

namespace App\Http\Requests;

use App\Enums\WhReceiptStatus;
use App\Http\Requests\Concerns\ValidatesOrderClientBalance;
use App\Http\Requests\Concerns\ValidatesWarehouseProductLinesOrig;
use App\Rules\CashRegisterAccessRule;
use App\Rules\ClientAccessRule;
use App\Rules\WarehouseAccessRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreWarehouseReceiptRequest extends FormRequest
{
    use ValidatesOrderClientBalance;
    use ValidatesWarehouseProductLinesOrig;

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
        return array_merge([
            'client_id' => ['required', 'integer', new ClientAccessRule()],
            'warehouse_id' => ['required', 'integer', new WarehouseAccessRule()],
            'purchase_id' => ['nullable', 'integer', 'exists:wh_purchases,id'],
            'cash_id' => ['nullable', 'integer', new CashRegisterAccessRule()],
            'date' => 'nullable|date',
            'note' => 'nullable|string',
            'products' => 'required|array',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:0',
            'products.*.price' => 'required|numeric|min:0',
            'client_balance_id' => $this->orderClientBalanceIdRules(),
            'status' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in([WhReceiptStatus::Draft->value]),
            ],
        ], $this->warehouseProductLinesOrigRules());
    }

    /**
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $this->addWarehouseProductLinesOrigPairValidator($validator);
        $this->addWarehouseProductLinesOrigConsistencyValidator($validator);
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            if (! $this->filled('purchase_id') && ! $this->filled('cash_id')) {
                $v->errors()->add('cash_id', 'Для оприходования без закупки нужно выбрать кассу');
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
