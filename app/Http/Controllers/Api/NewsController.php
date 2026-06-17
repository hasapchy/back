<?php

namespace App\Http\Controllers\Api;

use App\Events\NewsAcknowledgedUpdated;
use App\Events\NewsCreated;
use App\Events\NewsViewedUpdated;
use App\Services\EngagementReactionService;
use App\Http\Requests\StoreNewsRequest;
use App\Http\Requests\UpdateNewsRequest;
use App\Http\Resources\NewsResource;
use App\Models\News;
use App\Repositories\NewsRepository;
use App\Services\InAppNotifications\InAppNotificationDispatcher;
use App\Services\NewsImageService;
use App\Services\Timeline\TimelineEntityAccessGuard;
use Illuminate\Http\Request;

/**
 * @group Контент
 * @subgroup Новости
 */
class NewsController extends BaseController
{
    protected $itemsRepository;

    protected $imageService;

    public function __construct(
        NewsRepository $itemsRepository,
        NewsImageService $imageService,
        private readonly InAppNotificationDispatcher $inAppNotificationDispatcher,
        private readonly EngagementReactionService $engagementReactionService,
        private readonly TimelineEntityAccessGuard $timelineAccessGuard,
    ) {
        $this->itemsRepository = $itemsRepository;
        $this->imageService = $imageService;
    }

    /**
     * Список новостей
     */
    public function index(Request $request)
    {
        $userId = (int) ($this->getAuthenticatedUserIdOrFail() ?? 0);

        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 20);
        $search = $request->input('search');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $authorId = $request->input('author_id');
        $companyId = (int) ($this->getCurrentCompanyId() ?? 0);

        $items = $this->itemsRepository->getItemsWithPagination($perPage, $page, $search, $dateFrom, $dateTo, $authorId);
        $this->itemsRepository->attachReactionSummaries($items->items());
        $this->itemsRepository->attachViewedBy($items->items(), $companyId);
        $this->itemsRepository->attachAcknowledgedBy($items->items(), $companyId);
        $this->itemsRepository->attachAcknowledgedByCurrentUser($items->items(), $userId, $companyId);

        return $this->successResponse([
            'items' => NewsResource::collection($items->items())->resolve(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * Получить все новости
     */
    public function all(Request $request)
    {
        $userId = (int) ($this->getAuthenticatedUserIdOrFail() ?? 0);
        $companyId = (int) ($this->getCurrentCompanyId() ?? 0);
        $items = $this->itemsRepository->getAllItems();
        $this->itemsRepository->attachReactionSummaries($items);
        $this->itemsRepository->attachViewedBy($items, $companyId);
        $this->itemsRepository->attachAcknowledgedBy($items, $companyId);
        $this->itemsRepository->attachAcknowledgedByCurrentUser($items, $userId, $companyId);

        return $this->successResponse(NewsResource::collection($items)->resolve());
    }

    /**
     * Создать новость
     */
    public function store(StoreNewsRequest $request)
    {
        $userId = $this->getAuthenticatedUserIdOrFail();

        $this->authorize('create', News::class);

        $validatedData = $request->validated();

        // Обрабатываем изображения ДО сохранения (без ID новости)
        // Это предотвращает ошибку "MySQL server has gone away" из-за больших base64 данных
        $processedContent = $this->imageService->processImages($validatedData['content']);

        $itemData = [
            'title' => $validatedData['title'],
            'content' => $processedContent,
            'creator_id' => $userId,
            'is_important' => (bool) ($validatedData['is_important'] ?? false),
        ];

        try {
            // Создаем новость с уже обработанными изображениями
            $itemCreated = $this->itemsRepository->createItem($itemData);

            if (! $itemCreated) {
                return $this->errorResponse(__('Ошибка создания новости'), 400);
            }

            // После создания перемещаем изображения в папку с ID новости и обновляем контент
            $organizedContent = $this->imageService->organizeImagesByNewsId($processedContent, $itemCreated->id);
            if ($organizedContent !== $processedContent) {
                $this->itemsRepository->updateItem($itemCreated->id, ['content' => $organizedContent]);
            }

            $companyId = (int) $this->getCurrentCompanyId();
            if ($companyId > 0) {
                $this->inAppNotificationDispatcher->dispatch(
                    $companyId,
                    'news_new',
                    (int) $userId,
                    'Новость',
                    $itemCreated->title,
                    ['route' => '/news', 'news_id' => $itemCreated->id]
                );
                $this->dispatchNewsCreatedBroadcast((int) $itemCreated->id, $companyId);
            }

            return $this->successResponse(null, __('Новость создана'));
        } catch (\Exception $e) {
            return $this->errorResponse(__('Ошибка создания новости: ').$e->getMessage(), 500);
        }
    }

    /**
     * Обновить новость
     */
    public function update(UpdateNewsRequest $request, $id)
    {
        $user = $this->requireAuthenticatedUser();
        $news = News::findOrFail($id);

        $this->authorize('update', $news);

        $validatedData = $request->validated();

        // Получаем старый контент для очистки неиспользуемых изображений
        $oldContent = $news->content;

        // Обрабатываем изображения в новом контенте
        $processedContent = $this->imageService->processImages($validatedData['content'], $id);

        $itemData = [
            'title' => $validatedData['title'],
            'content' => $processedContent,
            'is_important' => (bool) ($validatedData['is_important'] ?? $news->is_important),
        ];

        try {
            $itemUpdated = $this->itemsRepository->updateItem($id, $itemData);

            // Удаляем неиспользуемые изображения
            if ($itemUpdated) {
                $this->imageService->cleanupUnusedImages($oldContent, $processedContent, $id);
            }

            if (! $itemUpdated) {
                return $this->errorResponse(__('Ошибка обновления новости'), 400);
            }

            return $this->successResponse(null, __('Новость обновлена'));
        } catch (\Exception $e) {
            return $this->errorResponse(__('Ошибка обновления новости: ').$e->getMessage(), 500);
        }
    }

    /**
     * Получить новость по ID
     */
    public function show($id)
    {
        try {
            $user = $this->requireAuthenticatedUser();
            $news = $this->itemsRepository->findItemWithRelations($id);

            if (! $news) {
                return $this->errorResponse(__('Новость не найдена или доступ запрещен'), 404);
            }

            $this->itemsRepository->attachReactionSummaries([$news]);
            $companyId = (int) ($this->getCurrentCompanyId() ?? 0);
            $this->itemsRepository->attachViewedBy([$news], $companyId);
            $this->itemsRepository->attachAcknowledgedBy([$news], $companyId);
            $this->itemsRepository->attachAcknowledgedByCurrentUser([$news], (int) $user->id, $companyId);

            return $this->successResponse(new NewsResource($news));
        } catch (\Exception $e) {
            return $this->errorResponse(__('Ошибка при получении новости: ').$e->getMessage(), 500);
        }
    }

    /**
     * Удалить новость
     */
    public function destroy($id)
    {
        $this->requireAuthenticatedUser();
        $news = News::findOrFail($id);

        $this->authorize('delete', $news);

        try {
            // Удаляем все изображения новости перед удалением самой новости
            $this->imageService->deleteNewsImages($id);

            $deleted = $this->itemsRepository->deleteItem($id);

            if (! $deleted) {
                return $this->errorResponse(__('Ошибка удаления новости'), 400);
            }

            return $this->successResponse(null, __('Новость удалена'));
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function setReaction(Request $request, int $id)
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) ($this->getCurrentCompanyId() ?? 0);
        $this->timelineAccessGuard->resolveEntityForCompany($user, 'news', $id, $companyId);

        $validated = $request->validate([
            'emoji' => 'nullable|string|max:16',
        ]);

        $emoji = $validated['emoji'] ?? null;
        $reactions = $this->engagementReactionService->setNewsReaction(
            $companyId,
            $id,
            (int) $user->id,
            $emoji
        );

        return $this->successResponse(['reactions' => $reactions]);
    }

    /**
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function markViewed(int $id)
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) ($this->getCurrentCompanyId() ?? 0);
        $this->timelineAccessGuard->resolveEntityForCompany($user, 'news', $id, $companyId);

        $viewedAt = $this->itemsRepository->markViewed($id, (int) $user->id, $companyId);

        if ($companyId > 0) {
            $news = $this->itemsRepository->findItemWithRelations($id);
            if ($news) {
                $this->itemsRepository->attachViewedBy([$news], $companyId);
                NewsViewedUpdated::dispatch(
                    $companyId,
                    $id,
                    is_array($news->viewed_by) ? $news->viewed_by : [],
                );
            }
        }

        return $this->successResponse([
            'viewed_at' => $viewedAt,
        ]);
    }

    /**
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function acknowledge(int $id)
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) ($this->getCurrentCompanyId() ?? 0);
        $news = $this->timelineAccessGuard->resolveEntityForCompany($user, 'news', $id, $companyId);

        if (! $news instanceof News) {
            return $this->errorResponse(__('Новость не найдена или доступ запрещен'), 404);
        }

        if (! $news->is_important) {
            return $this->errorResponse(__('Подтверждение нужно только для важной новости'), 422);
        }

        $ackAt = $this->itemsRepository->acknowledgeImportant($id, (int) $user->id, $companyId);

        if ($companyId > 0) {
            $news = $this->itemsRepository->findItemWithRelations($id);
            if ($news) {
                $this->itemsRepository->attachAcknowledgedBy([$news], $companyId);
                NewsAcknowledgedUpdated::dispatch(
                    $companyId,
                    $id,
                    is_array($news->acknowledged_by) ? $news->acknowledged_by : [],
                );
            }
        }

        return $this->successResponse([
            'acknowledged_at' => $ackAt,
        ]);
    }

    /**
     * @param int $newsId
     * @param int $companyId
     * @return void
     */
    private function dispatchNewsCreatedBroadcast(int $newsId, int $companyId): void
    {
        $news = $this->itemsRepository->findItemWithRelations($newsId);
        if (! $news) {
            return;
        }

        $this->itemsRepository->attachReactionSummaries([$news]);
        $this->itemsRepository->attachViewedBy([$news], $companyId);
        $this->itemsRepository->attachAcknowledgedBy([$news], $companyId);

        NewsCreated::dispatch($companyId, (new NewsResource($news))->resolve());
    }
}
