<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreOrderStatusCategoryRequest;
use App\Http\Requests\UpdateOrderStatusCategoryRequest;
use App\Http\Resources\OrderStatusCategoryReferenceResource;
use App\Http\Resources\OrderStatusCategoryResource;
use App\Repositories\OrderStatusCategoryRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с категориями статусов заказов
 */
/**
 * @group Заказы
 * @subgroup Категории статусов
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
     * Список категорий статусов заказов
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);
        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $page);
        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse([
            'items' => $this->wave1IndexCollection(
                $items->items(),
                OrderStatusCategoryReferenceResource::class,
                OrderStatusCategoryResource::class,
                $companyId
            ),
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

        $useReference = $this->useReferenceContractsForWave1All($this->getCurrentCompanyId());
        $collection = $useReference
            ? OrderStatusCategoryReferenceResource::collection($items)
            : OrderStatusCategoryResource::collection($items);

        return $this->successResponse($collection->resolve());
    }

    /**
     * Создать категорию статусов заказов
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
            return $this->errorResponse(__('Ошибка создания категории статусов'), 400);
        }

        return $this->successResponse(null, __('Категория статусов создана'));
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

        return $this->successResponse(null, __('Категория статусов обновлена'));
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
            return $this->errorResponse(__('api.transfers.delete_failed'), 400);
        }

        return $this->successResponse(null, __('Категория статусов удалена'));
    }
}
