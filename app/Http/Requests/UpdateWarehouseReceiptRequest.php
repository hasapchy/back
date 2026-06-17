<?php

namespace App\Http\Requests;

use App\Enums\WhReceiptStatus;
use App\Http\Requests\Concerns\ValidatesWarehouseProductLinesOrig;
use App\Models\WhReceipt;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateWarehouseReceiptRequest extends FormRequest
{
    use ValidatesWarehouseProductLinesOrig;

    /**
     * Определить, авторизован ли пользователь для выполнения этого запроса
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $receipt = WhReceipt::query()->find($this->route('id'));

        if (! $receipt) {
            return true;
        }

        return $this->user()->can('update', $receipt);
    }

    /**
     * Получить правила валидации
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'date' => 'nullable|date',
            'note' => 'nullable|string',
            'status' => ['sometimes', 'nullable', 'string', Rule::in(WhReceiptStatus::values())],
            'products' => 'sometimes|array|min:1',
            'products.*.product_id' => 'required_with:products|integer|exists:products,id',
            'products.*.quantity' => 'required_with:products|numeric|min:0',
            'products.*.price' => 'required_with:products|numeric|min:0',
        ], $this->warehouseProductLinesOrigRules());
    }

    /**
     * @param  Validator  $validator
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
