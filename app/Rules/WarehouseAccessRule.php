<?php

namespace App\Rules;

use App\Models\Warehouse;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class WarehouseAccessRule implements ValidationRule
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

        $warehouse = Warehouse::find($value);

        if (! $warehouse) {
            return;
        }

        if (! $this->user->can('view', $warehouse)) {
            $fail('У вас нет доступа к этому складу.');
        }
    }
}
