<?php

namespace App\Http\Resources;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        if (! $this->resource instanceof Lead) {
            return parent::toArray($request);
        }

        /** @var Lead $lead */
        $lead = $this->resource;

        return [
            'id' => $lead->id,
            'company_id' => $lead->company_id,
            'creator_id' => $lead->creator_id,
            'responsible_id' => $lead->responsible_id,
            'client_id' => $lead->client_id,
            'lead_source_id' => $lead->lead_source_id,
            'status_id' => $lead->status_id,
            'comment' => $lead->comment,
            'order_id' => $lead->order_id,
            'created_at' => $lead->created_at,
            'updated_at' => $lead->updated_at,
            'client' => $lead->client ? [
                'id' => $lead->client->id,
                'first_name' => $lead->client->first_name,
                'last_name' => $lead->client->last_name,
            ] : null,
            'status' => $lead->status ? [
                'id' => $lead->status->id,
                'name' => $lead->status->name,
                'color' => $lead->status->color,
                'sort' => $lead->status->sort,
                'is_active' => $lead->status->is_active,
                'kanban_outcome' => $lead->status->kanban_outcome,
            ] : null,
            'source' => $lead->source ? [
                'id' => $lead->source->id,
                'name' => $lead->source->name,
            ] : null,
            'responsible' => $lead->responsible ? [
                'id' => $lead->responsible->id,
                'name' => $lead->responsible->name,
                'surname' => $lead->responsible->surname,
                'photo' => $lead->responsible->photo,
            ] : null,
        ];
    }
}
