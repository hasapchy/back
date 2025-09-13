<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\WarehouseRepository;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    protected $warehouseRepository;

    public function __construct(WarehouseRepository $warehouseRepository)
    {
        $this->warehouseRepository = $warehouseRepository;
    }

    // Метод для получения складов с пагинацией
    public function index(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        $page = $request->input('page', 1);

        // Получаем склад с пагинацией
        $warehouses = $this->warehouseRepository->getWarehousesWithPagination($userUuid, 20, $page);

        return response()->json([
            'items' => $warehouses->items(),  // Список складов
            'current_page' => $warehouses->currentPage(),  // Текущая страница
            'next_page' => $warehouses->nextPageUrl(),  // Следующая страница
            'last_page' => $warehouses->lastPage(),  // Общее количество страниц
            'total' => $warehouses->total()  // Общее количество складов
        ]);
    }
    // Метод для получения всех складов
    public function all(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;

        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        // Получаем склады
        $warehouses = $this->warehouseRepository->getAllWarehouses($userUuid);

        return response()->json($warehouses);
    }

    // Метод для создания склада

    public function store(Request $request)
    {
        // Валидация данных
        $request->validate([
            'name' => 'required|string',
            'users' => 'required|array',
            'users.*' => 'exists:users,id'
        ]);

        // Создаем склад
        $warehouse_created = $this->warehouseRepository->createItem($request->name, $request->users);

        if (!$warehouse_created) {
            return response()->json([
                'message' => 'Ошибка создания склада'
            ], 400);
        }
        return response()->json([
            'message' => 'Склад создан',
            'warehouse' => $warehouse_created
        ]);
    }

    // Метод для обновления склада
    public function update(Request $request, $id)
    {
        // Валидация данных
        $request->validate([
            'name' => 'required|string',
            'users' => 'required|array',
            'users.*' => 'exists:users,id'
        ]);

        // Обновляем склад
        $warehouse_updated = $this->warehouseRepository->updateItem($id, $request->name, $request->users);

        if (!$warehouse_updated) {
            return response()->json([
                'message' => 'Ошибка обновления склада'
            ], 400);
        }
        return response()->json([
            'message' => 'Склад обновлен',
            'warehouse' => $warehouse_updated
        ]);
    }

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
