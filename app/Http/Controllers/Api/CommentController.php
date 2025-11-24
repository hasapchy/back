<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GetCommentsRequest;
use App\Http\Requests\GetTimelineRequest;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Requests\UpdateCommentRequest;
use App\Http\Resources\CommentResource;
use App\Repositories\CommentsRepository;
use App\Services\CacheService;
use App\Services\TimelineService;
use Illuminate\Http\Request;
use App\Models\Comment;

/**
 * Контроллер для работы с комментариями
 */
class CommentController extends Controller
{
    protected CommentsRepository $itemsRepository;

    /**
     * @var TimelineService
     */
    protected $timelineService;

    /**
     * Конструктор контроллера
     *
     * @param CommentsRepository $itemsRepository
     * @param TimelineService $timelineService
     */
    public function __construct(CommentsRepository $itemsRepository, TimelineService $timelineService)
    {
        $this->itemsRepository = $itemsRepository;
        $this->timelineService = $timelineService;
    }

    /**
     * Получить список комментариев для сущности
     *
     * @param GetCommentsRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(GetCommentsRequest $request)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->unauthorizedResponse();
        }

        $comments = $this->itemsRepository->getCommentsFor($request->type, $request->id);
        return CommentResource::collection($comments)->response();
    }

    /**
     * Создать новый комментарий
     *
     * @param StoreCommentRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreCommentRequest $request)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->unauthorizedResponse();
        }

        $comment = $this->itemsRepository->createItem($request->type, $request->id, $request->body, $user->id);
        $comment = Comment::with('user')->findOrFail($comment->id);

        $this->invalidateTimelineCache($request->type, $request->id);

        return $this->dataResponse(new CommentResource($comment), 'Комментарий добавлен');
    }

    /**
     * Обновить комментарий
     *
     * @param UpdateCommentRequest $request
     * @param int $id ID комментария
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateCommentRequest $request, $id)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->unauthorizedResponse();
        }

        $updatedComment = $this->itemsRepository->updateItem($id, $user->id, $request->body);

        if (! $updatedComment) {
            return response()->json(['message' => 'Комментарий не найден или нет прав'], 403);
        }

        $this->invalidateTimelineCache($updatedComment->commentable_type, $updatedComment->commentable_id);
        $comment = Comment::with('user')->findOrFail($id);

        return $this->dataResponse(new CommentResource($comment), 'Комментарий обновлён');
    }

    /**
     * Удалить комментарий
     *
     * @param int $id ID комментария
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $user = auth('api')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $comment = Comment::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$comment) {
            return $this->forbiddenResponse('Комментарий не найден или нет прав');
        }

        $deleted = $this->itemsRepository->deleteItem($id, $user->id);

        if (!$deleted) {
            return $this->forbiddenResponse('Комментарий не найден или нет прав');
        }

        $this->invalidateTimelineCache($comment->commentable_type, $comment->commentable_id);

        return $this->dataResponse(new CommentResource($comment), 'Комментарий удалён');
    }

    /**
     * Получить таймлайн для сущности
     *
     * @param GetTimelineRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function timeline(GetTimelineRequest $request)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->unauthorizedResponse();
        }

        try {
            $modelClass = $this->itemsRepository->resolveType($request->type);
            $cacheKey = "timeline_{$request->type}_{$request->id}";

            $timeline = CacheService::remember($cacheKey, function () use ($modelClass, $request) {
                return $this->timelineService->buildTimeline($modelClass, $request->id);
            }, 600);

            return $this->dataResponse($timeline);

        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка загрузки таймлайна: ' . $e->getMessage(), 500);
        }
    }


    /**
     * Инвалидировать кэш таймлайна
     *
     * @param string $type Тип сущности
     * @param int $id ID сущности
     * @return void
     */
    private function invalidateTimelineCache(string $type, int $id)
    {
        $cacheKey = "timeline_{$type}_{$id}";
        CacheService::forget($cacheKey);

        $this->itemsRepository->invalidateCommentsCacheByType($type);
    }

}
