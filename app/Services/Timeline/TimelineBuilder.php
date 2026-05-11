<?php

namespace App\Services\Timeline;

use App\Contracts\SupportsTimeline;
use App\Models\Comment;
use App\Models\TimelineReadState;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class TimelineBuilder
{
    public function __construct(
        private TimelineActivityPresenter $activityPresenter
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function build(string $modelClass, int $id, ?int $companyId = null): Collection
    {
        $registry = TimelineModelRegistry::config($modelClass);
        $selectFields = array_merge(['id'], $registry['select']);

        /** @var Model $model */
        $model = $modelClass::query()
            ->select($selectFields)
            ->with($registry['with'])
            ->findOrFail($id);

        if (isset($model->client) && $model->client) {
            $model->client->name = $model->client->first_name . ' ' . $model->client->last_name;
        }

        if (! $model instanceof SupportsTimeline) {
            throw new \RuntimeException('Модель не поддерживает комментарии или активность');
        }

        $comments = $this->collectComments($model, $companyId);
        $activities = $this->collectActivities($model, $modelClass);

        if ($registry['merge_order_transaction_logs']) {
            $activities = $activities->toBase()->merge(
                $this->activityPresenter->collectOrderTransactionTimelineRows($id)
            );
        }

        return $comments
            ->toBase()
            ->merge($activities->toBase())
            ->sortBy(function (array $item) {
                return Carbon::parse($item['created_at']);
            })
            ->values();
    }

    /**
     * @return Collection<int, mixed>
     */
    private function collectComments(SupportsTimeline $model, ?int $companyId = null): Collection
    {
        $commentRows = $model->comments()
            ->select([
                'comments.id',
                'comments.body',
                'comments.creator_id',
                'comments.created_at',
            ])
            ->with(['creator:id,name,email'])
            ->orderBy('created_at', 'desc')
            ->get();

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

        $readStates = $readStatesQuery->get();

        return $commentRows->map(function (Comment $comment) use ($readStates) {
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
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function collectActivities(SupportsTimeline $model, string $modelClass): Collection
    {
        $presenter = $this->activityPresenter;

        return $model->activities()
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
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (Activity $log) use ($presenter, $modelClass) {
                return $presenter->processActivityLog($log, $modelClass);
            });
    }
}
