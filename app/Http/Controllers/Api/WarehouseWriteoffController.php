<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\WarehouseWriteoffRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

class WarehouseWriteoffController extends Controller
{
    protected $warehouseRepository;

    public function __construct(WarehouseWriteoffRepository $warehouseRepository)
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
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'note' => 'nullable|string',
            'products' => 'required|array',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:0'
        ]);

        $data = array(
            'warehouse_id' => $request->warehouse_id,
            'note' => $request->note ?? '',
            'products' => array_map(function ($product) {
                return [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity']
                ];
            }, $request->products)
        );

        try {
            $warehouse_created = $this->warehouseRepository->createItem($data);
            if (!$warehouse_created) {
                return $this->errorResponse('Ошибка списания', 400);
            }

            CacheService::invalidateWarehouseWriteoffsCache();
            CacheService::invalidateWarehouseStocksCache();
            CacheService::invalidateProductsCache();

            return response()->json(['message' => 'Списание создано']);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка списания' . $th->getMessage(), 400);
        }
    }

    public function update(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'note' => 'nullable|string',
            'products' => 'required|array',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:0'
        ]);

        $data = array(
            'warehouse_id' => $request->warehouse_id,
            'note' => $request->note ?? '',
            'products' => array_map(function ($product) {
                return [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity']
                ];
            }, $request->products)
        );

        try {
            $warehouse_created = $this->warehouseRepository->updateItem($id, $data);
            if (!$warehouse_created) {
                return $this->errorResponse('Ошибка списания', 400);
            }

            CacheService::invalidateWarehouseWriteoffsCache();
            CacheService::invalidateWarehouseStocksCache();
            CacheService::invalidateProductsCache();

            return response()->json(['message' => 'Списание обновлено']);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка списания' . $th->getMessage(), 400);
        }
    }

    public function destroy($id)
    {
        $warehouse_deleted = $this->warehouseRepository->deleteItem($id);

        if (!$warehouse_deleted) {
            return $this->errorResponse('Ошибка удаления списания', 400);
        }

        CacheService::invalidateWarehouseWriteoffsCache();
        CacheService::invalidateWarehouseStocksCache();
        CacheService::invalidateProductsCache();

        return response()->json(['message' => 'Списание удалено']);
    }
}
