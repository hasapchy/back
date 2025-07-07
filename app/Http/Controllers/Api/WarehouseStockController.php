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
}
