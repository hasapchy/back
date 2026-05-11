<?php

namespace App\Rules;

use App\Models\CashRegister;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CashRegisterAccessRule implements ValidationRule
{
    public function __construct(
        protected $user = null
    ) {
        $this->user = $user ?? auth('api')->user();
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || $value === false) {
            return;
        }

        if (! $this->user) {
            $fail('Поле :attribute некорректно. Пользователь не авторизован.');

            return;
        }

        $id = (int) $value;
        if ($id <= 0) {
            return;
        }

        $cashRegister = CashRegister::query()->find($id);

        if (! $cashRegister) {
            $fail(__('warehouse_receipt.cash_register_not_found'));

            return;
        }

        if (! $this->user->can('view', $cashRegister)) {
            $fail(__('warehouse_receipt.cash_register_forbidden'));
        }
    }
}
