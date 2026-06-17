<?php

namespace App\Repositories;

use App\Models\Comment;
use App\Models\News;
use App\Models\TimelineReadState;
use App\Models\User;
use App\Services\CacheService;
use App\Services\CommentModerationGuard;
use App\Services\Timeline\TimelineEntityRegistry;
use App\Services\Timeline\TimelineUserFormatter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class CommentsRepository extends BaseRepository
{
    public function __construct(
        private readonly CommentModerationGuard $moderationGuard
    ) {}

    /**
     * @param string $type
     * @param int $id
     * @return \Illuminate\Database\Eloquent\Collection<int, Comment>
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
                ->whereNull('parent_id')
                ->orderBy('created_at', 'desc')
                ->get();
        }, $this->getCacheTTL('reference'));
    }

    /**
     * @param int $newsId
     * @param int $limit
     * @param int|null $cursor
     * @return array{items: list<Comment>, next_cursor: int|null, has_more: bool}
     */
    public function getNewsCommentsPage(int $newsId, int $limit = 20, ?int $cursor = null): array
    {
        $limit = max(1, min(100, $limit));
        $modelClass = News::class;

        $query = Comment::query()
            ->where('commentable_type', $modelClass)
            ->where('commentable_id', $newsId)
            ->whereNull('parent_id')
            ->with([
                'creator:'.TimelineUserFormatter::SELECT_COLUMNS,
                'parent.creator:'.TimelineUserFormatter::SELECT_COLUMNS,
                'replies.creator:'.TimelineUserFormatter::SELECT_COLUMNS,
                'reactions.user:'.TimelineUserFormatter::SELECT_COLUMNS,
                'replies.reactions.user:'.TimelineUserFormatter::SELECT_COLUMNS,
            ])
            ->orderBy('id');

        if ($cursor !== null && $cursor > 0) {
            $query->where('id', '>', $cursor);
        }

        $comments = $query->limit($limit + 1)->get();
        $hasMore = $comments->count() > $limit;
        if ($hasMore) {
            $comments = $comments->take($limit);
        }

        $companyId = (int) ($this->getCurrentCompanyId() ?? 0);
        $readStates = TimelineReadState::query()
            ->select([
                'timeline_read_states.user_id',
                'timeline_read_states.last_read_comment_id',
                'timeline_read_states.last_read_at',
            ])
            ->with(['user:id,name'])
            ->where('commentable_type', $modelClass)
            ->where('commentable_id', $newsId)
            ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
            ->get();

        $items = $comments->map(function (Comment $comment) use ($readStates) {
            $this->attachViewedBy($comment, $readStates);
            foreach ($comment->replies as $reply) {
                $this->attachViewedBy($reply, $readStates);
            }

            return $comment;
        })->values()->all();

        $nextCursor = null;
        if ($hasMore && $comments->isNotEmpty()) {
            $nextCursor = (int) $comments->last()->id;
        }

        return [
            'items' => $items,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @param Comment $comment
     * @param Collection<int, TimelineReadState> $readStates
     * @return void
     */
    private function attachViewedBy(Comment $comment, Collection $readStates): void
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

        $comment->setAttribute('viewed_by', $viewedBy);
    }

    /**
     * @param string $type
     * @param int $id
     * @param string $body
     * @param int $userId
     * @param int|null $parentId
     * @return array<string, mixed>
     */
    public function createItem(string $type, int $id, string $body, int $userId, ?int $parentId = null): array
    {
        try {
            return DB::transaction(function () use ($type, $id, $body, $userId, $parentId) {
                $modelClass = $this->resolveType($type);

                if ($parentId !== null) {
                    $this->assertValidParentComment($modelClass, $id, $parentId);
                }

                $comment = Comment::create([
                    'body' => $body,
                    'creator_id' => $userId,
                    'parent_id' => $parentId,
                    'commentable_type' => $modelClass,
                    'commentable_id' => $id,
                ])->load(['creator:'.TimelineUserFormatter::SELECT_COLUMNS]);

                $this->invalidateCommentsCache($type, $id);

                return [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'parent_id' => $comment->parent_id,
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
     * @param class-string $modelClass
     * @param int $entityId
     * @param int $parentId
     * @return void
     */
    private function assertValidParentComment(string $modelClass, int $entityId, int $parentId): void
    {
        $parent = Comment::query()
            ->where('id', $parentId)
            ->where('commentable_type', $modelClass)
            ->where('commentable_id', $entityId)
            ->first();

        if (! $parent) {
            throw new InvalidArgumentException('Parent comment does not belong to this entity');
        }

        if ($parent->parent_id !== null) {
            throw new InvalidArgumentException('Nested replies deeper than one level are not supported');
        }
    }

    /**
     * @param int $id
     * @param int $userId
     * @param string $body
     * @return Comment|null
     */
    public function updateItem(int $id, int $userId, string $body)
    {
        return DB::transaction(function () use ($id, $userId, $body) {
            $comment = Comment::query()->where('id', $id)->first();
            if (! $comment) {
                return null;
            }

            $user = User::query()->find($userId);
            if (! $user || ! $this->moderationGuard->canUpdate($user, $comment)) {
                return null;
            }

            $comment->body = $body;
            $comment->save();

            $this->invalidateCommentsCache(
                $this->apiTypeFromModelClass($comment->commentable_type),
                (int) $comment->commentable_id
            );

            return $comment->load(['creator:'.TimelineUserFormatter::SELECT_COLUMNS]);
        });
    }

    /**
     * @param int $id
     * @param int $userId
     * @param int $companyId
     * @return bool
     */
    public function deleteItem(int $id, int $userId, int $companyId): bool
    {
        return DB::transaction(function () use ($id, $userId, $companyId) {
            $comment = Comment::query()->where('id', $id)->first();
            if (! $comment) {
                return false;
            }

            $user = User::query()->find($userId);
            if (! $user || ! $this->moderationGuard->canDelete($user, $comment, $companyId)) {
                return false;
            }

            $commentableType = $comment->commentable_type;
            $commentableId = (int) $comment->commentable_id;
            $deleted = (bool) $comment->delete();

            if ($deleted) {
                $this->invalidateCommentsCache(
                    $this->apiTypeFromModelClass($commentableType),
                    $commentableId
                );
            }

            return $deleted;
        });
    }

    /**
     * @param string $type
     * @return string
     */
    public function resolveType(string $type): string
    {
        try {
            return TimelineEntityRegistry::modelClassFromApiType($type);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("Unknown commentable type: $type", 0, $e);
        }
    }

    /**
     * @param string $modelClass
     * @return string
     */
    public function apiTypeFromModelClass(string $modelClass): string
    {
        try {
            return TimelineEntityRegistry::apiTypeFromModelClass($modelClass);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("Unknown commentable model class: {$modelClass}", 0, $e);
        }
    }

    /**
     * @param string $type
     * @param int $id
     * @return void
     */
    private function invalidateCommentsCache(string $type, int $id): void
    {
        CacheService::forget($this->generateCacheKey('comments', [$type, $id]));
        $companyId = $this->getCurrentCompanyId() ?? 'default';
        CacheService::invalidateByLike("%comments_{$type}_{$id}_{$companyId}%");
    }

    /**
     * @param string $type
     * @return void
     */
    public function invalidateCommentsCacheByType(string $type): void
    {
        $companyId = $this->getCurrentCompanyId() ?? 'default';
        CacheService::invalidateByLike("%comments_{$type}_{$companyId}%");
    }

    /**
     * @param string $type
     * @param list<int> $entityIds
     * @param int $userId
     * @param int $companyId
     * @return array<int, int>
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
     * @param string $type
     * @param int $entityId
     * @param int $userId
     * @param int $companyId
     * @return int
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

        if ($type === 'news') {
            $this->invalidateCommentsCache($type, $entityId);
        }

        return $lastCommentId;
    }
}
