<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class TransactionResource extends BaseResource
{
    /**
     * Преобразовать ресурс в массив
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $resource = $this->resource;
        $isModel = $resource instanceof \Illuminate\Database\Eloquent\Model;
        $clientData = $this->getResourceValue('client');

        return [
            'id' => $this->getResourceValue('id'),
            'amount' => $this->formatCurrency($this->getResourceValue('amount')),
            'orig_amount' => $this->formatCurrency($this->getResourceValue('orig_amount')),
            'cash_id' => $this->getResourceValue('cash_id'),
            'category_id' => $this->getResourceValue('category_id'),
            'client_id' => $this->getResourceValue('client_id'),
            'currency_id' => $this->getResourceValue('currency_id'),
            'project_id' => $this->getResourceValue('project_id'),
            'type' => $this->getResourceValue('type'),
            'user_id' => $this->getResourceValue('user_id'),
            'is_debt' => $this->toBoolean($this->getResourceValue('is_debt', false)),
            'is_deleted' => $this->toBoolean($this->getResourceValue('is_deleted', false)),
            'source_type' => $this->getResourceValue('source_type'),
            'source_id' => $this->getResourceValue('source_id'),
            'date' => $this->formatDate($this->getResourceValue('date')),
            'note' => $this->getResourceValue('note'),
            'cashRegister' => $isModel && $this->whenLoaded('cashRegister')
                ? new CashRegisterResource($this->whenLoaded('cashRegister'))
                : null,
            'category' => $isModel && $this->whenLoaded('category')
                ? new TransactionCategoryResource($this->whenLoaded('category'))
                : null,
            'client' => $isModel && $this->whenLoaded('client')
                ? new ClientResource($this->whenLoaded('client'))
                : ($clientData ?: null),
            'currency' => $isModel && $this->whenLoaded('currency')
                ? new CurrencyResource($this->whenLoaded('currency'))
                : null,
            'user' => $isModel && $this->whenLoaded('user')
                ? new UserResource($this->whenLoaded('user'))
                : null,
            'project' => $isModel && $this->whenLoaded('project')
                ? new ProjectResource($this->whenLoaded('project'))
                : null,
            'created_at' => $this->formatDateTime($this->getResourceValue('created_at')),
            'updated_at' => $this->formatDateTime($this->getResourceValue('updated_at')),
        ];
    }
}

