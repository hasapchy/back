<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreTransactionCategoryRequest;
use App\Http\Requests\UpdateTransactionCategoryRequest;
use App\Http\Resources\TransactionCategoryResource;
use App\Repositories\TransactionCategoryRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с категориями транзакций
 */
class TransactionCategoryController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     *
     * @param TransactionCategoryRepository $itemsRepository
     */
    public function __construct(TransactionCategoryRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список категорий транзакций с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $this->getAuthenticatedUserIdOrFail();

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);

        $items = $this->itemsRepository->getItemsWithPagination($perPage, $page);

        return $this->successResponse([
            'items' => TransactionCategoryResource::collection($items->items())->resolve(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'next_page' => $items->nextPageUrl(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * Получить все категории транзакций
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function all()
    {
        $items = $this->itemsRepository->getAllItems();

        return $this->successResponse(TransactionCategoryResource::collection($items)->resolve());
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
        $validatedData = $request->validated();

        $this->itemsRepository->createItem([
            'name' => $validatedData['name'],
            'type' => $validatedData['type'],
            'creator_id' => $userUuid,
            'parent_id' => $validatedData['parent_id'],
        ]);

        return $this->successResponse(null, 'Категория транзакции создана');
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
        $validatedData = $request->validated();

        try {
            $payload = [
                'name' => $validatedData['name'],
                'type' => $validatedData['type'],
                'creator_id' => $userUuid,
            ];
            if (array_key_exists('parent_id', $validatedData)) {
                $payload['parent_id'] = $validatedData['parent_id'];
            }
            $this->itemsRepository->updateItem($id, $payload);

            return $this->successResponse(null, 'Категория транзакции обновлена');
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
        $this->getAuthenticatedUserIdOrFail();

        try {
            $this->itemsRepository->deleteItem($id);

            return $this->successResponse(null, 'Категория транзакции удалена');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
