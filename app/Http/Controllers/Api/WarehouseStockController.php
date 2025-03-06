<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\WarehouseStockRepository;
use Illuminate\Http\Request;

class WarehouseStockController extends Controller
{
    protected $warehouseRepository;

    public function __construct(WarehouseStockRepository $warehouseRepository)
    {
        $this->warehouseRepository = $warehouseRepository;
    }

    // Метод для получения стоков с пагинацией
    public function index(Request $request)
    {

        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        $warehouse_id = $request->query('warehouse_id');
        $category_id = $request->query('category_id');

        // Получаем сток с пагинацией
        $warehouses = $this->warehouseRepository->getItemsWithPagination($userUuid, 20, $warehouse_id, $category_id);

        return response()->json([
            'items' => $warehouses->items(),  // Список 
            'current_page' => $warehouses->currentPage(),  // Текущая страница
            'next_page' => $warehouses->nextPageUrl(),  // Следующая страница
            'last_page' => $warehouses->lastPage(),  // Общее количество страниц
            'total' => $warehouses->total()  // Общее количество
        ]);
    }

    // // Метод для создания склада

    // public function store(Request $request)
    // {
    //     // Валидация данных
    //     $request->validate([
    //         'name' => 'required|string',
    //         'users' => 'required|array',
    //         'users.*' => 'exists:users,id'
    //     ]);

    //     // Создаем склад
    //     $warehouse_created = $this->warehouseRepository->createWarehouse($request->name, $request->users);

    //     if (!$warehouse_created) {
    //         return response()->json([
    //             'message' => 'Ошибка создания склада'
    //         ], 400);
    //     }
    //     return response()->json([
    //         'message' => 'Склад создан'
    //     ]);
    // }

    // // Метод для обновления склада
    // public function update(Request $request, $id)
    // {
    //     // Валидация данных
    //     $request->validate([
    //         'name' => 'required|string',
    //         'users' => 'required|array',
    //         'users.*' => 'exists:users,id'
    //     ]);

    //     // Обновляем склад
    //     $warehouse_updated = $this->warehouseRepository->updateWarehouse($id, $request->name, $request->users);

    //     if (!$warehouse_updated) {
    //         return response()->json([
    //             'message' => 'Ошибка обновления склада'
    //         ], 400);
    //     }
    //     return response()->json([
    //         'message' => 'Склад обновлен'
    //     ]);
    // }

    // // Метод для удаления склада
    // public function destroy($id)
    // {
    //     // Удаляем склад
    //     $warehouse_deleted = $this->warehouseRepository->deleteWarehouse($id);

    //     if (!$warehouse_deleted) {
    //         return response()->json([
    //             'message' => 'Ошибка удаления склада'
    //         ], 400);
    //     }
    //     return response()->json([
    //         'message' => 'Склад удален'
    //     ]);
    // }
}
