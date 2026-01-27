<?php

namespace App\Rules;

use App\Models\CashRegister;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CashRegisterTypeMatchRule implements ValidationRule
{
    protected $contractType;

    /**
     * @param int|null $contractType Тип контракта (0 - безналичный, 1 - наличный)
     */
    public function __construct($contractType = null)
    {
        $this->contractType = $contractType;
    }

    /**
     * @param string $attribute
     * @param mixed $value
     * @param \Closure $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$value) {
            return;
        }

        if ($this->contractType === null) {
            $this->contractType = request()->input('type');
        }

        if ($this->contractType === null) {
            return;
        }

        $cashRegister = CashRegister::find($value);

        if (!$cashRegister) {
            return;
        }

        $contractTypeIsCash = (int)$this->contractType === 1;
        $cashRegisterIsCash = (bool)$cashRegister->is_cash;

        if ($contractTypeIsCash !== $cashRegisterIsCash) {
            if ($contractTypeIsCash) {
                $fail('Для наличного контракта необходимо выбрать наличную кассу.');
            } else {
                $fail('Для безналичного контракта необходимо выбрать безналичную кассу.');
            }
        }
    }
}
