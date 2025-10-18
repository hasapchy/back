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
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        $warehouses = $this->warehouseRepository->getItemsWithPagination($userUuid, 20);

        // Логирование для отладки
        $items = $warehouses->items();
        if (count($items) > 0) {
            $firstItem = $items[0];
            Log::info('Первое приходование для отправки на фронт', [
                'id' => $firstItem->id,
                'supplier_id' => $firstItem->supplier_id,
                'has_supplier' => isset($firstItem->supplier),
                'supplier_name' => $firstItem->supplier ? ($firstItem->supplier->first_name . ' ' . $firstItem->supplier->last_name) : null,
                'has_products' => isset($firstItem->products),
                'products_count' => $firstItem->products ? count($firstItem->products) : 0,
                'has_cashRegister' => isset($firstItem->cashRegister),
                'cash_name' => $firstItem->cashRegister ? $firstItem->cashRegister->name : null,
            ]);
        }

        return response()->json([
            'items' => $warehouses->items(),
            'current_page' => $warehouses->currentPage(),
            'next_page' => $warehouses->nextPageUrl(),
            'last_page' => $warehouses->lastPage(),
            'total' => $warehouses->total()
        ]);
    }

    public function store(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
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

        // Логирование полученных данных
        Log::info('Создание приходования', [
            'client_id' => $data['client_id'],
            'warehouse_id' => $data['warehouse_id'],
            'type' => $data['type'],
            'products_count' => count($data['products']),
            'products' => $data['products']
        ]);

        try {
            $warehouse_created = $this->warehouseRepository->createItem($data);
            if (!$warehouse_created) {
                Log::error('Ошибка: createItem вернул false');
                return response()->json([
                    'message' => 'Ошибка оприходования'
                ], 400);
            }

            Log::info('Приходование успешно создано');

            // Инвалидируем кэш приходов и остатков
            CacheService::invalidateWarehouseReceiptsCache();
            CacheService::invalidateWarehouseStocksCache();

            return response()->json([
                'message' => 'Оприходование создано'
            ]);
        } catch (\Throwable $th) {
            Log::error('Ошибка при создании приходования', [
                'message' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Ошибка оприходования: ' . $th->getMessage()
            ], 400);
        }
    }


    public function destroy($id)
    {
        $warehouse_deleted = $this->warehouseRepository->deleteItem($id);

        if (!$warehouse_deleted) {
            return response()->json([
                'message' => 'Ошибка удаления склада'
            ], 400);
        }

        // Инвалидируем кэш приходов и остатков
        CacheService::invalidateWarehouseReceiptsCache();
        CacheService::invalidateWarehouseStocksCache();

        return response()->json([
            'message' => 'Склад удален'
        ]);
    }
}
