<?php

namespace App\Repositories;

use App\Models\Comment;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;

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
                'comments.id', 'comments.body', 'comments.commentable_type',
                'comments.commentable_id', 'comments.user_id', 'comments.created_at',
                'comments.updated_at'
            ])
            ->with([
                'user:id,name,email',
                'commentable:id,name'
            ])
            ->where('commentable_type', $modelClass)
            ->where('commentable_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();
        }, 1800);
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
        DB::beginTransaction();
        try {
            $modelClass = $this->resolveType($type);

            $comment = Comment::create([
                'body' => $body,
                'user_id' => $userId,
                'commentable_type' => $modelClass,
                'commentable_id' => $id,
            ])->load(['user:id,name,email']);

            DB::commit();

            $this->invalidateCommentsCache($type, $id);

            return [
                'id' => $comment->id,
                'body' => $comment->body,
                'commentable_type' => $comment->commentable_type,
                'commentable_id' => $comment->commentable_id,
                'created_at' => $comment->created_at,
                'updated_at' => $comment->updated_at,
                'user_id' => $comment->user_id,
                'user' => [
                    'id' => $comment->user->id,
                    'name' => $comment->user->name,
                ],
            ];
        } catch (\Exception $e) {
            DB::rollBack();
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
        DB::beginTransaction();
        try {
            $comment = Comment::select([
                'comments.id', 'comments.body', 'comments.commentable_type',
                'comments.commentable_id', 'comments.user_id'
            ])
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

            if (!$comment) {
                DB::rollBack();
                return false;
            }

            $comment->body = $body;
            $comment->save();

            DB::commit();

            $this->invalidateCommentsCache($comment->commentable_type, $comment->commentable_id);

            return $comment->load(['user:id,name,email']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
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
        DB::beginTransaction();
        try {
            $comment = Comment::select([
                'comments.id', 'comments.commentable_type', 'comments.commentable_id'
            ])
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

            if (!$comment) {
                DB::rollBack();
                return false;
            }

            $deleted = $comment->delete();

            DB::commit();

            if ($deleted) {
                $this->invalidateCommentsCache($comment->commentable_type, $comment->commentable_id);
            }

            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
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
        return match ($type) {
            'order' => \App\Models\Order::class,
            'sale' => \App\Models\Sale::class,
            'transaction' => \App\Models\Transaction::class,
            'client' => \App\Models\Client::class,
            'product' => \App\Models\Product::class,
            'project' => \App\Models\Project::class,
            default => throw new \InvalidArgumentException("Unknown commentable type: $type"),
        };
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
}
