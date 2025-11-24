<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class TransferResource extends BaseResource
{
    /**
     * Преобразовать ресурс в массив
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'cash_id_from' => $this->cash_id_from,
            'cash_id_to' => $this->cash_id_to,
            'tr_id_from' => $this->tr_id_from,
            'tr_id_to' => $this->tr_id_to,
            'user_id' => $this->user_id,
            'amount' => $this->formatCurrency($this->amount),
            'note' => $this->note,
            'date' => $this->formatDate($this->date),
            'fromCashRegister' => new CashRegisterResource($this->whenLoaded('fromCashRegister')),
            'toCashRegister' => new CashRegisterResource($this->whenLoaded('toCashRegister')),
            'fromTransaction' => new TransactionResource($this->whenLoaded('fromTransaction')),
            'toTransaction' => new TransactionResource($this->whenLoaded('toTransaction')),
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->formatDateTime($this->created_at),
            'updated_at' => $this->formatDateTime($this->updated_at),
        ];
    }
}

