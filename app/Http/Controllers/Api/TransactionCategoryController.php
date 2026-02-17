<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\StoreTransactionCategoryRequest;
use App\Http\Requests\UpdateTransactionCategoryRequest;
use App\Repositories\TransactionCategoryRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с категориями транзакций
 */
class TransactionCategoryController extends BaseController
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

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);

        $items = $this->transactionCategoryRepository->getItemsWithPagination($perPage, $page);

        $mappedItems = collect($items->items())->map(function ($item) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                'type' => $item->type,
                'creator_id' => $item->creator_id,
                'user_name' => $item->creator ? $item->creator->name : null,
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
            ];
        });

        return response()->json([
            'items' => $mappedItems,
            'current_page' => $items->currentPage(),
            'next_page' => $items->nextPageUrl(),
            'last_page' => $items->lastPage(),
            'total' => $items->total()
        ]);
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

        return response()->json($items->map(function ($item) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                'type' => $item->type,
                'creator_id' => $item->creator_id,
                'user_name' => $item->creator ? $item->creator->name : null,
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
            ];
        }));
    }

    /**
     * Создать новую категорию транзакций
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreTransactionCategoryRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $created = $this->transactionCategoryRepository->createItem([
            'name' => $validatedData['name'],
            'type' => $validatedData['type'],
            'creator_id' => $userUuid,
        ]);

        if (!$created) return $this->errorResponse('Ошибка создания категории транзакции', 400);

        return response()->json(['message' => 'Категория транзакции создана']);
    }

    /**
     * Обновить категорию транзакций
     *
     * @param Request $request
     * @param int $id ID категории
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateTransactionCategoryRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        try {
            $updated = $this->transactionCategoryRepository->updateItem($id, [
                'name' => $validatedData['name'],
                'type' => $validatedData['type'],
                'creator_id' => $userUuid,
            ]);

            if (!$updated) return $this->notFoundResponse('Категория транзакции не найдена');

            return response()->json(['message' => 'Категория транзакции обновлена']);
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
            $deleted = $this->transactionCategoryRepository->deleteItem($id);
            if (!$deleted) return $this->notFoundResponse('Категория транзакции не найдена');

            return response()->json(['message' => 'Категория транзакции удалена']);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
