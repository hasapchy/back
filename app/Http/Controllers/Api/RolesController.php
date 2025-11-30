<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\RolesRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

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
        $maxAttempts = 3;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            try {
                if ($attempt > 0) {
                    Cache::forget('spatie.permission.cache');
                }

                $page = $request->input('page', 1);
                $search = $request->input('search');
                $companyId = $this->getCurrentCompanyId();
                return $this->paginatedResponse($this->itemsRepository->getItemsWithPagination($page, 20, $search, $companyId));
            } catch (\Illuminate\Database\QueryException $e) {
                $attempt++;

                if ($e->getCode() == '40001' && str_contains($e->getMessage(), 'Deadlock')) {
                    if ($attempt >= $maxAttempts) {
                        return $this->errorResponse('Ошибка при получении ролей. Попробуйте обновить страницу.', 500);
                    }

                    Cache::forget('spatie.permission.cache');
                    usleep(100000 * $attempt);
                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * Получить все роли
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function all()
    {
        $companyId = $this->getCurrentCompanyId();
        return response()->json($this->itemsRepository->getAllItems($companyId));
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
            return response()->json($role);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Роль не найдена', 404);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении роли', 500);
        }
    }

    /**
     * Создать новую роль
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $data = $request->all();
            $companyId = $this->getCurrentCompanyId();

            if (isset($data['name'])) {
                $data['name'] = trim($data['name']);
                if (empty($data['name'])) {
                    return $this->errorResponse('Название роли не может быть пустым', 422);
                }
            }

            $uniqueRule = Rule::unique('roles', 'name')
                ->where('guard_name', 'api');

            if ($companyId) {
                $uniqueRule->where('company_id', $companyId);
            } else {
                $uniqueRule->whereNull('company_id');
            }

            $validator = Validator::make($data, [
                'name' => ['required', 'string', 'max:255', $uniqueRule],
                'permissions' => 'nullable|array|max:1000',
                'permissions.*' => 'string|exists:permissions,name,guard_name,api',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator);
            }

            $role = $this->itemsRepository->createItem($data, $companyId);

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

    /**
     * Обновить роль
     *
     * @param Request $request
     * @param int $id ID роли
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $data = $request->all();
            $companyId = $this->getCurrentCompanyId();

            $rules = [
                'permissions' => 'nullable|array|max:1000',
                'permissions.*' => 'string|exists:permissions,name,guard_name,api',
            ];

            if (isset($data['name'])) {
                $data['name'] = trim($data['name']);
                if (empty($data['name'])) {
                    return $this->errorResponse('Название роли не может быть пустым', 422);
                }
                $uniqueRule = Rule::unique('roles', 'name')
                    ->ignore($id)
                    ->where('guard_name', 'api');

                if ($companyId) {
                    $uniqueRule->where('company_id', $companyId);
                } else {
                    $uniqueRule->whereNull('company_id');
                }

                $rules['name'] = ['required', 'string', 'max:255', $uniqueRule];
            }

            $validator = Validator::make($data, $rules);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator);
            }

            $role = $this->itemsRepository->updateItem($id, $data, $companyId);

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
            $this->itemsRepository->deleteItem($id, $companyId);
            return response()->json(['message' => 'Роль удалена успешно']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Роль не найдена', 404);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
