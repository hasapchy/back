<?php

namespace App\Http\Controllers\Api;

use App\Services\EngagementReactionService;
use App\Events\TimelineItemCreated;
use App\Contracts\SupportsTimeline;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Requests\UpdateCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\News;
use App\Repositories\CommentsRepository;
use App\Services\CacheService;
use App\Services\InAppNotifications\InAppNotificationDispatcher;
use App\Services\Timeline\TimelineEntityRegistry;
use App\Services\Timeline\TimelineBuilder;
use App\Services\Timeline\TimelineCache;
use App\Services\Timeline\TimelineCursor;
use App\Services\Timeline\TimelineEntityAccessGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * @group Контент
 * @subgroup Комментарии
 */
class CommentController extends BaseController
{
    public function __construct(
        protected CommentsRepository $itemsRepository,
        protected TimelineBuilder $timelineBuilder,
        protected TimelineEntityAccessGuard $timelineAccessGuard,
        protected EngagementReactionService $engagementReactionService,
        protected InAppNotificationDispatcher $inAppNotificationDispatcher,
    ) {}

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        $request->validate([
            'type' => ['required', 'string', Rule::in(TimelineEntityRegistry::apiTypes())],
            'id' => 'required|integer',
            'limit' => 'sometimes|integer|min:1|max:100',
            'cursor' => 'sometimes|nullable|integer|min:0',
        ]);

        $companyId = (int) ($this->getCurrentCompanyId() ?? 0);
        $apiType = (string) $request->type;
        $entityId = (int) $request->id;

        $this->timelineAccessGuard->resolveEntityForCompany($user, $apiType, $entityId, $companyId);

        if ($apiType === 'news') {
            $page = $this->itemsRepository->getNewsCommentsPage(
                $entityId,
                (int) $request->input('limit', 20),
                $request->filled('cursor') ? (int) $request->input('cursor') : null
            );

            return $this->successResponse([
                'items' => CommentResource::collection(collect($page['items']))->resolve(),
                'next_cursor' => $page['next_cursor'],
                'has_more' => $page['has_more'],
            ]);
        }

        $comments = $this->itemsRepository->getCommentsFor($apiType, $entityId);

        return $this->successResponse(CommentResource::collection($comments)->resolve());
    }

    /**
     * @param StoreCommentRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreCommentRequest $request)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        $validatedData = $request->validated();
        $companyId = (int) ($this->getCurrentCompanyId() ?? 0);
        $apiType = (string) $validatedData['type'];
        $entityId = (int) $validatedData['id'];
        $parentId = isset($validatedData['parent_id']) ? (int) $validatedData['parent_id'] : null;

        try {
            $model = $this->timelineAccessGuard->resolveEntityForCompany($user, $apiType, $entityId, $companyId);
            if (! $model instanceof SupportsTimeline) {
                throw new \RuntimeException('Timeline entity does not implement SupportsTimeline');
            }

            $commentPayload = $this->itemsRepository->createItem(
                $apiType,
                $entityId,
                $validatedData['body'],
                (int) $user->id,
                $parentId
            );

            $this->invalidateTimelineCache($apiType, $entityId);

            $comment = Comment::query()->findOrFail((int) $commentPayload['id']);
            $timelineItem = $this->timelineBuilder->buildCommentItemForEntity($model, $comment, $companyId);
            TimelineItemCreated::dispatch($companyId, $apiType, $entityId, $timelineItem);

            if ($apiType === 'news' && $companyId > 0) {
                $this->dispatchNewsCommentNotifications($model, $comment, $companyId, (int) $user->id, $parentId);
            }

            return $this->successResponse([
                'message' => 'Комментарий добавлен',
                'comment' => (new CommentResource($comment->load(['creator:' . \App\Services\Timeline\TimelineUserFormatter::SELECT_COLUMNS])))->resolve(),
                'timeline_item' => $timelineItem,
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('comment.store.failed', [
                'user_id' => (int) $user->id,
                'company_id' => $companyId,
                'type' => $apiType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(__('api.comments.save_failed'), 500);
        }
    }

    /**
     * @param UpdateCommentRequest $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateCommentRequest $request, $id)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        $validatedData = $request->validated();
        $companyId = (int) ($this->getCurrentCompanyId() ?? 0);

        $updatedComment = $this->itemsRepository->updateItem($id, (int) $user->id, $validatedData['body']);

        if (! $updatedComment) {
            return $this->errorResponse(__('api.comments.not_found_or_forbidden'), 403);
        }

        $apiType = $this->itemsRepository->apiTypeFromModelClass($updatedComment->commentable_type);
        $this->timelineAccessGuard->resolveEntityForCompany(
            $user,
            $apiType,
            (int) $updatedComment->commentable_id,
            $companyId
        );

        $this->invalidateTimelineCache($apiType, (int) $updatedComment->commentable_id);

        return $this->successResponse(new CommentResource($updatedComment), __('api.comments.updated'));
    }

    /**
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        $comment = Comment::query()->where('id', $id)->first();
        if (! $comment) {
            return $this->errorResponse(__('api.comments.not_found_or_forbidden'), 404);
        }

        $apiType = $this->itemsRepository->apiTypeFromModelClass($comment->commentable_type);
        $commentableId = (int) $comment->commentable_id;
        $companyId = (int) ($this->getCurrentCompanyId() ?? 0);
        $this->timelineAccessGuard->resolveEntityForCompany($user, $apiType, $commentableId, $companyId);

        $deleted = $this->itemsRepository->deleteItem($id, (int) $user->id, $companyId);

        if (! $deleted) {
            return $this->errorResponse(__('api.comments.not_found_or_forbidden'), 403);
        }

        $this->invalidateTimelineCache($apiType, $commentableId);

        return $this->successResponse(null, __('api.comments.deleted'));
    }

    /**
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function setReaction(Request $request, int $id)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        $validated = $request->validate([
            'emoji' => 'nullable|string|max:16',
        ]);

        $comment = Comment::query()->findOrFail($id);
        if ($comment->commentable_type !== News::class) {
            return $this->errorResponse(__('api.comments.not_found_or_forbidden'), 422);
        }

        $companyId = (int) ($this->getCurrentCompanyId() ?? 0);
        $newsId = (int) $comment->commentable_id;
        $this->timelineAccessGuard->resolveEntityForCompany($user, 'news', $newsId, $companyId);

        $emoji = $validated['emoji'] ?? null;
        $reactions = $this->engagementReactionService->setCommentReactionForModel(
            $companyId,
            $comment,
            (int) $user->id,
            $emoji
        );

        return $this->successResponse(['reactions' => $reactions]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function timeline(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        $request->validate([
            'type' => ['required', 'string', Rule::in(TimelineEntityRegistry::apiTypes())],
            'id' => 'required|integer',
            'limit' => 'sometimes|integer|min:1|max:100',
            'cursor' => 'sometimes|nullable|string',
        ]);

        try {
            $apiType = (string) $request->type;
            $entityId = (int) $request->id;
            $companyId = (int) ($this->getCurrentCompanyId() ?? 0);
            $limit = (int) $request->input('limit', 50);
            $cursorRaw = $request->input('cursor');
            $cursor = $cursorRaw ? TimelineCursor::decode((string) $cursorRaw) : null;

            $this->timelineAccessGuard->resolveEntityForCompany($user, $apiType, $entityId, $companyId);
            $modelClass = $this->itemsRepository->resolveType($apiType);

            $loadPage = function () use ($modelClass, $entityId, $companyId, $limit, $cursor) {
                return $this->timelineBuilder->buildPage($modelClass, $entityId, $companyId, $limit, $cursor);
            };

            if ($cursor === null) {
                $cacheKey = TimelineCache::page1Key($apiType, $entityId, $companyId);
                $page = CacheService::remember($cacheKey, $loadPage, 60);
            } else {
                $page = $loadPage();
            }

            return $this->successResponse($page);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(__('api.comments.invalid_timeline_cursor'), 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            abort(404);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('comment.timeline.failed', [
                'type' => $request->input('type'),
                'id' => $request->input('id'),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(__('api.comments.timeline_load_failed'), 500);
        }
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function unreadCounts(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(TimelineEntityRegistry::apiTypes())],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        $companyId = (int) $this->getCurrentCompanyId();
        if ($companyId < 1) {
            return $this->errorResponse(__('api.common.company_context_required'), 422);
        }

        $allowedIds = $this->timelineAccessGuard->filterAccessibleEntityIds(
            $user,
            $validated['type'],
            $validated['ids'],
            $companyId
        );

        $counts = $this->itemsRepository->getUnreadCountsForEntities(
            $validated['type'],
            $allowedIds,
            (int) $user->id,
            $companyId
        );

        return $this->successResponse([
            'counts' => $counts,
        ]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function markRead(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(TimelineEntityRegistry::apiTypes())],
            'id' => ['required', 'integer', 'min:1'],
        ]);

        $companyId = (int) $this->getCurrentCompanyId();
        if ($companyId < 1) {
            return $this->errorResponse(__('api.common.company_context_required'), 422);
        }

        $this->timelineAccessGuard->resolveEntityForCompany(
            $user,
            $validated['type'],
            (int) $validated['id'],
            $companyId
        );

        $lastReadCommentId = $this->itemsRepository->markEntityCommentsAsRead(
            $validated['type'],
            (int) $validated['id'],
            (int) $user->id,
            $companyId
        );
        $this->invalidateTimelineCache($validated['type'], (int) $validated['id']);

        return $this->successResponse([
            'last_read_comment_id' => $lastReadCommentId,
        ]);
    }

    /**
     * @param News $news
     * @param Comment $comment
     * @param int $companyId
     * @param int $actorUserId
     * @param int|null $parentId
     * @return void
     */
    private function dispatchNewsCommentNotifications(News $news, Comment $comment, int $companyId, int $actorUserId, ?int $parentId): void
    {
        $bodyPreview = mb_substr((string) $comment->body, 0, 120);
        $data = ['route' => '/news', 'news_id' => $news->id, 'comment_id' => $comment->id];

        if ($parentId !== null) {
            $parent = Comment::query()->find($parentId);
            $recipientId = (int) ($parent?->creator_id ?? 0);
            if ($recipientId > 0 && $recipientId !== $actorUserId) {
                $this->inAppNotificationDispatcher->dispatchToUserIds(
                    $companyId,
                    'news_comment_reply',
                    [$recipientId],
                    $actorUserId,
                    'Ответ на комментарий',
                    $bodyPreview,
                    $data
                );
            }

            return;
        }

        $recipientId = (int) ($news->creator_id ?? 0);
        if ($recipientId > 0 && $recipientId !== $actorUserId) {
            $this->inAppNotificationDispatcher->dispatchToUserIds(
                $companyId,
                'news_comment_new',
                [$recipientId],
                $actorUserId,
                'Комментарий к новости',
                $bodyPreview,
                $data
            );
        }
    }

    /**
     * @param string $apiType
     * @param int $id
     * @return void
     */
    private function invalidateTimelineCache(string $apiType, int $id): void
    {
        TimelineCache::forget($apiType, $id, (int) ($this->getCurrentCompanyId() ?? 0));
        $this->itemsRepository->invalidateCommentsCacheByType($apiType);
    }
}
