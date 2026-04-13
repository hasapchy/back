<?php

namespace App\Http\Requests\Concerns;

use App\Models\CashRegister;
use App\Models\ClientBalance;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesTransactionClientBalanceConsistency
{
    /**
     * Проверка: при переданном client_balance_id валюта и касса совпадают с балансом, баланс принадлежит клиенту.
     *
     * @param  Validator  $validator
     * @param  mixed  $clientBalanceId
     * @param  mixed  $clientId
     * @param  mixed  $currencyId
     * @param  mixed  $cashId
     * @return void
     */
    protected function assertTransactionPayloadMatchesClientBalance(
        Validator $validator,
        mixed $clientBalanceId,
        mixed $clientId,
        mixed $currencyId,
        mixed $cashId,
    ): void {
        if ($clientBalanceId === null || $clientBalanceId === '') {
            return;
        }

        $balance = ClientBalance::query()->find((int) $clientBalanceId);
        if (! $balance) {
            return;
        }

        if ($clientId !== null && $clientId !== '' && (int) $balance->client_id !== (int) $clientId) {
            $validator->errors()->add(
                'client_balance_id',
                __('Выбранный баланс не принадлежит этому клиенту.')
            );
        }

        if ($currencyId !== null && $currencyId !== '' && (int) $currencyId !== (int) $balance->currency_id) {
            $validator->errors()->add(
                'currency_id',
                __('Валюта транзакции должна совпадать с валютой выбранного баланса клиента.')
            );
        }

        if ($cashId === null || $cashId === '') {
            return;
        }

        $cash = CashRegister::query()->find((int) $cashId);
        if (! $cash) {
            return;
        }

        $balanceIsCash = (int) $balance->type === 1;
        if ((bool) $cash->is_cash !== $balanceIsCash) {
            $validator->errors()->add(
                'cash_id',
                __('Тип кассы (наличная / безналичная) должен совпадать с типом выбранного баланса.')
            );
        }

        if ((int) $cash->currency_id !== (int) $balance->currency_id) {
            $validator->errors()->add(
                'cash_id',
                __('Валюта кассы должна совпадать с валютой выбранного баланса клиента.')
            );
        }
    }
}
