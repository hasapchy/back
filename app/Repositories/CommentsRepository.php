<?php

namespace App\Repositories;

use App\Models\Comment;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;

class CommentsRepository
{
    /**
     * Получить комментарии для сущности с оптимизацией
     */
    public function getCommentsFor(string $type, int $id)
    {
        $cacheKey = "comments_{$type}_{$id}";

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
        }, 1800); // 30 минут
    }

    /**
     * Получить комментарии для нескольких сущностей (batch loading)
     */
    public function getCommentsForBatch(string $type, array $ids)
    {
        if (empty($ids)) {
            return collect();
        }

        $cacheKey = "comments_batch_{$type}_" . md5(implode(',', $ids));

        return CacheService::remember($cacheKey, function () use ($type, $ids) {
            $modelClass = $this->resolveType($type);

            return Comment::select([
                'comments.id', 'comments.body', 'comments.commentable_type',
                'comments.commentable_id', 'comments.user_id', 'comments.created_at',
                'comments.updated_at'
            ])
            ->with([
                'user:id,name,email'
            ])
            ->where('commentable_type', $modelClass)
            ->whereIn('commentable_id', $ids)
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('commentable_id');
        }, 1800); // 30 минут
    }

    /**
     * Получить комментарии с пагинацией
     */
    public function getCommentsWithPagination(string $type, int $id, int $perPage = 20)
    {
        $cacheKey = "comments_paginated_{$type}_{$id}_{$perPage}";

        return CacheService::remember($cacheKey, function () use ($type, $id, $perPage) {
            $modelClass = $this->resolveType($type);

            return Comment::select([
                'comments.id', 'comments.body', 'comments.commentable_type',
                'comments.commentable_id', 'comments.user_id', 'comments.created_at',
                'comments.updated_at'
            ])
            ->with([
                'user:id,name,email'
            ])
            ->where('commentable_type', $modelClass)
            ->where('commentable_id', $id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
        }, 900); // 15 минут для пагинации
    }

    /**
     * Создание комментария с оптимизацией
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

            // Инвалидируем кэш комментариев
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
     * Обновление комментария с оптимизацией
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

            // Инвалидируем кэш комментариев
            $this->invalidateCommentsCache($comment->commentable_type, $comment->commentable_id);

            return $comment->load(['user:id,name,email']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Удаление комментария с оптимизацией
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
                // Инвалидируем кэш комментариев
                $this->invalidateCommentsCache($comment->commentable_type, $comment->commentable_id);
            }

            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Получить последние комментарии для таймлайна
     */
    public function getRecentCommentsForTimeline(string $type, int $id, int $limit = 50)
    {
        $cacheKey = "comments_timeline_{$type}_{$id}_{$limit}";

        return CacheService::remember($cacheKey, function () use ($type, $id, $limit) {
            $modelClass = $this->resolveType($type);

            return Comment::select([
                'comments.id', 'comments.body', 'comments.commentable_type',
                'comments.commentable_id', 'comments.user_id', 'comments.created_at'
            ])
            ->with([
                'user:id,name,email'
            ])
            ->where('commentable_type', $modelClass)
            ->where('commentable_id', $id)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
        }, 600); // 10 минут для таймлайна
    }

    /**
     * Получить статистику комментариев
     */
    public function getCommentsStats(string $type, int $id)
    {
        $cacheKey = "comments_stats_{$type}_{$id}";

        return CacheService::remember($cacheKey, function () use ($type, $id) {
            $modelClass = $this->resolveType($type);

            return Comment::where('commentable_type', $modelClass)
                ->where('commentable_id', $id)
                ->selectRaw('
                    COUNT(*) as total_comments,
                    COUNT(DISTINCT user_id) as unique_users,
                    MAX(created_at) as last_comment_date,
                    MIN(created_at) as first_comment_date
                ')
                ->first();
        }, 1800); // 30 минут
    }

    /**
     * Поиск комментариев
     */
    public function searchComments(string $type, int $id, string $search, int $perPage = 20)
    {
        $cacheKey = "comments_search_{$type}_{$id}_{$search}_{$perPage}";

        return CacheService::rememberSearch($cacheKey, function () use ($type, $id, $search, $perPage) {
            $modelClass = $this->resolveType($type);

            return Comment::select([
                'comments.id', 'comments.body', 'comments.commentable_type',
                'comments.commentable_id', 'comments.user_id', 'comments.created_at'
            ])
            ->with([
                'user:id,name,email'
            ])
            ->where('commentable_type', $modelClass)
            ->where('commentable_id', $id)
            ->where('body', 'like', "%{$search}%")
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
        });
    }

    /**
     * Разрешение типа сущности
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
     * Инвалидация кэша комментариев
     */
    private function invalidateCommentsCache(string $type, int $id)
    {
        // Точное удаление конкретных ключей без wildcard
        \Illuminate\Support\Facades\Cache::forget("comments_{$type}_{$id}");
        \Illuminate\Support\Facades\Cache::forget("comments_stats_{$type}_{$id}");

        // Используем централизованный метод из CacheService
        CacheService::invalidateByLike("%comments_paginated_{$type}_{$id}%");
        CacheService::invalidateByLike("%comments_timeline_{$type}_{$id}%");
        CacheService::invalidateByLike("%comments_batch_{$type}%");
    }

    /**
     * Инвалидация кэша комментариев по типу
     */
    public function invalidateCommentsCacheByType(string $type)
    {
        // Используем централизованный метод из CacheService
        CacheService::invalidateByLike("%comments_{$type}%");
    }
}
