<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreWarehouseReceiptRequest;
use App\Http\Requests\UpdateWarehouseReceiptRequest;
use App\Http\Resources\WarehouseReceiptResource;
use App\Repositories\WarehouseReceiptRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с оприходованиями на склад
 */
class WarehouseReceiptController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     */
    public function __construct(WarehouseReceiptRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список оприходований с пагинацией
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);
        $clientId = $request->input('client_id');

        $warehouses = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $page, $clientId);

        return $this->successResponse([
            'items' => WarehouseReceiptResource::collection($warehouses->items())->resolve(),
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
     * Получить оприходование по ID
     *
     * @param  int  $id  ID оприходования
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $item = $this->itemsRepository->getItemById($id, $userUuid);
        if (! $item) {
            return $this->errorResponse('Оприходование не найдено', 404);
        }

        return $this->successResponse(new WarehouseReceiptResource($item));
    }

    /**
     * Создать оприходование на склад
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreWarehouseReceiptRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $warehouseAccessCheck = $this->checkWarehouseAccess($validatedData['warehouse_id']);
        if ($warehouseAccessCheck) {
            return $warehouseAccessCheck;
        }

        $cashAccessCheck = $this->checkCashRegisterAccess($validatedData['cash_id'] ?? null);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        $data = [
            'client_id' => $validatedData['client_id'],
            'warehouse_id' => $validatedData['warehouse_id'],
            'type' => $validatedData['type'],
            'cash_id' => $validatedData['cash_id'] ?? null,
            'creator_id' => $userUuid,
            'date' => $validatedData['date'] ?? now(),
            'note' => $validatedData['note'] ?? '',
            'project_id' => $validatedData['project_id'] ?? null,
            'products' => array_map(function ($product) {
                return [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                    'price' => $product['price'],
                ];
            }, $validatedData['products']),
        ];

        try {
            $warehouse_created = $this->itemsRepository->createItem($data);
            if (! $warehouse_created) {
                return $this->errorResponse('Ошибка оприходования', 400);
            }

            return $this->successResponse(null, 'Оприходование создано');
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка оприходования: '.$th->getMessage(), 400);
        }
    }

    /**
     * Обновить оприходование на склад
     *
     * @param  Request  $request
     * @param  int  $id  ID оприходования
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateWarehouseReceiptRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $receipt = $this->itemsRepository->getItemById($id, $userUuid);
        if (! $receipt) {
            return $this->errorResponse('Оприходование не найдено', 404);
        }

        $data = [
            'client_id' => $receipt->client_id,
            'warehouse_id' => $receipt->warehouse_id,
            'cash_id' => $receipt->cash_id,
            'date' => $validatedData['date'] ?? $receipt->date,
            'note' => $validatedData['note'] ?? $receipt->note,
            'products' => $receipt->products,
            'project_id' => $receipt->project_id,
        ];

        try {
            $updated = $this->itemsRepository->updateReceipt($id, $data);
            if (! $updated) {
                return $this->errorResponse('Ошибка обновления приходования', 400);
            }

            return $this->successResponse(null, 'Приходование обновлено');
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка обновления приходования: '.$th->getMessage(), 400);
        }
    }

    /**
     * Удалить оприходование со склада
     *
     * @param  int  $id  ID оприходования
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $warehouse_deleted = $this->itemsRepository->deleteItem($id);

            if (! $warehouse_deleted) {
                return $this->errorResponse('Ошибка удаления оприходования', 400);
            }

            return $this->successResponse(null, 'Оприходование удалено');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Оприходование не найдено', 404);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка удаления оприходования: '.$th->getMessage(), 400);
        }
    }
}
