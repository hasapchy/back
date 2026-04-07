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

/**
 * Контроллер для работы с комментариями
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
     * Создать новый комментарий
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

        $comment = $this->itemsRepository->createItem(
            $validatedData['type'],
            $validatedData['id'],
            $validatedData['body'],
            $user->id
        );

        $this->invalidateTimelineCache($validatedData['type'], (int) $validatedData['id']);

        return $this->successResponse([
            'message' => 'Комментарий добавлен',
            'comment' => (new CommentResource($comment))->resolve(),
        ]);
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
            $cacheKey = TimelineCache::key($apiType, $entityId);

            return CacheService::remember($cacheKey, function () use ($modelClass, $entityId) {
                return $this->timelineBuilder->build($modelClass, $entityId);
            }, 600);
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка загрузки таймлайна: ' . $e->getMessage(), 500);
        }
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
        TimelineCache::forget($apiType, $id);
        $this->itemsRepository->invalidateCommentsCacheByType($apiType);
    }
}
