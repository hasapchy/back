<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\WarehouseReceiptRepository;
use Illuminate\Http\Request;

class WarehouseReceiptController extends Controller
{
    protected $warehouseRepository;

    public function __construct(WarehouseReceiptRepository $warehouseRepository)
    {
        $this->warehouseRepository = $warehouseRepository;
    }

    // Метод для получения оприходований с пагинацией
    public function index(Request $request)
    {

        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        // Получаем сток с пагинацией
        $warehouses = $this->warehouseRepository->getItemsWithPagination($userUuid, 20);

        return response()->json([
            'items' => $warehouses->items(),  // Список 
            'current_page' => $warehouses->currentPage(),  // Текущая страница
            'next_page' => $warehouses->nextPageUrl(),  // Следующая страница
            'last_page' => $warehouses->lastPage(),  // Общее количество страниц
            'total' => $warehouses->total()  // Общее количество
        ]);
    }

    // Метод оприходования товара

    public function store(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Валидация данных
        $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'type'         => 'required|in:cash,balance',
            'cash_id'      => 'nullable|integer|exists:cash_registers,id',
            'date' => 'nullable|date',
            'note' => 'nullable|string',
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
            // 'currency_id' => $request->currency_id,
            'date' => $request->date ?? now(),
            'note' => $request->note ?? '',
            'products' => array_map(function ($product) {
                return [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                    'price' => $product['price']
                ];
            }, $request->products)
        );

        // Оприходуем
        try {
            $warehouse_created = $this->warehouseRepository->createItem($data);
            if (!$warehouse_created) {
                return response()->json([
                    'message' => 'Ошибка оприходования'
                ], 400);
            }
            return response()->json([
                'message' => 'Оприходование создано'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Ошибка оприходования' . $th->getMessage()
            ], 400);
        }
    }

    // // Метод для обновления оприходования
    // public function update(Request $request, $id)
    // {
    //     $userUuid = optional(auth('api')->user())->id;
    //     if (!$userUuid) {
    //         return response()->json(array('message' => 'Unauthorized'), 401);
    //     }
    //     // Валидация данных
    //     $request->validate([
    //         'client_id' => 'required|integer|exists:clients,id',
    //         'warehouse_id' => 'required|integer|exists:warehouses,id',
    //         'type'         => 'required|in:cash,balance',
    //         'cash_id'      => 'nullable|integer|exists:cash_registers,id',
    //         'currency_id'  => 'nullable|integer|exists:currencies,id',
    //         'date' => 'nullable|date',
    //         'note' => 'nullable|string',
    //         'products' => 'required|array',
    //         'products.*.product_id' => 'required|integer|exists:products,id',
    //         'products.*.quantity' => 'required|numeric|min:0',
    //         'products.*.price' => 'required|numeric|min:0'
    //     ]);

    //     $data = array(
    //         'client_id' => $request->client_id,
    //         'warehouse_id' => $request->warehouse_id,
    //         'type'        => $request->type,
    //         'cash_id'     => $request->cash_id,
    //         'currency_id' => $request->currency_id,  
    //         'date' => $request->date ?? now(),
    //         'note' => $request->note ?? '',
    //         'products' => array_map(function ($product) {
    //             return [
    //                 'product_id' => $product['product_id'],
    //                 'quantity' => $product['quantity'],
    //                 'price' => $product['price']
    //             ];
    //         }, $request->products)
    //     );

    //     // Оприходуем с обновлением
    //     try {
    //         $warehouse_created = $this->warehouseRepository->updateReceipt($id, $data);
    //         if (!$warehouse_created) {
    //             return response()->json([
    //                 'message' => 'Ошибка обновления оприходования'
    //             ], 400);
    //         }
    //         return response()->json([
    //             'message' => 'Оприходование обновлено'
    //         ]);
    //     } catch (\Throwable $th) {
    //         return response()->json([
    //             'message' => 'Ошибка обновления оприходования' . $th->getMessage()
    //         ], 400);
    //     }
    // }


    // Метод для удаления склада
    public function destroy($id)
    {
        // Удаляем склад
        $warehouse_deleted = $this->warehouseRepository->deleteItem($id);

        if (!$warehouse_deleted) {
            return response()->json([
                'message' => 'Ошибка удаления склада'
            ], 400);
        }
        return response()->json([
            'message' => 'Склад удален'
        ]);
    }
}
