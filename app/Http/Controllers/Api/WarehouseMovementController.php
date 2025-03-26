<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\WarehouseMovementRepository;
use Illuminate\Http\Request;

class WarehouseMovementController extends Controller
{
    protected $warehouseRepository;

    public function __construct(WarehouseMovementRepository $warehouseRepository)
    {
        $this->warehouseRepository = $warehouseRepository;
    }

    // Метод для получения списаний с пагинацией
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

    // Метод перемещения товара
    public function store(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Валидация данных
        $request->validate([
            'warehouse_from_id' => 'required|integer|exists:warehouses,id',
            'warehouse_to_id' => 'required|integer|exists:warehouses,id',
            'date' => 'nullable|date',
            'note' => 'nullable|string',
            'products' => 'required|array',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:0'
        ]);

        $data = array(
            'warehouse_from_id' => $request->warehouse_from_id,
            'warehouse_to_id' => $request->warehouse_to_id,
            'date' => $request->date ?? now(),
            'note' => $request->note ?? '',
            'products' => array_map(function ($product) {
                return [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity']
                ];
            }, $request->products)
        );

        // Перемещаем
        try {
            $warehouse_created = $this->warehouseRepository->createMovement($data);
            if (!$warehouse_created) {
                return response()->json([
                    'message' => 'Ошибка перемещения'
                ], 400);
            }
            return response()->json([
                'message' => 'Перемещение создано'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Ошибка перемещения' . $th->getMessage()
            ], 400);
        }
    }

    // Метод обновления перемещения
    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Валидация данных
        $request->validate([
            'warehouse_from_id' => 'required|integer|exists:warehouses,id',
            'warehouse_to_id' => 'required|integer|exists:warehouses,id',
            'date' => 'nullable|date',
            'note' => 'nullable|string',
            'products' => 'required|array',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:0'
        ]);

        $data = array(
            'warehouse_from_id' => $request->warehouse_from_id,
            'warehouse_to_id' => $request->warehouse_to_id,
            'date' => $request->date ?? now(),
            'note' => $request->note ?? '',
            'products' => array_map(function ($product) {
                return [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity']
                ];
            }, $request->products)
        );

        // Обновляем перемещение
        try {
            $warehouse_created = $this->warehouseRepository->updateMovement($id, $data);
            if (!$warehouse_created) {
                return response()->json([
                    'message' => 'Ошибка обновления перемещения'
                ], 400);
            }
            return response()->json([
                'message' => 'Перемещение обновлено'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Ошибка обновления перемещения' . $th->getMessage()
            ], 400);
        }
    }

    // Метод для удаления перемещения
    public function destroy($id)
    {
        // Удаляем перемещение
        $warehouse_deleted = $this->warehouseRepository->deleteMovement($id);

        if (!$warehouse_deleted) {
            return response()->json([
                'message' => 'Ошибка удаления перемещения'
            ], 400);
        }
        return response()->json([
            'message' => 'Перемещение удалено'
        ]);
    }
}
