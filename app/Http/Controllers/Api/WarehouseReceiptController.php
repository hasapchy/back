<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\WarehouseReceiptRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с оприходованиями на склад
 */
class WarehouseReceiptController extends Controller
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

        $warehouses = $this->warehouseRepository->getItemsWithPagination($userUuid, 20);

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
    public function store(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'type'         => 'required|in:cash,balance',
            'cash_id'      => 'nullable|integer|exists:cash_registers,id',
            'date' => 'nullable|date',
            'note' => 'nullable|string',
            'project_id' => 'nullable|integer|exists:projects,id',
            'products' => 'required|array',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:0',
            'products.*.price' => 'required|numeric|min:0'
        ]);

        $warehouseAccessCheck = $this->checkWarehouseAccess($request->warehouse_id);
        if ($warehouseAccessCheck) {
            return $warehouseAccessCheck;
        }

        $cashAccessCheck = $this->checkCashRegisterAccess($request->cash_id);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        $data = array(
            'client_id' => $request->client_id,
            'warehouse_id' => $request->warehouse_id,
            'type'        => $request->type,
            'cash_id'     => $request->cash_id,
            'user_id' => $userUuid,
            'date' => $request->date ?? now(),
            'note' => $request->note ?? '',
            'project_id' => $request->project_id ?? null,
            'products' => array_map(function ($product) {
                return [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                    'price' => $product['price']
                ];
            }, $request->products)
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
    public function update(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'date' => 'nullable|date',
            'note' => 'nullable|string',
        ]);

        $receipt = $this->warehouseRepository->getItemById($id, $userUuid);
        if (!$receipt) {
            return $this->notFoundResponse('Оприходование не найдено');
        }

        $warehouseAccessCheck = $this->checkWarehouseAccess($request->warehouse_id);
        if ($warehouseAccessCheck) {
            return $warehouseAccessCheck;
        }

        $cashAccessCheck = $this->checkCashRegisterAccess($request->cash_id);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        $data = [
            'client_id' => $request->client_id,
            'warehouse_id' => $request->warehouse_id,
            'cash_id' => $request->cash_id,
            'date' => $request->date,
            'note' => $request->note,
            'products' => $request->products,
            'project_id' => $request->project_id ?? null,
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
