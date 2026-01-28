<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status_id' => $this->status_id,
            'priority' => $this->priority?->value,
            'priority_label' => $this->priority?->label(),
            'priority_icons' => $this->priority?->icons(),
            'complexity' => $this->complexity?->value,
            'complexity_label' => $this->complexity?->label(),
            'complexity_icons' => $this->complexity?->icons(),
            'deadline' => $this->deadline?->toDateTimeString(),
            'files' => $this->files,
            'comments' => $this->comments,
            'checklist' => $this->checklist,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),

            // Связи
            'status' => $this->whenLoaded('status', function () {
                return [
                    'id' => $this->status->id,
                    'name' => $this->status->name,
                    'color' => $this->status->color,
                ];
            }),
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                    'email' => $this->creator->email,
                ];
            }),
            'supervisor' => $this->whenLoaded('supervisor', function () {
                return [
                    'id' => $this->supervisor->id,
                    'name' => $this->supervisor->name,
                    'surname' => $this->supervisor->surname,
                    'email' => $this->supervisor->email,
                    'position' => $this->supervisor->position,
                    'photo' => $this->supervisor->photo,
                ];
            }),
            'executor' => $this->whenLoaded('executor', function () {
                return [
                    'id' => $this->executor->id,
                    'name' => $this->executor->name,
                    'surname' => $this->executor->surname,
                    'email' => $this->executor->email,
                    'position' => $this->executor->position,
                    'photo' => $this->executor->photo,
                ];
            }),
            'project' => $this->whenLoaded('project', function () {
                return [
                    'id' => $this->project->id,
                    'name' => $this->project->name,
                ];
            }),
        ];
    }
}
