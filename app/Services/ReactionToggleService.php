<?php

namespace App\Services;

use App\Services\Timeline\TimelineUserFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ReactionToggleService
{
    /**
     * @param class-string<Model> $reactionModelClass
     * @param string $foreignKey
     * @param int $foreignId
     * @param int $userId
     * @param string|null $emoji
     * @return void
     */
    public function toggle(string $reactionModelClass, string $foreignKey, int $foreignId, int $userId, ?string $emoji): void
    {
        /** @var Model $model */
        $model = new $reactionModelClass;

        if ($emoji === null || $emoji === '') {
            $model::query()
                ->where($foreignKey, $foreignId)
                ->where('user_id', $userId)
                ->delete();

            return;
        }

        $existing = $model::query()
            ->where($foreignKey, $foreignId)
            ->where('user_id', $userId)
            ->where('emoji', $emoji)
            ->first();

        if ($existing) {
            $existing->delete();

            return;
        }

        $model::query()->updateOrInsert(
            [$foreignKey => $foreignId, 'user_id' => $userId],
            ['emoji' => $emoji, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /**
     * @param class-string<Model> $reactionModelClass
     * @param string $foreignKey
     * @param int $foreignId
     * @return list<array{emoji: string, creator_id: int, user: array<string, mixed>|null}>
     */
    public function formatReactions(string $reactionModelClass, string $foreignKey, int $foreignId): array
    {
        /** @var Model $model */
        $model = new $reactionModelClass;

        return $model::query()
            ->where($foreignKey, $foreignId)
            ->with('user:'.TimelineUserFormatter::SELECT_COLUMNS)
            ->get()
            ->map(fn (Model $reaction) => [
                'emoji' => (string) $reaction->emoji,
                'creator_id' => (int) $reaction->user_id,
                'user' => $reaction->relationLoaded('user')
                    ? TimelineUserFormatter::toArray($reaction->user)
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Model> $reactions
     * @return list<array{emoji: string, creator_id: int, user: array<string, mixed>|null}>
     */
    public function formatReactionCollection(Collection $reactions): array
    {
        return $reactions->map(fn (Model $reaction) => [
            'emoji' => (string) $reaction->emoji,
            'creator_id' => (int) $reaction->user_id,
            'user' => $reaction->relationLoaded('user')
                ? TimelineUserFormatter::toArray($reaction->user)
                : null,
        ])->values()->all();
    }

    /**
     * @param class-string<Model> $reactionModelClass
     * @param string $foreignKey
     * @param list<int> $foreignIds
     * @return array<int, list<array{emoji: string, count: int}>>
     */
    public function summarizeByForeignIds(string $reactionModelClass, string $foreignKey, array $foreignIds): array
    {
        $foreignIds = array_values(array_unique(array_filter(array_map('intval', $foreignIds), fn (int $id) => $id > 0)));
        if ($foreignIds === []) {
            return [];
        }

        /** @var Model $model */
        $model = new $reactionModelClass;

        $rows = $model::query()
            ->selectRaw("{$foreignKey} as foreign_id, emoji, COUNT(*) as reaction_count")
            ->whereIn($foreignKey, $foreignIds)
            ->groupBy($foreignKey, 'emoji')
            ->get();

        $result = [];
        foreach ($foreignIds as $id) {
            $result[$id] = [];
        }

        foreach ($rows as $row) {
            $id = (int) $row->foreign_id;
            $result[$id][] = [
                'emoji' => (string) $row->emoji,
                'count' => (int) $row->reaction_count,
            ];
        }

        return $result;
    }
}
