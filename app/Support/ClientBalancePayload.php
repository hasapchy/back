<?php

namespace App\Support;

use App\Models\ClientBalance;
use Illuminate\Support\Collection;

class ClientBalancePayload
{
    /**
     * @return array<string, mixed>
     */
    public static function fromModel(ClientBalance $balance): array
    {
        $users = ($balance->users ?? collect())->map(fn ($u) => [
            'id' => $u->id,
            'name' => trim(($u->name ?? '').' '.($u->surname ?? '')),
        ])->values()->all();

        return [
            'id' => $balance->id,
            'currency_id' => $balance->currency_id,
            'type' => (int) $balance->type,
            'currency' => $balance->currency ? [
                'id' => $balance->currency->id,
                'code' => $balance->currency->code,
                'name' => $balance->currency->name,
            ] : null,
            'balance' => (float) $balance->balance,
            'is_default' => $balance->is_default,
            'note' => $balance->note,
            'users' => $users,
        ];
    }

    /**
     * @param  Collection<int, ClientBalance>  $balances
     * @return array<int, array<string, mixed>>
     */
    public static function collection(Collection $balances): array
    {
        return $balances->map(fn (ClientBalance $balance) => self::fromModel($balance))->values()->all();
    }
}
