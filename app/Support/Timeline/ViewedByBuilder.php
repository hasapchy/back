<?php

namespace App\Support\Timeline;

use App\Models\Comment;
use App\Models\TimelineReadState;
use App\Models\User;
use App\Services\Timeline\TimelineUserFormatter;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ViewedByBuilder
{
    /**
     * @param Collection<int, TimelineReadState> $readStates
     * @return list<array{user_id: int, name: string, viewed_at: string}>
     */
    public static function forComment(Collection $readStates, Comment $comment): array
    {
        $viewedBy = $readStates
            ->filter(function (TimelineReadState $state) use ($comment) {
                return $state->last_read_comment_id !== null
                    && (int) $state->last_read_comment_id >= (int) $comment->id
                    && $state->last_read_at !== null;
            })
            ->map(function (TimelineReadState $state) {
                return [
                    'user_id' => (int) $state->user_id,
                    'name' => TimelineUserFormatter::fullName($state->user),
                    'viewed_at' => optional($state->last_read_at)->toISOString(),
                ];
            })
            ->filter(fn (array $row) => $row['name'] !== '' && $row['viewed_at'] !== null)
            ->values()
            ->all();

        return self::withCreator($viewedBy, $comment->creator, $comment->created_at);
    }

    /**
     * @param list<array{user_id: int, name: string, viewed_at: string}> $viewedBy
     * @return list<array{user_id: int, name: string, viewed_at: string}>
     */
    public static function withCreator(array $viewedBy, ?User $creator, ?CarbonInterface $viewedAt): array
    {
        $creatorId = (int) ($creator?->id ?? 0);
        if ($creatorId > 0) {
            $creatorExists = collect($viewedBy)->contains(fn (array $row) => (int) $row['user_id'] === $creatorId);
            if (! $creatorExists) {
                $name = TimelineUserFormatter::fullName($creator);
                $viewedAtIso = optional($viewedAt)->toISOString();
                if ($name !== '' && $viewedAtIso !== null) {
                    $viewedBy[] = [
                        'user_id' => $creatorId,
                        'name' => $name,
                        'viewed_at' => $viewedAtIso,
                    ];
                }
            }
        }

        return self::sortByViewedAtDesc($viewedBy);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function sortByViewedAtDesc(array $rows): array
    {
        usort($rows, function (array $a, array $b): int {
            $aTime = strtotime((string) ($a['viewed_at'] ?? '')) ?: 0;
            $bTime = strtotime((string) ($b['viewed_at'] ?? '')) ?: 0;

            return $bTime <=> $aTime;
        });

        return $rows;
    }
}
