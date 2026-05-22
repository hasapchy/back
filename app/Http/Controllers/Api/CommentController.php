<?php

namespace App\Http\Controllers\Api;

use App\Events\TimelineItemCreated;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Requests\UpdateCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Repositories\CommentsRepository;
use App\Services\CacheService;
use App\Services\Timeline\TimelineBuilder;
use App\Services\Timeline\TimelineCache;
use App\Services\Timeline\TimelineCursor;
use App\Services\Timeline\TimelineEntityAccessGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Контроллер для работы с комментариями
 */
/**
 * @group Контент
 * @subgroup Комментарии
 */
class CommentController extends BaseController
{
    /**
     * @param CommentsRepository $itemsRepository Репозиторий комментариев
     * @param TimelineBuilder $timelineBuilder Сборщик таймлайна
     * @param TimelineEntityAccessGuard $timelineAccessGuard Проверка доступа к сущности таймлайна
     */
    public function __construct(
        protected CommentsRepository $itemsRepository,
        protected TimelineBuilder $timelineBuilder,
        protected TimelineEntityAccessGuard $timelineAccessGuard
    ) {}

    /**
     * Получить список комментариев для сущности
     *
     * @param Request $request HTTP-запрос
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        $request->validate([
            'type' => 'required|string',
            'id' => 'required|integer',
        ]);

        $companyId = (int) ($this->getCurrentCompanyId() ?? 0);
        $this->timelineAccessGuard->resolveEntityForCompany(
            $user,
            (string) $request->type,
            (int) $request->id,
            $companyId
        );

        $comments = $this->itemsRepository->getCommentsFor($request->type, $request->id);

        return $this->successResponse(CommentResource::collection($comments)->resolve());
    }

    /**
     * Создать комментарий
     *
     * @param StoreCommentRequest $request Запрос с валидированными данными
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

        Log::info('comment.store.request', [
            'user_id' => (int) $user->id,
            'company_id' => $companyId,
            'type' => $apiType,
            'entity_id' => $entityId,
            'body_length' => strlen((string) ($validatedData['body'] ?? '')),
        ]);

        try {
            $model = $this->timelineAccessGuard->resolveEntityForCompany($user, $apiType, $entityId, $companyId);

            $commentPayload = $this->itemsRepository->createItem(
                $apiType,
                $entityId,
                $validatedData['body'],
                $user->id
            );

            $this->invalidateTimelineCache($apiType, $entityId);

            $comment = Comment::query()->findOrFail((int) $commentPayload['id']);
            $timelineItem = $this->timelineBuilder->buildCommentItemForEntity($model, $comment, $companyId);
            TimelineItemCreated::dispatch($companyId, $apiType, $entityId, $timelineItem);

            Log::info('comment.store.success', [
                'user_id' => (int) $user->id,
                'company_id' => $companyId,
                'type' => $apiType,
                'entity_id' => $entityId,
                'comment_id' => (int) ($commentPayload['id'] ?? 0),
            ]);

            return $this->successResponse([
                'message' => 'Комментарий добавлен',
                'comment' => (new CommentResource($commentPayload))->resolve(),
                'timeline_item' => $timelineItem,
            ]);
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

            return $this->errorResponse('Ошибка сохранения комментария', 500);
        }
    }

    /**
     * Обновить комментарий
     *
     * @param UpdateCommentRequest $request Запрос с валидированными данными
     * @param int $id ID комментария
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateCommentRequest $request, $id)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        $validatedData = $request->validated();

        $updatedComment = $this->itemsRepository->updateItem($id, $user->id, $validatedData['body']);

        if (! $updatedComment) {
            return $this->errorResponse('Комментарий не найден или нет прав', 403);
        }

        $apiType = $this->itemsRepository->apiTypeFromModelClass($updatedComment->commentable_type);
        $companyId = (int) ($this->getCurrentCompanyId() ?? 0);
        $this->timelineAccessGuard->resolveEntityForCompany(
            $user,
            $apiType,
            (int) $updatedComment->commentable_id,
            $companyId
        );

        $this->invalidateTimelineCache(
            $apiType,
            (int) $updatedComment->commentable_id
        );

        return $this->successResponse(new CommentResource($updatedComment), 'Комментарий обновлён');
    }

    /**
     * Удалить комментарий
     *
     * @param int $id ID комментария
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        $comment = Comment::query()
            ->where('id', $id)
            ->where('creator_id', $user->id)
            ->first();

        if (! $comment) {
            return $this->errorResponse('Комментарий не найден или нет прав', 404);
        }

        $apiType = $this->itemsRepository->apiTypeFromModelClass($comment->commentable_type);
        $commentableId = (int) $comment->commentable_id;
        $companyId = (int) ($this->getCurrentCompanyId() ?? 0);
        $this->timelineAccessGuard->resolveEntityForCompany($user, $apiType, $commentableId, $companyId);

        $deleted = $this->itemsRepository->deleteItem($id, $user->id);

        if (! $deleted) {
            return $this->errorResponse('Комментарий не найден или нет прав', 404);
        }

        $this->invalidateTimelineCache($apiType, $commentableId);

        return $this->successResponse(null, 'Комментарий удалён');
    }

    /**
     * Получить таймлайн для сущности
     *
     * @param Request $request HTTP-запрос
     * @return \Illuminate\Http\JsonResponse
     */
    public function timeline(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        $request->validate([
            'type' => 'required|string',
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

            Log::info('comment.timeline.request', [
                'user_id' => (int) $user->id,
                'company_id' => $companyId,
                'type' => $apiType,
                'entity_id' => $entityId,
                'limit' => $limit,
                'has_cursor' => $cursor !== null,
            ]);

            $loadPage = function () use ($modelClass, $entityId, $companyId, $limit, $cursor) {
                return $this->timelineBuilder->buildPage($modelClass, $entityId, $companyId, $limit, $cursor);
            };

            if ($cursor === null) {
                $cacheKey = TimelineCache::page1Key($apiType, $entityId, $companyId);
                $page = CacheService::remember($cacheKey, $loadPage, 60);
            } else {
                $page = $loadPage();
            }

            Log::info('comment.timeline.response', [
                'user_id' => (int) $user->id,
                'company_id' => $companyId,
                'type' => $apiType,
                'entity_id' => $entityId,
                'items_count' => count($page['items'] ?? []),
                'has_more' => (bool) ($page['has_more'] ?? false),
            ]);

            return $this->successResponse($page);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse('Некорректный курсор таймлайна', 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            abort(404);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('comment.timeline.failed', [
                'user_id' => (int) ($user->id ?? 0),
                'company_id' => (int) ($this->getCurrentCompanyId() ?? 0),
                'type' => (string) ($request->type ?? ''),
                'entity_id' => (int) ($request->id ?? 0),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Ошибка загрузки таймлайна: '.$e->getMessage(), 500);
        }
    }

    /**
     * Получить количество непрочитанных комментариев по списку сущностей
     *
     * @param Request $request HTTP-запрос
     * @return \Illuminate\Http\JsonResponse
     */
    public function unreadCounts(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in([
                'order',
                'sale',
                'transaction',
                'client',
                'product',
                'project',
                'task',
                'project_contract',
                'lead',
                'wh_receipt',
                'wh_writeoff',
                'wh_movement',
                'wh_purchase',
            ])],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        $companyId = (int) $this->getCurrentCompanyId();
        if ($companyId < 1) {
            return $this->errorResponse('Company context is required', 422);
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
     * Пометить комментарии сущности как прочитанные для текущего пользователя
     *
     * @param Request $request HTTP-запрос
     * @return \Illuminate\Http\JsonResponse
     */
    public function markRead(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in([
                'order',
                'sale',
                'transaction',
                'client',
                'product',
                'project',
                'task',
                'project_contract',
                'lead',
                'wh_receipt',
                'wh_writeoff',
                'wh_movement',
                'wh_purchase',
            ])],
            'id' => ['required', 'integer', 'min:1'],
        ]);

        $companyId = (int) $this->getCurrentCompanyId();
        if ($companyId < 1) {
            return $this->errorResponse('Company context is required', 422);
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
     * Инвалидировать кэш таймлайна и комментариев по API-типу сущности
     *
     * @param string $apiType Короткий тип (order, client, …)
     * @param int $id ID сущности
     * @return void
     */
    private function invalidateTimelineCache(string $apiType, int $id): void
    {
        TimelineCache::forget($apiType, $id, (int) ($this->getCurrentCompanyId() ?? 0));
        $this->itemsRepository->invalidateCommentsCacheByType($apiType);
    }
}
