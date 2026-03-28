<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\WarehouseStockResource;
use App\Repositories\WarehouseStockRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с остатками на складах
 */
class WarehouseStockController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     *
     * @param WarehouseStockRepository $itemsRepository
     */
    public function __construct(WarehouseStockRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
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

        $warehouses = $this->itemsRepository->getItemsWithPagination(
            $userUuid,
            $perPage,
            $warehouse_id,
            $category_id,
            $page,
            $search,
            $availability
        );

        return $this->successResponse([
            'items' => WarehouseStockResource::collection($warehouses->items())->resolve(),
            'meta' => [
                'current_page' => $warehouses->currentPage(),
                'next_page' => $warehouses->nextPageUrl(),
                'last_page' => $warehouses->lastPage(),
                'per_page' => $warehouses->perPage(),
                'total' => $warehouses->total(),
            ],
        ]);
    }
}
