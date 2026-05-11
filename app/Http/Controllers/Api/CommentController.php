<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreCommentRequest;
use App\Http\Requests\UpdateCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Repositories\CommentsRepository;
use App\Services\CacheService;
use App\Services\Timeline\TimelineBuilder;
use App\Services\Timeline\TimelineCache;
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
     */
    public function __construct(
        protected CommentsRepository $itemsRepository,
        protected TimelineBuilder $timelineBuilder
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

        Log::info('comment.store.request', [
            'user_id' => (int) $user->id,
            'company_id' => $companyId,
            'type' => (string) ($validatedData['type'] ?? ''),
            'entity_id' => (int) ($validatedData['id'] ?? 0),
            'body_length' => strlen((string) ($validatedData['body'] ?? '')),
        ]);

        try {
            $comment = $this->itemsRepository->createItem(
                $validatedData['type'],
                $validatedData['id'],
                $validatedData['body'],
                $user->id
            );

            $this->invalidateTimelineCache($validatedData['type'], (int) $validatedData['id']);

            Log::info('comment.store.success', [
                'user_id' => (int) $user->id,
                'company_id' => $companyId,
                'type' => (string) $validatedData['type'],
                'entity_id' => (int) $validatedData['id'],
                'comment_id' => (int) ($comment['id'] ?? 0),
            ]);

            return $this->successResponse([
                'message' => 'Комментарий добавлен',
                'comment' => (new CommentResource($comment))->resolve(),
            ]);
        } catch (\Throwable $e) {
            Log::error('comment.store.failed', [
                'user_id' => (int) $user->id,
                'company_id' => $companyId,
                'type' => (string) ($validatedData['type'] ?? ''),
                'entity_id' => (int) ($validatedData['id'] ?? 0),
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

        $this->invalidateTimelineCache(
            $this->itemsRepository->apiTypeFromModelClass($updatedComment->commentable_type),
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
        ]);

        try {
            $modelClass = $this->itemsRepository->resolveType($request->type);
            $apiType = $request->type;
            $entityId = (int) $request->id;
            $companyId = (int) ($this->getCurrentCompanyId() ?? 0);
            $cacheKey = TimelineCache::key($apiType, $entityId, $companyId);

            Log::info('comment.timeline.request', [
                'user_id' => (int) $user->id,
                'company_id' => $companyId,
                'type' => (string) $apiType,
                'entity_id' => $entityId,
            ]);

            $timeline = CacheService::remember($cacheKey, function () use ($modelClass, $entityId, $companyId) {
                return $this->timelineBuilder->build($modelClass, $entityId, $companyId);
            }, 600);

            Log::info('comment.timeline.response', [
                'user_id' => (int) $user->id,
                'company_id' => $companyId,
                'type' => (string) $apiType,
                'entity_id' => $entityId,
                'items_count' => method_exists($timeline, 'count') ? (int) $timeline->count() : (is_countable($timeline) ? count($timeline) : 0),
            ]);

            return $timeline;
        } catch (\Throwable $e) {
            Log::error('comment.timeline.failed', [
                'user_id' => (int) ($user->id ?? 0),
                'company_id' => (int) ($this->getCurrentCompanyId() ?? 0),
                'type' => (string) ($request->type ?? ''),
                'entity_id' => (int) ($request->id ?? 0),
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Ошибка загрузки таймлайна: ' . $e->getMessage(), 500);
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
            ])],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        $companyId = (int) $this->getCurrentCompanyId();
        if ($companyId < 1) {
            return $this->errorResponse('Company context is required', 422);
        }

        $counts = $this->itemsRepository->getUnreadCountsForEntities(
            $validated['type'],
            $validated['ids'],
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
            ])],
            'id' => ['required', 'integer', 'min:1'],
        ]);

        $companyId = (int) $this->getCurrentCompanyId();
        if ($companyId < 1) {
            return $this->errorResponse('Company context is required', 422);
        }

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
