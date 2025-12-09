<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\StoreWarehouseReceiptRequest;
use App\Http\Requests\UpdateWarehouseReceiptRequest;
use App\Repositories\WarehouseReceiptRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с оприходованиями на склад
 */
class WarehouseReceiptController extends BaseController
{
    protected $warehouseRepository;

    /**
     * Конструктор контроллера
     *
     * @param WarehouseReceiptRepository $warehouseRepository
     */
    public function __construct(WarehouseReceiptRepository $warehouseRepository)
    {
        $this->warehouseRepository = $warehouseRepository;
    }

    /**
     * Получить список оприходований с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);

        $warehouses = $this->warehouseRepository->getItemsWithPagination($userUuid, $perPage);

        return $this->paginatedResponse($warehouses);
    }

    /**
     * Получить оприходование по ID
     *
     * @param int $id ID оприходования
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $item = $this->warehouseRepository->getItemById($id, $userUuid);
        if (!$item) {
            return $this->notFoundResponse('Оприходование не найдено');
        }

        return response()->json(['item' => $item]);
    }

    /**
     * Создать оприходование на склад
     *
     * @param Request $request
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

        $data = array(
            'client_id' => $validatedData['client_id'],
            'warehouse_id' => $validatedData['warehouse_id'],
            'type'        => $validatedData['type'],
            'cash_id'     => $validatedData['cash_id'] ?? null,
            'user_id' => $userUuid,
            'date' => $validatedData['date'] ?? now(),
            'note' => $validatedData['note'] ?? '',
            'project_id' => $validatedData['project_id'] ?? null,
            'products' => array_map(function ($product) {
                return [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                    'price' => $product['price']
                ];
            }, $validatedData['products'])
        );

        try {
            $warehouse_created = $this->warehouseRepository->createItem($data);
            if (!$warehouse_created) {
                return $this->errorResponse('Ошибка оприходования', 400);
            }

            return response()->json(['message' => 'Оприходование создано']);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка оприходования: ' . $th->getMessage(), 400);
        }
    }

    /**
     * Обновить оприходование на склад
     *
     * @param Request $request
     * @param int $id ID оприходования
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateWarehouseReceiptRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $receipt = $this->warehouseRepository->getItemById($id, $userUuid);
        if (!$receipt) {
            return $this->notFoundResponse('Оприходование не найдено');
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
            $updated = $this->warehouseRepository->updateReceipt($id, $data);
            if (!$updated) {
                return $this->errorResponse('Ошибка обновления приходования', 400);
            }

            return response()->json(['message' => 'Приходование обновлено']);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка обновления приходования: ' . $th->getMessage(), 400);
        }
    }

    /**
     * Удалить оприходование со склада
     *
     * @param int $id ID оприходования
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $warehouse_deleted = $this->warehouseRepository->deleteItem($id);

            if (!$warehouse_deleted) {
                return $this->errorResponse('Ошибка удаления оприходования', 400);
            }

            return response()->json(['message' => 'Оприходование удалено']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Оприходование не найдено');
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка удаления оприходования: ' . $th->getMessage(), 400);
        }
    }
}
