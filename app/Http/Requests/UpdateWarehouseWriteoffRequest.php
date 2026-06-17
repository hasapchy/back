<?php

namespace App\Http\Requests;

use App\Enums\WhWriteoffReason;
use App\Http\Requests\Concerns\ValidatesWarehouseProductLinesOrig;
use App\Models\WhWriteoff;
use App\Rules\WarehouseAccessRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class UpdateWarehouseWriteoffRequest extends FormRequest
{
    use ValidatesWarehouseProductLinesOrig;

    /**
     * Определить, авторизован ли пользователь для выполнения этого запроса
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $writeoff = WhWriteoff::query()->find($this->route('id'));

        if (! $writeoff) {
            return true;
        }

        return $this->user()->can('update', $writeoff);
    }

    /**
     * Получить правила валидации
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return array_merge([
            'warehouse_id' => ['required', 'integer', new WarehouseAccessRule()],
            'reason' => ['required', 'string', Rule::in(WhWriteoffReason::values())],
            'source_receipt_id' => ['nullable', 'integer', 'exists:wh_receipts,id', 'required_if:reason,'.WhWriteoffReason::ReturnSupplier->value],
            'note' => 'nullable|string',
            'date' => 'nullable|date',
            'products' => 'required|array',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:0',
            'products.*.source_receipt_product_id' => 'nullable|integer|exists:wh_receipt_products,id',
        ], $this->warehouseProductLinesOrigRules());
    }

    /**
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $this->addWarehouseProductLinesOrigPairValidator($validator);
        $this->addWarehouseProductLinesOrigConsistencyValidator($validator);
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
