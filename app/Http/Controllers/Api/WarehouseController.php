<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\WarehouseRepository;
use Illuminate\Http\Request;
use App\Services\CacheService;

class WarehouseController extends Controller
{
    protected $warehouseRepository;

    public function __construct(WarehouseRepository $warehouseRepository)
    {
        $this->warehouseRepository = $warehouseRepository;
    }

    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = $request->input('page', 1);
        $warehouses = $this->warehouseRepository->getWarehousesWithPagination($userUuid, 20, $page);

        return $this->paginatedResponse($warehouses);
    }
    public function all(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $warehouses = $this->warehouseRepository->getAllItems($userUuid);

        return response()->json($warehouses);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'users' => 'required|array',
            'users.*' => 'exists:users,id'
        ]);

        $warehouse_created = $this->warehouseRepository->createItem($request->name, $request->users);

        if (!$warehouse_created) {
            return $this->errorResponse('Ошибка создания склада', 400);
        }

        CacheService::invalidateWarehousesCache();

        return response()->json(['warehouse' => $warehouse_created, 'message' => 'Склад создан']);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string',
            'users' => 'required|array',
            'users.*' => 'exists:users,id'
        ]);

        $warehouse_updated = $this->warehouseRepository->updateItem($id, $request->name, $request->users);

        if (!$warehouse_updated) {
            return $this->errorResponse('Ошибка обновления склада', 400);
        }

        CacheService::invalidateWarehousesCache();

        return response()->json(['warehouse' => $warehouse_updated, 'message' => 'Склад обновлен']);
    }

    public function destroy($id)
    {
        $warehouse_deleted = $this->warehouseRepository->deleteItem($id);

        if (!$warehouse_deleted) {
            return $this->errorResponse('Ошибка удаления склада', 400);
        }

        CacheService::invalidateWarehousesCache();

        return response()->json(['message' => 'Склад удален']);
    }
}
