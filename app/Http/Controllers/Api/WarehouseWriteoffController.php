<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\WarehouseWriteoffRepository;
use Illuminate\Http\Request;

class WarehouseWriteoffController extends Controller
{
    protected $warehouseRepository;

    public function __construct(WarehouseWriteoffRepository $warehouseRepository)
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

    // Метод списания товара
    public function store(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Валидация данных
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

        // Списываем
        try {
            $warehouse_created = $this->warehouseRepository->createItem($data);
            if (!$warehouse_created) {
                return response()->json([
                    'message' => 'Ошибка списания'
                ], 400);
            }
            return response()->json([
                'message' => 'Списание создано'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Ошибка списания' . $th->getMessage()
            ], 400);
        }
    }

    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Валидация данных
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

        // Оприходуем
        try {
            $warehouse_created = $this->warehouseRepository->updateItem($id, $data);
            if (!$warehouse_created) {
                return response()->json([
                    'message' => 'Ошибка списания'
                ], 400);
            }
            return response()->json([
                'message' => 'Списание обновлено'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Ошибка списания' . $th->getMessage()
            ], 400);
        }
    }


    // // Метод для удаления cписания
    public function destroy($id)
    {
        // Удаляем склад
        $warehouse_deleted = $this->warehouseRepository->deleteItem($id);

        if (!$warehouse_deleted) {
            return response()->json([
                'message' => 'Ошибка удаления списания'
            ], 400);
        }
        return response()->json([
            'message' => 'Списание удалено'
        ]);
    }
}
