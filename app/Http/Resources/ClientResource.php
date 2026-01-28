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
        $defaultBalance = $this->defaultBalance;
        $balances = $this->balances ?? collect();

        return [
            'id' => $this->id,
            'client_type' => $this->client_type,
            'balance' => $defaultBalance ? (float) $defaultBalance->balance : (float) ($this->balance ?? 0),
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
            'user_id' => $this->user_id,
            'user_name' => $this->user?->name,
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
            ])->all(),
            'currency_symbol' => $defaultBalance?->currency->symbol 
                ?? Currency::where('is_default', true)->value('symbol'),
        ];
    }
}

