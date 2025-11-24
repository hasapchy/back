<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ProjectResource extends BaseResource
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
            'name' => $this->name,
            'user_id' => $this->user_id,
            'client_id' => $this->client_id,
            'files' => $this->files,
            'budget' => $this->formatCurrency($this->budget),
            'currency_id' => $this->currency_id,
            'exchange_rate' => $this->formatCurrency($this->exchange_rate, 4),
            'date' => $this->formatDate($this->date),
            'description' => $this->description,
            'status_id' => $this->status_id,
            'company_id' => $this->company_id,
            'client' => new ClientResource($this->whenLoaded('client')),
            'creator' => new UserResource($this->whenLoaded('creator')),
            'currency' => new CurrencyResource($this->whenLoaded('currency')),
            'status' => new ProjectStatusResource($this->whenLoaded('status')),
            'users' => UserResource::collection($this->whenLoaded('users')),
            'created_at' => $this->formatDateTime($this->created_at),
            'updated_at' => $this->formatDateTime($this->updated_at),
        ];
    }
}

