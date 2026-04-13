<?php

namespace App\Services\Timeline;

use App\Contracts\SupportsTimeline;
use App\Models\Comment;
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
    public function build(string $modelClass, int $id): Collection
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

        $comments = $this->collectComments($model);
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
    private function collectComments(SupportsTimeline $model): Collection
    {
        return $model->comments()
            ->select([
                'comments.id',
                'comments.body',
                'comments.creator_id',
                'comments.created_at',
            ])
            ->with(['creator:id,name,email'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (Comment $comment) {
                return [
                    'type' => 'comment',
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'user' => $comment->creator,
                    'created_at' => $comment->created_at,
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
