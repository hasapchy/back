<?php

namespace App\Repositories;

use App\Models\Comment;
use App\Models\TimelineReadState;
use App\Services\CacheService;
use App\Services\Timeline\TimelineEntityRegistry;
use App\Services\Timeline\TimelineUserFormatter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommentsRepository extends BaseRepository
{
    /**
     * Получить комментарии для сущности
     *
     * @param string $type Тип сущности
     * @param int $id ID сущности
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getCommentsFor(string $type, int $id)
    {
        $cacheKey = $this->generateCacheKey('comments', [$type, $id]);

        return CacheService::remember($cacheKey, function () use ($type, $id) {
            $modelClass = $this->resolveType($type);

            return Comment::select([
                'comments.id',
                'comments.body',
                'comments.commentable_type',
                'comments.commentable_id',
                'comments.creator_id',
                'comments.created_at',
                'comments.updated_at',
            ])
                ->with([
                    'creator:id,name,email',
                    'commentable',
                ])
                ->where('commentable_type', $modelClass)
                ->where('commentable_id', $id)
                ->orderBy('created_at', 'desc')
                ->get();
        }, $this->getCacheTTL('reference'));
    }

    /**
     * Создать комментарий
     *
     * @param string $type Тип сущности
     * @param int $id ID сущности
     * @param string $body Текст комментария
     * @param int $userId ID пользователя
     * @return array
     * @throws \Exception
     */
    public function createItem(string $type, int $id, string $body, int $userId): array
    {
        try {
            return DB::transaction(function () use ($type, $id, $body, $userId) {
                $modelClass = $this->resolveType($type);

                $comment = Comment::create([
                    'body' => $body,
                    'creator_id' => $userId,
                    'commentable_type' => $modelClass,
                    'commentable_id' => $id,
                ])->load(['creator:'.TimelineUserFormatter::SELECT_COLUMNS]);

                $this->invalidateCommentsCache($type, $id);

                return [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'commentable_type' => $comment->commentable_type,
                    'commentable_id' => $comment->commentable_id,
                    'created_at' => $comment->created_at,
                    'updated_at' => $comment->updated_at,
                    'creator_id' => $comment->creator_id,
                    'user' => TimelineUserFormatter::toArray($comment->creator),
                ];
            });
        } catch (\Throwable $e) {
            Log::error('comment.repository.create_item_failed', [
                'type' => $type,
                'entity_id' => $id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Обновить комментарий
     *
     * @param int $id ID комментария
     * @param int $userId ID пользователя
     * @param string $body Новый текст комментария
     * @return \App\Models\Comment|false
     * @throws \Exception
     */
    public function updateItem(int $id, int $userId, string $body)
    {
        return DB::transaction(function () use ($id, $userId, $body) {
            $query = Comment::select([
                'comments.id',
                'comments.body',
                'comments.commentable_type',
                'comments.commentable_id',
                'comments.creator_id'
            ])
                ->where('id', $id);

            $this->applyUserAccessFilter($query, $userId);

            $comment = $query->firstOrFail();

            $comment->body = $body;
            $comment->save();

            $this->invalidateCommentsCache(
                $this->apiTypeFromModelClass($comment->commentable_type),
                (int) $comment->commentable_id
            );

            return $comment->load(['creator:id,name,email']);
        });
    }

    /**
     * Удалить комментарий
     *
     * @param int $id ID комментария
     * @param int $userId ID пользователя
     * @return bool
     * @throws \Exception
     */
    public function deleteItem(int $id, int $userId)
    {
        return DB::transaction(function () use ($id, $userId) {
            $query = Comment::select([
                'comments.id',
                'comments.commentable_type',
                'comments.commentable_id'
            ])
                ->where('id', $id);

            $this->applyUserAccessFilter($query, $userId);

            $comment = $query->firstOrFail();
            $commentableType = $comment->commentable_type;
            $commentableId = $comment->commentable_id;

            $deleted = $comment->delete();

            if ($deleted) {
                $this->invalidateCommentsCache(
                    $this->apiTypeFromModelClass($commentableType),
                    (int) $commentableId
                );
            }

            return $deleted;
        });
    }

    /**
     * Разрешить тип сущности в класс модели
     *
     * @param string $type Тип сущности
     * @return string Класс модели
     * @throws \InvalidArgumentException
     */
    public function resolveType(string $type): string
    {
        try {
            return TimelineEntityRegistry::modelClassFromApiType($type);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException("Unknown commentable type: $type", 0, $e);
        }
    }

    /**
     * Короткий API-тип сущности по классу модели (для кэша таймлайна и комментариев)
     *
     * @param string $modelClass FQCN модели из commentable_type
     * @return string
     */
    public function apiTypeFromModelClass(string $modelClass): string
    {
        try {
            return TimelineEntityRegistry::apiTypeFromModelClass($modelClass);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException("Unknown commentable model class: {$modelClass}", 0, $e);
        }
    }

    /**
     * Инвалидировать кэш комментариев
     *
     * @param string $type Тип сущности
     * @param int $id ID сущности
     * @return void
     */
    private function invalidateCommentsCache(string $type, int $id)
    {
        CacheService::forget($this->generateCacheKey('comments', [$type, $id]));
        $companyId = $this->getCurrentCompanyId() ?? 'default';
        CacheService::invalidateByLike("%comments_{$type}_{$id}_{$companyId}%");
    }

    /**
     * Инвалидировать кэш комментариев по типу
     *
     * @param string $type Тип сущности
     * @return void
     */
    public function invalidateCommentsCacheByType(string $type)
    {
        $companyId = $this->getCurrentCompanyId() ?? 'default';
        CacheService::invalidateByLike("%comments_{$type}_{$companyId}%");
    }

    /**
     * Получить количество непрочитанных комментариев для списка сущностей
     *
     * @param string $type Тип сущности
     * @param array<int> $entityIds ID сущностей
     * @param int $userId ID текущего пользователя
     * @param int $companyId ID компании
     * @return array<int, int> Map: entityId => unreadCount
     */
    public function getUnreadCountsForEntities(string $type, array $entityIds, int $userId, int $companyId): array
    {
        $entityIds = array_values(array_unique(array_map('intval', $entityIds)));
        if ($entityIds === []) {
            return [];
        }

        $modelClass = $this->resolveType($type);

        $counts = Comment::query()
            ->from('comments as c')
            ->leftJoin('timeline_read_states as trs', function ($join) use ($userId, $companyId, $modelClass) {
                $join->on('trs.commentable_id', '=', 'c.commentable_id')
                    ->where('trs.user_id', '=', $userId)
                    ->where('trs.company_id', '=', $companyId)
                    ->where('trs.commentable_type', '=', $modelClass);
            })
            ->where('c.commentable_type', $modelClass)
            ->whereIn('c.commentable_id', $entityIds)
            ->where('c.creator_id', '!=', $userId)
            ->whereRaw('c.id > COALESCE(trs.last_read_comment_id, 0)')
            ->groupBy('c.commentable_id')
            ->selectRaw('c.commentable_id as entity_id, COUNT(c.id) as unread_count')
            ->pluck('unread_count', 'entity_id')
            ->map(fn ($value) => (int) $value)
            ->toArray();

        $result = [];
        foreach ($entityIds as $entityId) {
            $result[$entityId] = (int) ($counts[$entityId] ?? 0);
        }

        return $result;
    }

    /**
     * Пометить комментарии сущности как прочитанные
     *
     * @param string $type Тип сущности
     * @param int $entityId ID сущности
     * @param int $userId ID пользователя
     * @param int $companyId ID компании
     * @return int Идентификатор последнего прочитанного комментария
     */
    public function markEntityCommentsAsRead(string $type, int $entityId, int $userId, int $companyId): int
    {
        $modelClass = $this->resolveType($type);

        $lastCommentId = (int) (Comment::query()
            ->where('commentable_type', $modelClass)
            ->where('commentable_id', $entityId)
            ->max('id') ?? 0);

        TimelineReadState::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'company_id' => $companyId,
                'commentable_type' => $modelClass,
                'commentable_id' => $entityId,
            ],
            [
                'last_read_comment_id' => $lastCommentId > 0 ? $lastCommentId : null,
                'last_read_at' => now(),
            ]
        );

        return $lastCommentId;
    }

    /**
     * Применить фильтр доступа пользователя к запросу комментариев
     *
     * Администраторы могут редактировать/удалять любые комментарии,
     * обычные пользователи - только свои
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query builder
     * @param int $userId ID пользователя, от имени которого выполняется действие
     * @return void
     */
    private function applyUserAccessFilter($query, int $userId)
    {
        $currentUser = auth('api')->user();
        if (!optional($currentUser)->is_admin) {
            $query->where('creator_id', $userId);
        }
    }
}
