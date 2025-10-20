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

        $page = (int)$request->input('page', 1);
        $perPage = (int)$request->input('per_page', 20);
        $warehouse_id = $request->query('warehouse_id');
        $search = $request->query('search');
        // category_id больше не поддерживается, так как столбец был удален из products

        // Получаем сток с пагинацией
        $warehouses = $this->warehouseRepository->getItemsWithPagination($userUuid, $perPage, $warehouse_id, null, $page, $search);

        return response()->json([
            'items' => $warehouses->items(),
            'current_page' => $warehouses->currentPage(),
            'next_page' => $warehouses->nextPageUrl(),
            'last_page' => $warehouses->lastPage(),
            'total' => $warehouses->total()
        ]);
    }
}
