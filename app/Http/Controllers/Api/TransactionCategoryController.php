<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransactionCategoryRequest;
use App\Http\Requests\UpdateTransactionCategoryRequest;
use App\Http\Resources\TransactionCategoryResource;
use App\Models\TransactionCategory;
use App\Repositories\TransactionCategoryRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с категориями транзакций
 */
class TransactionCategoryController extends Controller
{
    protected $transactionCategoryRepository;

    /**
     * Конструктор контроллера
     *
     * @param TransactionCategoryRepository $transactionCategoryRepository
     */
    public function __construct(TransactionCategoryRepository $transactionCategoryRepository)
    {
        $this->transactionCategoryRepository = $transactionCategoryRepository;
    }

    /**
     * Получить список категорий транзакций с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $items = $this->transactionCategoryRepository->getItemsWithPagination(20);

        return TransactionCategoryResource::collection($items)->response();
    }

    /**
     * Получить все категории транзакций
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $items = $this->transactionCategoryRepository->getAllItems();

        return TransactionCategoryResource::collection($items)->response();
    }

    /**
     * Создать новую категорию транзакций
     *
     * @param StoreTransactionCategoryRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreTransactionCategoryRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $created = $this->transactionCategoryRepository->createItem([
            'name' => $request->name,
            'type' => $request->type,
            'user_id' => $userUuid,
        ]);

        if (!$created) return $this->errorResponse('Ошибка создания категории транзакции', 400);

        $category = TransactionCategory::with('user')->findOrFail($created->id);
        return $this->dataResponse(new TransactionCategoryResource($category), 'Категория транзакции создана');
    }

    /**
     * Обновить категорию транзакций
     *
     * @param UpdateTransactionCategoryRequest $request
     * @param int $id ID категории
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateTransactionCategoryRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        try {
            $updated = $this->transactionCategoryRepository->updateItem($id, [
                'name' => $request->name,
                'type' => $request->type,
                'user_id' => $userUuid,
            ]);

            if (!$updated) return $this->notFoundResponse('Категория транзакции не найдена');

            $category = TransactionCategory::with('user')->findOrFail($id);
            return $this->dataResponse(new TransactionCategoryResource($category), 'Категория транзакции обновлена');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * Удалить категорию транзакций
     *
     * @param int $id ID категории
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        try {
            $category = TransactionCategory::with('user')->findOrFail($id);
            $deleted = $this->transactionCategoryRepository->deleteItem($id);
            if (!$deleted) return $this->notFoundResponse('Категория транзакции не найдена');

            return $this->dataResponse(new TransactionCategoryResource($category), 'Категория транзакции удалена');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
