<?php

namespace App\Http\Resources;

use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskReferenceResource extends JsonResource
{
    /**
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        /** @var Task $task */
        $task = $this->resource;
        $priority = $task->priority;
        $complexity = $task->complexity;
        $status = $task->relationLoaded('status') ? $task->status : null;
        $creator = $task->relationLoaded('creator') ? $task->creator : null;
        $supervisor = $task->relationLoaded('supervisor') ? $task->supervisor : null;
        $executor = $task->relationLoaded('executor') ? $task->executor : null;
        $observers = $task->relationLoaded('observers') ? $task->observers : null;
        $project = $task->relationLoaded('project') ? $task->project : null;

        return [
            'checklist' => [],
            'comments' => [],
            'complexity' => $complexity?->value,
            'complexity_icons' => $complexity?->icons(),
            'complexity_label' => $complexity?->label(),
            'created_at' => $task->created_at?->toDateTimeString(),
            'creator' => $creator ? [
                'id' => $creator->id,
                'name' => $creator->name,
                'email' => $creator->email,
            ] : null,
            'deadline' => $task->deadline?->toDateTimeString(),
            'description' => $task->description,
            'executor' => $executor ? [
                'id' => $executor->id,
                'name' => $executor->name,
                'surname' => $executor->surname,
                'email' => $executor->email,
                'position' => $executor->position,
                'photo' => $executor->photo,
            ] : null,
            'files' => [],
            'id' => $task->id,
            'observers' => $observers ? $observers->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'surname' => $user->surname,
                'email' => $user->email,
                'position' => $user->position,
                'photo' => $user->photo,
            ])->values()->all() : [],
            'priority' => $priority?->value,
            'priority_icons' => $priority?->icons(),
            'priority_label' => $priority?->label(),
            'project' => $project ? [
                'id' => $project->id,
                'name' => $project->name,
            ] : null,
            'restrict_visibility' => (bool) $task->restrict_visibility,
            'status' => $status ? [
                'id' => $status->id,
                'name' => $status->name,
                'color' => $status->color,
            ] : null,
            'status_id' => $task->status_id,
            'supervisor' => $supervisor ? [
                'id' => $supervisor->id,
                'name' => $supervisor->name,
                'surname' => $supervisor->surname,
                'email' => $supervisor->email,
                'position' => $supervisor->position,
                'photo' => $supervisor->photo,
            ] : null,
            'title' => $task->title,
            'updated_at' => $task->updated_at?->toDateTimeString(),
        ];
    }
}
