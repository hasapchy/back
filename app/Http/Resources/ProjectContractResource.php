<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ProjectContractResource extends BaseResource
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
            'project_id' => $this->project_id,
            'number' => $this->number,
            'amount' => $this->formatCurrency($this->amount),
            'currency_id' => $this->currency_id,
            'date' => $this->formatDate($this->date),
            'returned' => $this->toBoolean($this->returned),
            'files' => $this->files,
            'note' => $this->note,
            'project' => new ProjectResource($this->whenLoaded('project')),
            'currency' => new CurrencyResource($this->whenLoaded('currency')),
            'created_at' => $this->formatDateTime($this->created_at),
            'updated_at' => $this->formatDateTime($this->updated_at),
        ];
    }
}

