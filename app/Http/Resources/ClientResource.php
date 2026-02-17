<?php

namespace App\Http\Resources;

use App\Models\Currency;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $user = auth('api')->user();
        $balances = $this->balances ?? collect();
        if ($user && !$user->is_admin) {
            $balances = $balances->filter(fn ($b) => $b->canUserAccess($user->id));
        }
        $visibleDefault = $balances->firstWhere('is_default', true) ?? $balances->first();
        $balanceValue = $visibleDefault ? (float) $visibleDefault->balance : 0.0;

        return [
            'id' => $this->id,
            'client_type' => $this->client_type,
            'balance' => $balanceValue,
            'is_supplier' => (bool)$this->is_supplier,
            'is_conflict' => (bool)$this->is_conflict,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'patronymic' => $this->patronymic,
            'contact_person' => $this->contact_person,
            'position' => $this->position,
            'address' => $this->address,
            'note' => $this->note,
            'status' => (bool)$this->status,
            'discount_type' => $this->discount_type,
            'discount' => $this->discount,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'creator_id' => $this->creator_id,
            'user_name' => $this->creator?->name,
            'employee_id' => $this->employee_id,
            'employee' => $this->employee ? [
                'id' => $this->employee->id,
                'name' => $this->employee->name,
                'surname' => $this->employee->surname,
                'position' => $this->employee->position,
            ] : null,
            'emails' => $this->emails->map(fn($email) => [
                'id' => $email->id,
                'email' => $email->email,
            ])->all(),
            'phones' => $this->phones->map(fn($phone) => [
                'id' => $phone->id,
                'phone' => $phone->phone,
            ])->all(),
            'balances' => $balances->map(fn($balance) => [
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

