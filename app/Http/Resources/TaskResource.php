<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $task = $this->resource;
        if (!$task instanceof Task) {
            return [];
        }
        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'status_id' => $task->status_id,
            'priority' => $task->priority !== null ? $task->priority->value : null,
            'priority_label' => $task->priority !== null ? $task->priority->label() : null,
            'priority_icons' => $task->priority !== null ? $task->priority->icons() : null,
            'complexity' => $task->complexity !== null ? $task->complexity->value : null,
            'complexity_label' => $task->complexity !== null ? $task->complexity->label() : null,
            'complexity_icons' => $task->complexity !== null ? $task->complexity->icons() : null,
            'deadline' => $task->deadline?->toDateTimeString(),
            'files' => $task->files,
            'comments' => $task->comments,
            'checklist' => $task->checklist,
            'created_at' => $task->created_at?->toDateTimeString(),
            'updated_at' => $task->updated_at?->toDateTimeString(),
            'status' => $this->whenLoaded('status', function () use ($task) {
                $status = $task->getRelation('status');
                return [
                    'id' => $status->id,
                    'name' => $status->name,
                    'color' => $status->color,
                ];
            }),
            'creator' => $this->whenLoaded('creator', function () use ($task) {
                $creator = $task->getRelation('creator');
                return [
                    'id' => $creator->id,
                    'name' => $creator->name,
                    'email' => $creator->email,
                ];
            }),
            'supervisor' => $this->whenLoaded('supervisor', function () use ($task) {
                $supervisor = $task->getRelation('supervisor');
                return [
                    'id' => $supervisor->id,
                    'name' => $supervisor->name,
                    'surname' => $supervisor->surname,
                    'email' => $supervisor->email,
                    'position' => $supervisor->position,
                    'photo' => $supervisor->photo,
                ];
            }),
            'executor' => $this->whenLoaded('executor', function () use ($task) {
                $executor = $task->getRelation('executor');
                return [
                    'id' => $executor->id,
                    'name' => $executor->name,
                    'surname' => $executor->surname,
                    'email' => $executor->email,
                    'position' => $executor->position,
                    'photo' => $executor->photo,
                ];
            }),
            'project' => $this->whenLoaded('project', function () use ($task) {
                $project = $task->getRelation('project');
                return [
                    'id' => $project->id,
                    'name' => $project->name,
                ];
            }),
        ];
    }
}
