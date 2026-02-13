<?php

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    /** Добавить url для каждого файла (tenant), чтобы картинки в чате открывались. */
    private function mapFilesWithUrl(?array $files): ?array
    {
        if (!is_array($files) || empty($files)) {
            return $files;
        }
        $companyId = request()->header('X-Company-ID');
        $base = request()->getSchemeAndHttpHost() . '/storage/tenant/';
        return array_map(function ($file) use ($companyId, $base) {
            $file = is_array($file) ? $file : (array) $file;
            if (!empty($companyId) && !empty($file['path'])) {
                $file['url'] = $base . $companyId . '/' . ltrim($file['path'], '/');
            }
            // Гарантировать name для отображения: в БД хранится оригинальное имя, fallback — из path
            if (empty($file['name']) && !empty($file['path'])) {
                $file['name'] = basename($file['path']);
            }
            return $file;
        }, $files);
    }

    /**
     * Keep compatible with legacy "messages" endpoint (it returned full model arrays).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $parent = null;
        if ($this->parent_id && $this->relationLoaded('parent')) {
            $parentMessage = $this->parent;
            $parent = [
                'id' => (int) $parentMessage->id,
                'body' => $parentMessage->body,
                'files' => $this->mapFilesWithUrl($parentMessage->files),
                'user' => $this->when(
                    $parentMessage->relationLoaded('user'),
                    fn () => [
                        'id' => (int) $parentMessage->user->id,
                        'name' => $parentMessage->user->name,
                        'surname' => $parentMessage->user->surname ?? null,
                        'photo' => $parentMessage->user->photo ?? null,
                    ]
                ),
            ];
        }

        $forwardedFrom = null;
        if ($this->forwarded_from_message_id && $this->relationLoaded('forwardedFrom')) {
            $forwardedMessage = $this->forwardedFrom;
            $forwardedFrom = [
                'id' => (int) $forwardedMessage->id,
                'body' => $forwardedMessage->body,
                'files' => $this->mapFilesWithUrl($forwardedMessage->files),
                'user' => $this->when(
                    $forwardedMessage->relationLoaded('user'),
                    fn () => [
                        'id' => (int) $forwardedMessage->user->id,
                        'name' => $forwardedMessage->user->name,
                        'surname' => $forwardedMessage->user->surname ?? null,
                        'photo' => $forwardedMessage->user->photo ?? null,
                    ]
                ),
                'created_at' => $forwardedMessage->created_at?->toDateTimeString(),
            ];
        }

        return [
            'id' => (int) $this->id,
            'chat_id' => (int) $this->chat_id,
            'user_id' => (int) $this->user_id,
            'body' => $this->body,
            'files' => $this->mapFilesWithUrl($this->files),
            'parent_id' => $this->parent_id,
            'parent' => $parent,
            'forwarded_from_message_id' => $this->forwarded_from_message_id,
            'forwarded_from' => $forwardedFrom,
            'user' => $this->when(
                $this->relationLoaded('user'),
                fn () => [
                    'id' => (int) $this->user->id,
                    'name' => $this->user->name,
                    'surname' => $this->user->surname ?? null,
                    'photo' => $this->user->photo ?? null,
                ]
            ),
            'is_edited' => (bool) ($this->is_edited ?? false),
            'edited_at' => $this->edited_at?->toDateTimeString(),
            'is_system' => (bool) ($this->is_system ?? false),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'deleted_at' => $this->deleted_at?->toDateTimeString(),
            'reactions' => $this->when(
                $this->relationLoaded('reactions'),
                fn () => $this->reactions->map(fn ($r) => [
                    'emoji' => $r->emoji,
                    'user_id' => (int) $r->user_id,
                    'user' => $r->relationLoaded('user') ? [
                        'id' => (int) $r->user->id,
                        'name' => $r->user->name,
                        'surname' => $r->user->surname ?? null,
                    ] : null,
                ])->values()->all()
            ),
        ];
    }
}


