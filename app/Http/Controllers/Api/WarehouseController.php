<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreWarehouseRequest;
use App\Http\Requests\UpdateWarehouseRequest;
use App\Http\Resources\WarehouseResource;
use App\Models\Warehouse;
use App\Repositories\WarehouseRepository;
use Illuminate\Http\Request;
use App\Services\CacheService;

/**
 * Контроллер для работы со складами
 */
class WarehouseController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     *
     * @param WarehouseRepository $itemsRepository
     */
    public function __construct(WarehouseRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список складов с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Warehouse::class);

        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);
        $warehouses = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $page);

        return $this->successResponse([
            'items' => WarehouseResource::collection($warehouses->items())->resolve(),
            'meta' => [
                'current_page' => $warehouses->currentPage(),
                'next_page' => $warehouses->nextPageUrl(),
                'last_page' => $warehouses->lastPage(),
                'per_page' => $warehouses->perPage(),
                'total' => $warehouses->total(),
            ],
        ]);
    }

    /**
     * Получить все склады
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $this->authorize('viewAny', Warehouse::class);

        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $warehouses = $this->itemsRepository->getAllItems($userUuid);

        return $this->successResponse(WarehouseResource::collection($warehouses)->resolve());
    }

    /**
     * Создать новый склад
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreWarehouseRequest $request)
    {
        $this->authorize('create', Warehouse::class);

        $validatedData = $request->validated();

        $warehouse_created = $this->itemsRepository->createItem($validatedData['name'], $validatedData['users']);

        if (!$warehouse_created) {
            return $this->errorResponse('Ошибка создания склада', 400);
        }

        return $this->successResponse(new WarehouseResource($warehouse_created), 'Склад создан');
    }

    /**
     * Обновить склад
     *
     * @param Request $request
     * @param int $id ID склада
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateWarehouseRequest $request, $id)
    {
        $warehouse = \App\Models\Warehouse::findOrFail($id);

        $this->authorize('update', $warehouse);

        $validatedData = $request->validated();

        $warehouse_updated = $this->itemsRepository->updateItem($id, $validatedData['name'], $validatedData['users']);

        if (!$warehouse_updated) {
            return $this->errorResponse('Ошибка обновления склада', 400);
        }

        return $this->successResponse(new WarehouseResource($warehouse_updated), 'Склад обновлен');
    }

    /**
     * Удалить склад
     *
     * @param int $id ID склада
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $warehouse = \App\Models\Warehouse::findOrFail($id);

        $this->authorize('delete', $warehouse);

        $warehouse_deleted = $this->itemsRepository->deleteItem($id);

        if (!$warehouse_deleted) {
            return $this->errorResponse('Ошибка удаления склада', 400);
        }

        return $this->successResponse(null, 'Склад удален');
    }
}
