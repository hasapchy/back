<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Repositories\RolesRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Контроллер для работы с ролями
 */
class RolesController extends Controller
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     *
     * @param RolesRepository $itemsRepository
     */
    public function __construct(RolesRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список ролей с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $page = $request->input('page', 1);
        $search = $request->input('search');
        $companyId = $this->getCurrentCompanyId();
        $items = $this->itemsRepository->getItemsWithPagination($page, 20, $search, $companyId);
        return RoleResource::collection($items)->response();
    }

    /**
     * Получить все роли
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function all()
    {
        $companyId = $this->getCurrentCompanyId();
        $items = $this->itemsRepository->getAllItems($companyId);
        return RoleResource::collection($items)->response();
    }

    /**
     * Получить роль по ID
     *
     * @param int $id ID роли
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $companyId = $this->getCurrentCompanyId();
            $role = $this->itemsRepository->getItem($id, $companyId);
            $role = Role::with('permissions')->findOrFail($id);
            return $this->dataResponse(new RoleResource($role));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Роль не найдена', 404);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении роли', 500);
        }
    }

    /**
     * Создать новую роль
     *
     * @param StoreRoleRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreRoleRequest $request)
    {
        try {
            $data = $request->validated();
            $companyId = $this->getCurrentCompanyId();

            $role = $this->itemsRepository->createItem($data, $companyId);
            $role = Role::with('permissions')->findOrFail($role->id);

            return $this->dataResponse(new RoleResource($role), 'Роль создана успешно', 201);
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->errorResponse('Ошибка при создании роли: ' . $e->getMessage(), 500);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * Обновить роль
     *
     * @param UpdateRoleRequest $request
     * @param int $id ID роли
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateRoleRequest $request, $id)
    {
        try {
            $data = $request->validated();
            $companyId = $this->getCurrentCompanyId();

            $role = $this->itemsRepository->updateItem($id, $data, $companyId);
            $role = Role::with('permissions')->findOrFail($id);

            return $this->dataResponse(new RoleResource($role), 'Роль обновлена успешно');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Роль не найдена', 404);
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->errorResponse('Ошибка при обновлении роли: ' . $e->getMessage(), 500);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * Удалить роль
     *
     * @param int $id ID роли
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $companyId = $this->getCurrentCompanyId();
            $role = Role::findOrFail($id);
            $this->itemsRepository->deleteItem($id, $companyId);
            return $this->dataResponse(new RoleResource($role), 'Роль удалена успешно');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Роль не найдена', 404);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
