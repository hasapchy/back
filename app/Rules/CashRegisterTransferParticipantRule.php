<?php

namespace App\Rules;

use App\Models\CashRegister;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CashRegisterTransferParticipantRule implements ValidationRule
{
    /**
     * @param string $attribute
     * @param mixed $value
     * @param Closure $fail
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || $value === false) {
            return;
        }

        $id = (int) $value;
        if ($id <= 0) {
            return;
        }

        $cashRegister = CashRegister::query()->find($id);

        if (! $cashRegister) {
            return;
        }

        if (! $cashRegister->participates_in_transfers) {
            $fail(__('cash_registers.not_participates_in_transfers'));
        }
    }
}
