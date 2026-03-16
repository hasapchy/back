<?php

namespace App\Http\Resources;

use App\Models\Currency;
use App\Models\Client;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $client = $this->resource;
        if (!$client instanceof Client) {
            return [];
        }
        $user = auth('api')->user();
        $balances = $client->balances ?? collect();
        if ($user && !$user->is_admin) {
            $balances = $balances->filter(fn ($b) => $b->canUserAccess($user->id));
        }
        $visibleDefault = $balances->firstWhere('is_default', true) ?? $balances->first();
        $balanceValue = $visibleDefault ? (float) $visibleDefault->balance : 0.0;

        return [
            'id' => $client->id,
            'client_type' => $client->client_type,
            'balance' => $balanceValue,
            'is_supplier' => (bool) $client->is_supplier,
            'is_conflict' => (bool) $client->is_conflict,
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'patronymic' => $client->patronymic,
            'position' => $client->position,
            'address' => $client->address,
            'note' => $client->note,
            'status' => (bool) $client->status,
            'discount_type' => $client->discount_type,
            'discount' => $client->discount,
            'created_at' => $client->created_at !== null ? $client->created_at->toIso8601String() : null,
            'updated_at' => $client->updated_at !== null ? $client->updated_at->toIso8601String() : null,
            'creator_id' => $client->creator_id,
            'user_name' => $client->creator?->name,
            'employee_id' => $client->employee_id,
            'employee' => $client->employee ? [
                'id' => $client->employee->id,
                'name' => $client->employee->name,
                'surname' => $client->employee->surname,
                'position' => $client->employee->position,
            ] : null,
            'emails' => $client->emails->map(fn ($email) => [
                'id' => $email->id,
                'email' => $email->email,
            ])->all(),
            'phones' => $client->phones->map(fn ($phone) => [
                'id' => $phone->id,
                'phone' => $phone->phone,
            ])->all(),
            'balances' => $balances->map(fn ($balance) => [
                'id' => $balance->id,
                'currency_id' => $balance->currency_id,
                'currency' => [
                    'id' => $balance->currency->id,
                    'code' => $balance->currency->code,
                    'symbol' => $balance->currency->symbol,
                    'name' => $balance->currency->name,
                ],
                'balance' => (float) $balance->balance,
                'is_default' => $balance->is_default,
                'note' => $balance->note,
                'users' => ($balance->users ?? collect())->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => trim(($u->name ?? '') . ' ' . ($u->surname ?? '')),
                ])->values()->all(),
            ])->values()->all(),
            'currency_symbol' => $visibleDefault?->currency?->symbol
                ?? Currency::where('is_default', true)->value('symbol'),
        ];
    }
}

