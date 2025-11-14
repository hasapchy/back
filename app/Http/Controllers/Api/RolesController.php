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
        try {
            $role = $this->itemsRepository->getItem($id);
            return response()->json($role);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Роль не найдена', 404);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении роли', 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $data = $request->all();

            if (isset($data['name'])) {
                $data['name'] = trim($data['name']);
                if (empty($data['name'])) {
                    return $this->errorResponse('Название роли не может быть пустым', 422);
                }
            }

            $validator = Validator::make($data, [
                'name' => 'required|string|max:255|unique:roles,name,NULL,id,guard_name,api',
                'permissions' => 'nullable|array|max:1000',
                'permissions.*' => 'string|exists:permissions,name,guard_name,api',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator);
            }

            $role = $this->itemsRepository->createItem($data);

            CacheService::invalidateUsersCache();

            return response()->json([
                'message' => 'Роль создана успешно',
                'role' => $role
            ], 201);
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->errorResponse('Ошибка при создании роли: ' . $e->getMessage(), 500);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $data = $request->all();

            $rules = [
                'permissions' => 'nullable|array|max:1000',
                'permissions.*' => 'string|exists:permissions,name,guard_name,api',
            ];

            if (isset($data['name'])) {
                $data['name'] = trim($data['name']);
                if (empty($data['name'])) {
                    return $this->errorResponse('Название роли не может быть пустым', 422);
                }
                $rules['name'] = 'required|string|max:255|unique:roles,name,' . $id . ',id,guard_name,api';
            }

            $validator = Validator::make($data, $rules);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator);
            }

            $role = $this->itemsRepository->updateItem($id, $data);

            CacheService::invalidateUsersCache();

            return response()->json([
                'message' => 'Роль обновлена успешно',
                'role' => $role
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Роль не найдена', 404);
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->errorResponse('Ошибка при обновлении роли: ' . $e->getMessage(), 500);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    public function destroy($id)
    {
        try {
            $this->itemsRepository->deleteItem($id);
            CacheService::invalidateUsersCache();
            return response()->json(['message' => 'Роль удалена успешно']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Роль не найдена', 404);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
