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
        if (! $value) {
            return;
        }

        if (! $this->user) {
            $fail('Поле :attribute некорректно. Пользователь не авторизован.');

            return;
        }

        $cashRegister = CashRegister::find($value);

        if (! $cashRegister) {
            return;
        }

        if (! $this->user->can('view', $cashRegister)) {
            $fail('У вас нет доступа к этой кассе.');
        }
    }
}
