<?php

namespace App\Http\Requests;

use App\Models\Order;
use App\Rules\ClientAccessRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class StoreInvoiceRequest extends FormRequest
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
            'client_id' => ['required', 'integer', new ClientAccessRule()],
            'invoice_date' => 'nullable|date',
            'note' => 'nullable|string',
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:orders,id',
        ];
    }

    /**
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $orderIds = $this->input('order_ids', []);
            if (empty($orderIds)) {
                return;
            }
            $orders = Order::with(['orderProducts', 'tempProducts'])
                ->whereIn('id', $orderIds)
                ->get();
            foreach ($orders as $order) {
                $hasProducts = $order->orderProducts->where('quantity', '>', 0)->isNotEmpty()
                    || $order->tempProducts->where('quantity', '>', 0)->isNotEmpty();
                if (!$hasProducts) {
                    $validator->errors()->add(
                        'order_ids',
                        __('Заказ #:id не содержит товаров с количеством больше нуля.', ['id' => $order->id])
                    );
                    break;
                }
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
