<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreWarehouseRequest;
use App\Http\Requests\UpdateWarehouseRequest;
use App\Http\Resources\WarehouseReferenceResource;
use App\Http\Resources\WarehouseResource;
use App\Models\Warehouse;
use App\Repositories\WarehouseRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы со складами
 *
 * @group Склады
 * @subgroup Склады
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
     * Список складов
     *
     * @param Request $request
     * @response 200 {"data":{"items":[],"meta":{"current_page":1,"next_page":null,"last_page":1,"per_page":20,"total":0}}}
     * @response 401 {"error":"Unauthenticated."}
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Warehouse::class);

        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);
        $warehouses = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $page);

        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse([
            'items' => $this->wave1IndexCollection(
                $warehouses->items(),
                WarehouseReferenceResource::class,
                WarehouseResource::class,
                $companyId
            ),
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
     * Все склады
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $this->authorize('viewAny', Warehouse::class);

        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $warehouses = $this->itemsRepository->getAllItems($userUuid);

        $useReference = $this->useReferenceContractsForWave1All($this->getCurrentCompanyId());
        $collection = $useReference
            ? WarehouseReferenceResource::collection($warehouses)
            : WarehouseResource::collection($warehouses);

        return $this->successResponse($collection->resolve());
    }

    /**
     * Создать склад
     *
     * @param Request $request
     * @response 200 {"data":{"id":1},"message":"Склад создан"}
     * @response 401 {"error":"Unauthenticated."}
     * @response 422 {"error":"The given data was invalid.","errors":{"name":["The name field is required."]}}
     *
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

        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse(
            $this->wave1SingleResource($warehouse_created, WarehouseReferenceResource::class, WarehouseResource::class, $companyId),
            'Склад создан'
        );
    }

    /**
     * Изменить склад
     *
     * @param Request $request
     * @param int $id ID склада
     * @response 200 {"data":{"id":1},"message":"Склад обновлен"}
     * @response 401 {"error":"Unauthenticated."}
     * @response 404 {"error":"Not found"}
     * @response 422 {"error":"The given data was invalid.","errors":{"name":["The name field is required."]}}
     *
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

        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse(
            $this->wave1SingleResource($warehouse_updated, WarehouseReferenceResource::class, WarehouseResource::class, $companyId),
            'Склад обновлен'
        );
    }

    /**
     * Удалить склад
     *
     * @param int $id ID склада
     * @response 200 {"data":null,"message":"Склад удален"}
     * @response 401 {"error":"Unauthenticated."}
     * @response 404 {"error":"Not found"}
     *
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
