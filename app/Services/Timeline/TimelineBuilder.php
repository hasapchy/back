<?php

namespace App\Services\Timeline;

use App\Contracts\SupportsTimeline;
use App\Models\Comment;
use App\Models\TimelineReadState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class TimelineBuilder
{
    public function __construct(
        private TimelineActivityPresenter $activityPresenter,
        private TimelineLightweightQuery $lightweightQuery
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null, has_more: bool}
     */
    public function buildPage(string $modelClass, int $id, ?int $companyId, int $limit, ?TimelineCursor $cursor): array
    {
        $registry = TimelineModelRegistry::config($modelClass);
        $limit = max(1, min(100, $limit));

        /** @var Model&SupportsTimeline $model */
        $model = $modelClass::query()->findOrFail($id);

        if (! $model instanceof SupportsTimeline) {
            throw new \RuntimeException('Модель не поддерживает комментарии или активность');
        }

        $page = $this->lightweightQuery->fetchPage(
            $modelClass,
            $id,
            $registry['merge_order_transaction_logs'],
            $limit,
            $cursor
        );

        $pageRows = collect($page['rows']);
        $hasMore = $page['has_more'];

        $items = $this->hydrateRows($model, $modelClass, $pageRows, $companyId);

        $nextCursor = null;
        if ($hasMore && $pageRows->isNotEmpty()) {
            $last = $pageRows->last();
            $nextCursor = TimelineCursor::fromRow($last)->encode();
        }

        return [
            'items' => $items,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function build(string $modelClass, int $id, ?int $companyId = null): Collection
    {
        $page = $this->buildPage($modelClass, $id, $companyId, 10000, null);

        return collect($page['items']);
    }

    /**
     * @param Model&SupportsTimeline $model
     * @param Collection<int, array{id: int, created_at: mixed, source: string}> $pageRows
     * @return list<array<string, mixed>>
     */
    private function hydrateRows(SupportsTimeline $model, string $modelClass, Collection $pageRows, ?int $companyId): array
    {
        if ($pageRows->isEmpty()) {
            return [];
        }

        $commentIds = $pageRows->where('source', TimelineCursor::SOURCE_COMMENT)->pluck('id')->all();
        $activityIds = $pageRows->where('source', TimelineCursor::SOURCE_ACTIVITY)->pluck('id')->all();
        $orderTxnActivityIds = $pageRows->where('source', TimelineCursor::SOURCE_ORDER_TRANSACTION)->pluck('id')->all();

        $byKey = [];

        if ($commentIds !== []) {
            $readStates = $this->loadReadStates($model, $companyId);
            $comments = $model->comments()
                ->select([
                    'comments.id',
                    'comments.body',
                    'comments.creator_id',
                    'comments.created_at',
                ])
                ->with(['creator:id,name,email'])
                ->whereIn('comments.id', $commentIds)
                ->get();

            foreach ($comments as $comment) {
                $item = $this->mapCommentToTimelineItem($comment, $readStates);
                $byKey[TimelineCursor::SOURCE_COMMENT.'_'.$comment->id] = $item;
            }
        }

        if ($activityIds !== []) {
            $activities = $model->activities()
                ->select([
                    'activity_log.id',
                    'activity_log.description',
                    'activity_log.properties',
                    'activity_log.causer_id',
                    'activity_log.created_at',
                    'activity_log.log_name',
                    'activity_log.event',
                ])
                ->with(['causer:id,name', 'subject'])
                ->whereIn('activity_log.id', $activityIds)
                ->get();

            foreach ($activities as $log) {
                $item = $this->activityPresenter->processActivityLog($log, $modelClass);
                $byKey[TimelineCursor::SOURCE_ACTIVITY.'_'.$log->id] = $item;
            }
        }

        if ($orderTxnActivityIds !== []) {
            $txnActivities = Activity::query()
                ->select([
                    'activity_log.id',
                    'activity_log.description',
                    'activity_log.properties',
                    'activity_log.causer_id',
                    'activity_log.created_at',
                    'activity_log.log_name',
                    'activity_log.event',
                ])
                ->with(['causer:id,name', 'subject'])
                ->whereIn('activity_log.id', $orderTxnActivityIds)
                ->get();

            foreach ($txnActivities as $log) {
                $item = $this->activityPresenter->processOrderTransactionActivityLog($log);
                if ($item !== null) {
                    $byKey[TimelineCursor::SOURCE_ORDER_TRANSACTION.'_'.$log->id] = $item;
                }
            }
        }

        $items = [];
        foreach ($pageRows as $row) {
            $key = $row['source'].'_'.$row['id'];
            if (isset($byKey[$key])) {
                $items[] = $byKey[$key];
            }
        }

        return $items;
    }

    /**
     * @param Model&SupportsTimeline $model
     * @return Collection<int, TimelineReadState>
     */
    private function loadReadStates(SupportsTimeline $model, ?int $companyId): Collection
    {
        $readStatesQuery = TimelineReadState::query()
            ->select([
                'timeline_read_states.user_id',
                'timeline_read_states.last_read_comment_id',
                'timeline_read_states.last_read_at',
            ])
            ->with(['user:id,name'])
            ->where('commentable_type', get_class($model))
            ->where('commentable_id', (int) $model->id);

        if ($companyId !== null && $companyId > 0) {
            $readStatesQuery->where('company_id', $companyId);
        }

        return $readStatesQuery->get();
    }

    /**
     * @param Collection<int, TimelineReadState> $readStates
     * @return array<string, mixed>
     */
    public function mapCommentToTimelineItem(Comment $comment, Collection $readStates): array
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
                    'name' => $state->user?->name ?? '',
                    'viewed_at' => optional($state->last_read_at)->toISOString(),
                ];
            })
            ->filter(fn (array $row) => $row['name'] !== '' && $row['viewed_at'] !== null)
            ->values()
            ->all();

        if ((int) $comment->creator_id > 0) {
            $creatorId = (int) $comment->creator_id;
            $creatorExists = collect($viewedBy)->contains(fn (array $row) => (int) $row['user_id'] === $creatorId);
            if (! $creatorExists) {
                array_unshift($viewedBy, [
                    'user_id' => $creatorId,
                    'name' => $comment->creator?->name ?? '',
                    'viewed_at' => optional($comment->created_at)->toISOString(),
                ]);
            }
        }

        return [
            'type' => 'comment',
            'id' => $comment->id,
            'body' => $comment->body,
            'user' => $comment->creator,
            'created_at' => $comment->created_at,
            'viewed_by' => $viewedBy,
        ];
    }

    /**
     * @param Model&SupportsTimeline $model
     * @return array<string, mixed>
     */
    public function buildCommentItemForEntity(SupportsTimeline $model, Comment $comment, ?int $companyId): array
    {
        $comment->loadMissing(['creator:id,name,email']);
        $readStates = $this->loadReadStates($model, $companyId);

        return $this->mapCommentToTimelineItem($comment, $readStates);
    }
}
