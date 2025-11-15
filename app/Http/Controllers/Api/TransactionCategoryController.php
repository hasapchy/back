<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        $mappedItems = collect($items->items())->map(function ($item) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                'type' => $item->type,
                'user_id' => $item->user_id,
                'user_name' => $item->user ? $item->user->name : null,
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
                'user_id' => $item->user_id,
                'user_name' => $item->user ? $item->user->name : null,
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
    public function store(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string',
            'type' => 'required|boolean',
        ]);

        $created = $this->transactionCategoryRepository->createItem([
            'name' => $request->name,
            'type' => $request->type,
            'user_id' => $userUuid,
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
    public function update(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string',
            'type' => 'required|boolean',
        ]);

        try {
            $updated = $this->transactionCategoryRepository->updateItem($id, [
                'name' => $request->name,
                'type' => $request->type,
                'user_id' => $userUuid,
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
