<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreWarehouseWriteoffRequest;
use App\Http\Requests\UpdateWarehouseWriteoffRequest;
use App\Http\Resources\WarehouseWriteoffResource;
use App\Repositories\WarehouseWriteoffRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы со списаниями со склада
 */
class WarehouseWriteoffController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     */
    public function __construct(WarehouseWriteoffRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список списаний с пагинацией
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);

        $warehouses = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $page);

        return $this->successResponse([
            'items' => WarehouseWriteoffResource::collection($warehouses->items())->resolve(),
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
     * Создать списание со склада
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreWarehouseWriteoffRequest $request)
    {
        $validatedData = $request->validated();

        $warehouseAccessCheck = $this->checkWarehouseAccess($validatedData['warehouse_id']);
        if ($warehouseAccessCheck) {
            return $warehouseAccessCheck;
        }

        $data = [
            'warehouse_id' => $validatedData['warehouse_id'],
            'note' => $validatedData['note'] ?? '',
            'products' => array_map(function ($product) {
                return [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                ];
            }, $validatedData['products']),
        ];

        try {
            $warehouse_created = $this->itemsRepository->createItem($data);
            if (! $warehouse_created) {
                return $this->errorResponse('Ошибка списания', 400);
            }

            return $this->successResponse(null, 'Списание создано');
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка списания: '.$th->getMessage(), 400);
        }
    }

    /**
     * Обновить списание со склада
     *
     * @param  Request  $request
     * @param  int  $id  ID списания
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateWarehouseWriteoffRequest $request, $id)
    {
        $validatedData = $request->validated();

        $warehouseAccessCheck = $this->checkWarehouseAccess($validatedData['warehouse_id']);
        if ($warehouseAccessCheck) {
            return $warehouseAccessCheck;
        }

        $data = [
            'warehouse_id' => $validatedData['warehouse_id'],
            'note' => $validatedData['note'] ?? '',
            'products' => array_map(function ($product) {
                return [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                ];
            }, $validatedData['products']),
        ];

        try {
            $warehouse_created = $this->itemsRepository->updateItem($id, $data);
            if (! $warehouse_created) {
                return $this->errorResponse('Ошибка списания', 400);
            }

            return $this->successResponse(null, 'Списание обновлено');
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка списания: '.$th->getMessage(), 400);
        }
    }

    /**
     * Удалить списание со склада
     *
     * @param  int  $id  ID списания
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $writeoff = \App\Models\WhWriteoff::findOrFail($id);

        if ($writeoff->warehouse_id) {
            $warehouseAccessCheck = $this->checkWarehouseAccess($writeoff->warehouse_id);
            if ($warehouseAccessCheck) {
                return $warehouseAccessCheck;
            }
        }

        $warehouse_deleted = $this->itemsRepository->deleteItem($id);

        if (! $warehouse_deleted) {
            return $this->errorResponse('Ошибка удаления списания', 400);
        }

        return $this->successResponse(null, 'Списание удалено');
    }
}
