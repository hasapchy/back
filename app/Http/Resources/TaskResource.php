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
        // Получаем базовый URL frontend из конфигурации или env
        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'deadline' => $this->deadline?->toDateTimeString(),
            'files' => $this->files,
            'comments' => $this->comments,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),

            // Связи
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
                    'email' => $this->supervisor->email,
                ];
            }),
            'executor' => $this->whenLoaded('executor', function () {
                return [
                    'id' => $this->executor->id,
                    'name' => $this->executor->name,
                    'email' => $this->executor->email,
                ];
            }),
            'project' => $this->whenLoaded('project', function () {
                return [
                    'id' => $this->project->id,
                    'name' => $this->project->name,
                ];
            }),

            // Ссылка на frontend для отображения задачи
            'frontend_url' => $frontendUrl . '/tasks/' . $this->id,
            'frontend_link' => '/tasks/' . $this->id, // Относительная ссылка
        ];
    }
}
