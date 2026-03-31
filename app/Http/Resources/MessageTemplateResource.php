<?php

namespace App\Http\Resources;

use App\Models\MessageTemplate;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageTemplateResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $creator = data_get($this->resource, 'creator');

        if (is_array($this->resource)) {
            return [
                'id' => data_get($this->resource, 'id'),
                'type' => data_get($this->resource, 'type'),
                'name' => data_get($this->resource, 'name'),
                'content' => data_get($this->resource, 'content'),
                'company_id' => data_get($this->resource, 'company_id'),
                'company' => data_get($this->resource, 'company'),
                'creator_id' => data_get($this->resource, 'creator_id'),
                'creator' => $creator ? [
                    'id' => data_get($creator, 'id'),
                    'name' => data_get($creator, 'name'),
                    'surname' => data_get($creator, 'surname'),
                    'email' => data_get($creator, 'email'),
                    'photo' => data_get($creator, 'photo'),
                ] : null,
                'is_active' => data_get($this->resource, 'is_active'),
                'created_at' => data_get($this->resource, 'created_at'),
                'updated_at' => data_get($this->resource, 'updated_at'),
            ];
        }

        if ($this->resource instanceof MessageTemplate) {
            /** @var MessageTemplate $template */
            $template = $this->resource;

            return [
                'id' => $template->id,
                'type' => $template->type,
                'name' => $template->name,
                'content' => $template->content,
                'company_id' => $template->company_id,
                'company' => $this->whenLoaded('company'),
                'creator_id' => $template->creator_id,
                'creator' => $this->whenLoaded('creator'),
                'is_active' => $template->is_active,
                'created_at' => $template->created_at,
                'updated_at' => $template->updated_at,
            ];
        }

        return [
            'id' => data_get($this->resource, 'id'),
            'type' => data_get($this->resource, 'type'),
            'name' => data_get($this->resource, 'name'),
            'content' => data_get($this->resource, 'content'),
            'company_id' => data_get($this->resource, 'company_id'),
            'company' => data_get($this->resource, 'company'),
            'creator_id' => data_get($this->resource, 'creator_id'),
            'creator' => $creator ? [
                'id' => data_get($creator, 'id'),
                'name' => data_get($creator, 'name'),
                'surname' => data_get($creator, 'surname'),
                'email' => data_get($creator, 'email'),
                'photo' => data_get($creator, 'photo'),
            ] : null,
            'is_active' => data_get($this->resource, 'is_active'),
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
        ];
    }
}
