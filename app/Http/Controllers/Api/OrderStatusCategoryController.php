<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreOrderStatusCategoryRequest;
use App\Http\Requests\UpdateOrderStatusCategoryRequest;
use App\Http\Resources\OrderStatusCategoryResource;
use App\Repositories\OrderStatusCategoryRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с категориями статусов заказов
 */
class OrderStatusCategoryController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     */
    public function __construct(OrderStatusCategoryRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список категорий статусов заказов с пагинацией
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);
        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $page);

        return $this->successResponse([
            'items' => OrderStatusCategoryResource::collection($items->items())->resolve(),
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
     * Получить все категории статусов заказов
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $items = $this->itemsRepository->getAllItems($userUuid);

        return $this->successResponse(OrderStatusCategoryResource::collection($items)->resolve());
    }

    /**
     * Создать новую категорию статусов заказов
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreOrderStatusCategoryRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $created = $this->itemsRepository->createItem([
            'name' => $validatedData['name'],
            'color' => $validatedData['color'] ?? '#6c757d',
            'creator_id' => $userUuid,
        ]);
        if (! $created) {
            return $this->errorResponse('Ошибка создания категории статусов', 400);
        }

        return $this->successResponse(null, 'Категория статусов создана');
    }

    /**
     * Обновить категорию статусов заказов
     *
     * @param  int  $id  ID категории
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateOrderStatusCategoryRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $updateData = ['name' => $validatedData['name']];
        if (isset($validatedData['color'])) {
            $updateData['color'] = $validatedData['color'];
        }

        $this->itemsRepository->updateItem($id, $updateData);

        return $this->successResponse(null, 'Категория статусов обновлена');
    }

    /**
     * Удалить категорию статусов заказов
     *
     * @param  int  $id  ID категории
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $deleted = $this->itemsRepository->deleteItem($id);
        if (! $deleted) {
            return $this->errorResponse('Ошибка удаления', 400);
        }

        return $this->successResponse(null, 'Категория статусов удалена');
    }
}
