<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\WarehouseReceiptRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WarehouseReceiptController extends Controller
{
    protected $warehouseRepository;

    public function __construct(WarehouseReceiptRepository $warehouseRepository)
    {
        $this->warehouseRepository = $warehouseRepository;
    }

    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $warehouses = $this->warehouseRepository->getItemsWithPagination($userUuid, 20);

        return $this->paginatedResponse($warehouses);
    }

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

            CacheService::invalidateWarehouseReceiptsCache();
            CacheService::invalidateWarehouseStocksCache();
            CacheService::invalidateProductsCache();
            CacheService::invalidateClientsCache();
            if ($request->project_id) {
                CacheService::invalidateProjectsCache();
            }

            return response()->json(['message' => 'Оприходование создано']);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка оприходования: ' . $th->getMessage(), 400);
        }
    }


    public function update(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'date' => 'nullable|date',
            'note' => 'nullable|string',
        ]);

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

            CacheService::invalidateWarehouseReceiptsCache();
            CacheService::invalidateWarehouseStocksCache();
            CacheService::invalidateProductsCache();
            CacheService::invalidateClientsCache();
            if ($request->project_id) {
                CacheService::invalidateProjectsCache();
            }

            return response()->json(['message' => 'Приходование обновлено']);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка обновления приходования: ' . $th->getMessage(), 400);
        }
    }

    public function destroy($id)
    {
        try {
            Log::info('WarehouseReceiptController::destroy - START', ['id' => $id]);

            $warehouse_deleted = $this->warehouseRepository->deleteItem($id);

            if (!$warehouse_deleted) {
                Log::warning('WarehouseReceiptController::destroy - deleteItem returned false', ['id' => $id]);
                return $this->errorResponse('Ошибка удаления оприходования', 400);
            }

            CacheService::invalidateWarehouseReceiptsCache();
            CacheService::invalidateWarehouseStocksCache();
            CacheService::invalidateProductsCache();
            CacheService::invalidateClientsCache();

            Log::info('WarehouseReceiptController::destroy - SUCCESS', ['id' => $id]);
            return response()->json(['message' => 'Оприходование удалено']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('WarehouseReceiptController::destroy - Not found', ['id' => $id]);
            return $this->notFoundResponse('Оприходование не найдено');
        } catch (\Throwable $th) {
            Log::error('WarehouseReceiptController::destroy error', [
                'id' => $id,
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);
            return $this->errorResponse('Ошибка удаления оприходования: ' . $th->getMessage(), 400);
        }
    }
}
