<?php

namespace App\Rules;

use App\Models\Client;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ClientAccessRule implements ValidationRule
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

        $client = Client::find($value);

        if (! $client) {
            return;
        }

        if (! $this->user->can('view', $client)) {
            $fail('У вас нет доступа к этому клиенту.');
        }
    }
}
