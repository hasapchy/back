<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreNewsRequest;
use App\Http\Requests\UpdateNewsRequest;
use App\Models\News;
use App\Repositories\NewsRepository;
use App\Services\NewsImageService;
use Illuminate\Http\Request;

class NewsController extends BaseController
{
    protected $itemsRepository;

    protected $imageService;

    public function __construct(NewsRepository $itemsRepository, NewsImageService $imageService)
    {
        $this->itemsRepository = $itemsRepository;
        $this->imageService = $imageService;
    }

    /**
     * Получить список новостей с пагинацией
     */
    public function index(Request $request)
    {
        $userId = $this->getAuthenticatedUserIdOrFail();

        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 20);
        $search = $request->input('search');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $authorId = $request->input('author_id');

        $items = $this->itemsRepository->getItemsWithPagination($perPage, $page, $search, $dateFrom, $dateTo, $authorId);

        return $this->paginatedResponse($items);
    }

    /**
     * Получить все новости
     */
    public function all(Request $request)
    {
        $userId = $this->getAuthenticatedUserIdOrFail();
        $items = $this->itemsRepository->getAllItems();

        return response()->json($items);
    }

    /**
     * Создать новость
     */
    public function store(StoreNewsRequest $request)
    {
        $userId = $this->getAuthenticatedUserIdOrFail();

        if (! $this->hasPermission('news_create')) {
            return $this->forbiddenResponse('У вас нет прав на создание новостей');
        }

        $validatedData = $request->validated();

        // Обрабатываем изображения ДО сохранения (без ID новости)
        // Это предотвращает ошибку "MySQL server has gone away" из-за больших base64 данных
        $processedContent = $this->imageService->processImages($validatedData['content']);

        $itemData = [
            'title' => $validatedData['title'],
            'content' => $processedContent,
            'user_id' => $userId,
        ];

        try {
            // Создаем новость с уже обработанными изображениями
            $itemCreated = $this->itemsRepository->createItem($itemData);

            if (! $itemCreated) {
                return $this->errorResponse('Ошибка создания новости', 400);
            }

            // После создания перемещаем изображения в папку с ID новости и обновляем контент
            $organizedContent = $this->imageService->organizeImagesByNewsId($processedContent, $itemCreated->id);
            if ($organizedContent !== $processedContent) {
                $this->itemsRepository->updateItem($itemCreated->id, ['content' => $organizedContent]);
            }

            return response()->json(['message' => 'Новость создана']);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка создания новости: '.$e->getMessage(), 500);
        }
    }

    /**
     * Обновить новость
     */
    public function update(UpdateNewsRequest $request, $id)
    {
        $user = $this->requireAuthenticatedUser();
        $news = News::findOrFail($id);

        if (! $this->canPerformAction('news', 'update', $news)) {
            return $this->forbiddenResponse('У вас нет прав на редактирование этой новости');
        }

        $validatedData = $request->validated();

        // Получаем старый контент для очистки неиспользуемых изображений
        $oldContent = $news->content;

        // Обрабатываем изображения в новом контенте
        $processedContent = $this->imageService->processImages($validatedData['content'], $id);

        $itemData = [
            'title' => $validatedData['title'],
            'content' => $processedContent,
        ];

        try {
            $itemUpdated = $this->itemsRepository->updateItem($id, $itemData);

            // Удаляем неиспользуемые изображения
            if ($itemUpdated) {
                $this->imageService->cleanupUnusedImages($oldContent, $processedContent, $id);
            }

            if (! $itemUpdated) {
                return $this->errorResponse('Ошибка обновления новости', 400);
            }

            return response()->json(['message' => 'Новость обновлена']);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка обновления новости: '.$e->getMessage(), 500);
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
                return $this->notFoundResponse('Новость не найдена или доступ запрещен');
            }

            return response()->json(['item' => $news]);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении новости: '.$e->getMessage(), 500);
        }
    }

    /**
     * Удалить новость
     */
    public function destroy($id)
    {
        $this->requireAuthenticatedUser();
        $news = News::findOrFail($id);

        if (! $this->canPerformAction('news', 'delete', $news)) {
            return $this->forbiddenResponse('У вас нет прав на удаление этой новости');
        }

        try {
            // Удаляем все изображения новости перед удалением самой новости
            $this->imageService->deleteNewsImages($id);

            $deleted = $this->itemsRepository->deleteItem($id);

            if (! $deleted) {
                return $this->errorResponse('Ошибка удаления новости', 400);
            }

            return response()->json(['message' => 'Новость удалена']);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
