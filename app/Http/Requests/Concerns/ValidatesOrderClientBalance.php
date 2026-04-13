<?php

namespace App\Http\Requests\Concerns;

use App\Models\CashRegister;
use Illuminate\Validation\Rule;

trait ValidatesOrderClientBalance
{
    /**
     * Правила для client_balance_id: клиент и тип (нал / безнал) как у выбранной кассы.
     *
     * @return array<int, mixed>
     */
    protected function orderClientBalanceIdRules(): array
    {
        $clientId = $this->input('client_id');
        $cashId = $this->input('cash_id');
        $cashRegister = $cashId ? CashRegister::query()->find($cashId) : null;
        $balanceTypeFilter = $cashRegister ? ($cashRegister->is_cash ? 1 : 0) : null;

        return [
            'nullable',
            'integer',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value && ! $this->input('cash_id')) {
                    $fail('Укажите кассу при выборе баланса клиента.');
                }
            },
            Rule::exists('client_balances', 'id')->where(function ($q) use ($clientId, $balanceTypeFilter) {
                if ($clientId) {
                    $q->where('client_id', $clientId);
                } else {
                    $q->whereRaw('1 = 0');
                }
                if ($balanceTypeFilter !== null) {
                    $q->where('type', $balanceTypeFilter);
                }
            }),
        ];
    }
}
