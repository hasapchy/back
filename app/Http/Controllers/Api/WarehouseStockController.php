<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\WarehouseStockRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с остатками на складах
 */
class WarehouseStockController extends Controller
{
    protected $warehouseRepository;

    /**
     * Конструктор контроллера
     *
     * @param WarehouseStockRepository $warehouseRepository
     */
    public function __construct(WarehouseStockRepository $warehouseRepository)
    {
        $this->warehouseRepository = $warehouseRepository;
    }

    /**
     * Получить список остатков на складах с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = (int)$request->input('page', 1);
        $perPage = (int)$request->input('per_page', 20);
        $warehouse_id = $request->query('warehouse_id');
        $category_id = $request->query('category_id');
        $search = $request->query('search');
        $availability = $request->query('availability');

        $warehouses = $this->warehouseRepository->getItemsWithPagination(
            $userUuid,
            $perPage,
            $warehouse_id,
            $category_id,
            $page,
            $search,
            $availability
        );

        return $this->paginatedResponse($warehouses);
    }
}
