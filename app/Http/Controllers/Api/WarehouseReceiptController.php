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

        try {
            $warehouse_created = $this->warehouseRepository->createItem($data);
            if (!$warehouse_created) {
                return response()->json([
                    'message' => 'Ошибка оприходования'
                ], 400);
            }

            // Инвалидируем кэш приходов, остатков и продуктов (т.к. stock_quantity изменился)
            CacheService::invalidateWarehouseReceiptsCache();
            CacheService::invalidateWarehouseStocksCache();
            CacheService::invalidateProductsCache();
            // Инвалидируем кэш клиентов (баланс поставщика/клиента изменился)
            CacheService::invalidateClientsCache();
            // Инвалидируем кэш проектов (если оприходование привязано к проекту)
            if ($request->project_id) {
                CacheService::invalidateProjectsCache();
            }

            return response()->json([
                'message' => 'Оприходование создано'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Ошибка оприходования: ' . $th->getMessage()
            ], 400);
        }
    }


    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

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
                return response()->json([
                    'message' => 'Ошибка обновления приходования'
                ], 400);
            }

            // Инвалидируем кэш приходов, остатков и продуктов (т.к. stock_quantity изменился)
            CacheService::invalidateWarehouseReceiptsCache();
            CacheService::invalidateWarehouseStocksCache();
            CacheService::invalidateProductsCache();
            // Инвалидируем кэш клиентов (баланс поставщика/клиента мог измениться)
            CacheService::invalidateClientsCache();
            // Инвалидируем кэш проектов (если оприходование привязано к проекту)
            if ($request->project_id) {
                CacheService::invalidateProjectsCache();
            }

            return response()->json([
                'message' => 'Приходование обновлено'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Ошибка обновления приходования: ' . $th->getMessage()
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

        // Инвалидируем кэш приходов, остатков и продуктов (т.к. stock_quantity изменился)
        CacheService::invalidateWarehouseReceiptsCache();
        CacheService::invalidateWarehouseStocksCache();
        CacheService::invalidateProductsCache();
        // Инвалидируем кэш клиентов (баланс поставщика/клиента изменился)
        CacheService::invalidateClientsCache();

        return response()->json([
            'message' => 'Склад удален'
        ]);
    }
}
