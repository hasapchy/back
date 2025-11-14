<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\RolesRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class RolesController extends Controller
{
    protected $itemsRepository;

    public function __construct(RolesRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    public function index(Request $request)
    {
        $page = $request->input('page', 1);
        $search = $request->input('search');
        return $this->paginatedResponse($this->itemsRepository->getItemsWithPagination($page, 20, $search));
    }

    public function all()
    {
        return response()->json($this->itemsRepository->getAllItems());
    }

    public function show($id)
    {
        $role = $this->itemsRepository->getItem($id);
        return response()->json($role);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:roles,name,NULL,id,guard_name,api',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name,guard_name,api',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $role = $this->itemsRepository->createItem($request->all());

        CacheService::invalidateUsersCache();

        return response()->json([
            'message' => 'Роль создана успешно',
            'role' => $role
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:roles,name,' . $id . ',id,guard_name,api',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name,guard_name,api',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $role = $this->itemsRepository->updateItem($id, $request->all());

        CacheService::invalidateUsersCache();

        return response()->json([
            'message' => 'Роль обновлена успешно',
            'role' => $role
        ]);
    }

    public function destroy($id)
    {
        try {
            $this->itemsRepository->deleteItem($id);
            CacheService::invalidateUsersCache();
            return response()->json(['message' => 'Роль удалена успешно']);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}

