<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesOrderNullableTextFields;
use App\Http\Requests\Concerns\ValidatesOrderClientBalance;
use App\Rules\CashRegisterAccessRule;
use App\Rules\WarehouseAccessRule;
use App\Rules\ProjectAccessRule;
use App\Rules\ClientAccessRule;
use App\Models\CashRegister;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class UpdateOrderRequest extends FormRequest
{
    use NormalizesOrderNullableTextFields;
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
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        $user = auth('api')->user();
        $isSimpleWorker = $user instanceof User && $user->hasRole(config('simple.worker_role'));

        return [
            'client_id'            => $isSimpleWorker
                ? ['required', 'integer', 'exists:clients,id']
                : ['required', 'integer', new ClientAccessRule()],
            'project_id'           => $isSimpleWorker
                ? ['nullable', 'sometimes', 'integer', 'exists:projects,id']
                : ['nullable', 'sometimes', 'integer', new ProjectAccessRule()],
            'cash_id'              => $isSimpleWorker
                ? ['nullable', 'integer', 'exists:cash_registers,id']
                : ['required', 'integer', new CashRegisterAccessRule()],
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
            'category_id'          => 'nullable|integer|exists:categories,id',
            'discount'             => 'nullable|numeric|min:0',
            'discount_type'        => 'nullable|in:fixed,percent|required_with:discount',
            'date'                 => 'nullable|date',
            'note'                 => 'nullable|string',
            'description'          => 'nullable|string',
            'status_id'            => 'nullable|integer|exists:order_statuses,id',
            'products'             => 'nullable|array',
            'products.*.id'        => 'nullable|integer|exists:order_products,id',
            'products.*.product_id' => 'required_with:products|integer|exists:products,id',
            'products.*.quantity'  => 'required_with:products|numeric|min:0.01',
            'products.*.price'     => 'required_with:products|numeric|min:0',
            'products.*.width'      => 'nullable|numeric|min:0',
            'products.*.height'     => 'nullable|numeric|min:0',
            'temp_products'         => 'nullable|array',
            'temp_products.*.id'    => 'nullable|integer|exists:order_temp_products,id',
            'temp_products.*.name'  => 'required_with:temp_products|string|max:255',
            'temp_products.*.description' => 'nullable|string',
            'temp_products.*.quantity'    => 'required_with:temp_products|numeric|min:0.01',
            'temp_products.*.price'       => 'required_with:temp_products|numeric|min:0',
            'temp_products.*.unit_id'     => 'nullable|exists:units,id',
            'temp_products.*.width'       => 'nullable|numeric|min:0',
            'temp_products.*.height'      => 'nullable|numeric|min:0',
            'remove_temp_products'  => 'nullable|array',
            'remove_temp_products.*' => 'integer|exists:order_temp_products,id',
            'client_balance_id'      => $this->orderClientBalanceIdRules(),
        ];
    }

    /**
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $orderId = $this->route('id');
            if (! $orderId || ! $this->exists('cash_id')) {
                return;
            }
            $order = Order::query()->find($orderId);
            if (! $order) {
                return;
            }
            $incoming = $this->input('cash_id');
            $newId = $incoming === null || $incoming === '' ? null : (int) $incoming;
            $oldId = $order->cash_id === null ? null : (int) $order->cash_id;
            if ($newId !== $oldId) {
                $validator->errors()->add('cash_id', 'Нельзя изменить кассу у сохранённого заказа.');
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

