<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'entry_number' => $this->entry_number,
            'entry_date' => $this->entry_date?->toDateString(),
            'description' => $this->description,
            'status' => $this->status->value,
            'template_key' => $this->template_key,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'meta' => $this->meta,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'lines' => JournalEntryLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
