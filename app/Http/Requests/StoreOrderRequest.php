<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesOrderNullableTextFields;
use App\Http\Requests\Concerns\ResolvesSimpleOrderUser;
use App\Http\Requests\Concerns\SharedOrderEntityRules;
use App\Http\Requests\Concerns\ValidatesOrderClientBalance;
use App\Rules\WarehouseAccessRule;
use App\Models\CashRegister;
use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class StoreOrderRequest extends FormRequest
{
    use NormalizesOrderNullableTextFields;
    use ResolvesSimpleOrderUser;
    use SharedOrderEntityRules;
    use ValidatesOrderClientBalance;

    /**
     * Определить, авторизован ли пользователь для выполнения этого запроса
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Order::class);
    }

    /**
     * Получить правила валидации
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        $isSimpleUser = $this->isSimpleOrderUser();

        return array_merge($this->sharedOrderEntityRules($isSimpleUser, false), [
            'warehouse_id'         => ['required', 'integer', new WarehouseAccessRule()],
            'currency_id'          => [
                'nullable',
                'integer',
                'exists:currencies,id',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value || ! $this->input('cash_id')) {
                        return;
                    }
                    $cash = CashRegister::query()->find($this->input('cash_id'));
                    if ($cash && (int) $cash->currency_id !== (int) $value) {
                        $fail('Валюта запроса должна совпадать с валютой выбранной кассы.');
                    }
                },
            ],
            'category_id'          => $isSimpleUser
                ? 'nullable|integer|exists:categories,id'
                : 'required|integer|exists:categories,id',
            'discount'             => 'nullable|numeric|min:0',
            'discount_type'        => 'nullable|in:fixed,percent|required_with:discount',
            'description'          => 'nullable|string',
            'date'                 => 'nullable|date',
            'note'                 => 'nullable|string',
            'products'              => 'sometimes|array',
            'products.*.product_id' => 'required_with:products|integer|exists:products,id',
            'products.*.quantity'   => 'required_with:products|numeric|min:0.01',
            'products.*.price'      => 'required_with:products|numeric|min:0',
            'products.*.width'      => 'nullable|numeric|min:0',
            'products.*.height'     => 'nullable|numeric|min:0',
            'temp_products'         => 'sometimes|array',
            'temp_products.*.name' => 'required_with:temp_products|string|max:255',
            'temp_products.*.description' => 'nullable|string',
            'temp_products.*.quantity'    => 'required_with:temp_products|numeric|min:0.01',
            'temp_products.*.price'       => 'required_with:temp_products|numeric|min:0',
            'temp_products.*.unit_id'     => 'nullable|exists:units,id',
            'temp_products.*.width'      => 'nullable|numeric|min:0',
            'temp_products.*.height'     => 'nullable|numeric|min:0',
            'client_balance_id'          => $this->orderClientBalanceIdRules(),
        ]);
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

