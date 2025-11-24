<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserProfileRequest;
use App\Http\Requests\StoreUserSalaryRequest;
use App\Http\Requests\UpdateUserSalaryRequest;
use App\Http\Resources\UserResource;
use App\Repositories\UsersRepository;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Services\CacheService;

/**
 * Контроллер для работы с пользователями
 */
class UsersController extends Controller
{
    protected $itemsRepository;

    /**
     * @var UserService
     */
    protected $userService;

    /**
     * Конструктор контроллера
     *
     * @param UsersRepository $itemsRepository
     * @param UserService $userService
     */
    public function __construct(UsersRepository $itemsRepository, UserService $userService)
    {
        $this->itemsRepository = $itemsRepository;
        $this->userService = $userService;
    }

    /**
     * Получить список пользователей с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $page = $request->input('page', 1);
        $items = $this->itemsRepository->getItemsWithPagination($page);
        return UserResource::collection($items)->response();
    }

    /**
     * Создать нового пользователя
     *
     * @param StoreUserRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreUserRequest $request)
    {
        $this->authorize('create', User::class);

        $data = $this->userService->prepareUserData($request);
        $user = $this->userService->createUser($data, $request);

        return $this->userResponse($user);
    }

    /**
     * Обновить пользователя
     *
     * @param UpdateUserRequest $request
     * @param int $id ID пользователя
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateUserRequest $request, $id)
    {
        $targetUser = User::findOrFail($id);

        $this->authorize('update', $targetUser);

        $data = $this->userService->prepareUserData($request);
        $user = $this->userService->updateUser($targetUser, $data, $request);

        $companyId = $this->getCurrentCompanyId();
        $user = $user->fresh(['companies']);
        $user->setRelation('permissions', $companyId ? $user->getAllPermissionsForCompany((int)$companyId) : $user->getAllPermissions());
        if ($companyId) {
            $user->setRelation('roles', $user->getRolesForCompany((int)$companyId));
        } else {
            $user->load(['roles']);
        }

        return $this->userResponse($user);
    }

    /**
     * Удалить пользователя
     *
     * @param int $id ID пользователя
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $targetUser = User::findOrFail($id);

            $this->authorize('delete', $targetUser);

            $this->itemsRepository->deleteItem($id);

            return response()->json(['message' => 'User deleted']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Пользователь не найден', 404);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * Проверить права пользователя
     *
     * @param int $id ID пользователя
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkPermissions($id)
    {
        $user = User::with('permissions', 'roles')->findOrFail($id);
        $companyId = $this->getCurrentCompanyId();
        $permissions = $companyId ? $user->getAllPermissionsForCompany((int)$companyId) : $user->getAllPermissions();

        return $this->dataResponse([
            'user_id' => $user->id,
            'user_email' => $user->email,
            'permissions' => $permissions->pluck('name')->toArray(),
            'permissions_count' => $permissions->count()
        ]);
    }

    /**
     * Получить все разрешения
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function permissions()
    {
        return $this->dataResponse(Permission::where('guard_name', 'api')->get());
    }

    /**
     * Получить все роли
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function roles()
    {
        return $this->dataResponse(Role::where('guard_name', 'api')->with('permissions:id,name')->get());
    }

    /**
     * Получить всех пользователей
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllUsers()
    {
        $items = $this->itemsRepository->getAllItems();
        return UserResource::collection($items)->response();
    }

    /**
     * Формировать ответ с данными пользователя
     *
     * @param User $user Пользователь
     * @return JsonResponse
     */
    protected function userResponse(User $user): JsonResponse
    {
        $companyId = $this->getCurrentCompanyId();
        $permissions = $companyId ? $user->getAllPermissionsForCompany((int)$companyId)->pluck('name')->toArray() : $user->getAllPermissions()->pluck('name')->toArray();
        $roles = $companyId ? $user->getRolesForCompany((int)$companyId)->pluck('name')->toArray() : $user->roles->pluck('name')->toArray();
        $user->company_roles = $user->getAllCompanyRoles();

        return (new UserResource($user))->additional([
            'permissions' => $permissions,
            'roles' => $roles,
            'company_roles' => $user->company_roles
        ])->response();
    }

    /**
     * Получить текущего пользователя
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCurrentUser(Request $request)
    {
        $user = $request->user()->load(['clientAccounts' => function($query) {
            $query->where('status', 'active')
                  ->select('id', 'employee_id', 'client_type', 'first_name', 'balance', 'status', 'company_id');
        }]);

        return new UserResource($user);
    }

    /**
     * Обновить профиль текущего пользователя
     *
     * @param UpdateUserProfileRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(UpdateUserProfileRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();

        if (isset($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        }

        if (array_key_exists('birthday', $data) && $data['birthday'] === '') {
            $data['birthday'] = null;
        }

        if (isset($data['permissions']) && is_string($data['permissions'])) {
            $data['permissions'] = explode(',', $data['permissions']);
        }
        if (isset($data['companies']) && is_string($data['companies'])) {
            $data['companies'] = array_filter(explode(',', $data['companies']), function ($c) {
                return trim($c) !== '';
            });
        }

        unset($data['photo']);

        $data = array_filter($data, function ($value) {
            return $value !== null;
        });

        $user = $this->itemsRepository->updateItem($user->id, $data);
        $user = $this->handlePhotoUpload($request, $user);

        $user = $user->fresh(['permissions', 'roles', 'companies']);

        return $this->dataResponse(new UserResource($user));
    }


    /**
     * Получить зарплаты сотрудника
     *
     * @param int $id ID пользователя
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSalaries($id)
    {
        try {
            $user = User::findOrFail($id);

            $this->authorize('viewSalaries', $user);

            $salaries = $this->itemsRepository->getSalaries($id);

            return $this->dataResponse(['salaries' => $salaries]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Пользователь не найден', 404);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении зарплат: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Создать зарплату сотрудника
     *
     * @param Request $request
     * @param int $id ID пользователя
     * @return \Illuminate\Http\JsonResponse
     */
    public function createSalary(StoreUserSalaryRequest $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            $this->authorize('createSalary', User::class);

            $validatedData = $request->validated();

            $salary = $this->itemsRepository->createSalary($id, $validatedData);

            return $this->dataResponse(['salary' => $salary], 'Зарплата создана успешно');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Пользователь не найден', 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->validator);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при создании зарплаты: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Обновить зарплату сотрудника
     *
     * @param Request $request
     * @param int $userId ID пользователя
     * @param int $salaryId ID зарплаты
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateSalary(UpdateUserSalaryRequest $request, $userId, $salaryId)
    {
        try {
            $user = User::findOrFail($userId);

            $this->authorize('updateSalary', $user);

            $validatedData = $request->validated();

            $salary = $this->itemsRepository->updateSalary($salaryId, $validatedData);

            return $this->dataResponse(['salary' => $salary], 'Зарплата обновлена успешно');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Пользователь или зарплата не найдены', 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->validator);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при обновлении зарплаты: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Удалить зарплату сотрудника
     *
     * @param int $userId ID пользователя
     * @param int $salaryId ID зарплаты
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteSalary($userId, $salaryId)
    {
        try {
            $user = User::findOrFail($userId);

            $this->authorize('deleteSalary', $user);

            $this->itemsRepository->deleteSalary($salaryId);

            return response()->json(['message' => 'Зарплата удалена успешно']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Пользователь или зарплата не найдены', 404);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при удалении зарплаты: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Получить баланс сотрудника
     *
     * @param int $id ID пользователя
     * @return \Illuminate\Http\JsonResponse
     */
    public function getEmployeeBalance($id)
    {
        try {
            $user = User::findOrFail($id);

            $this->authorize('viewClientBalance', $user);

            $balance = $this->itemsRepository->getEmployeeBalance($id);

            return $this->dataResponse(['balance' => $balance]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Пользователь не найден', 404);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении баланса: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Получить историю баланса сотрудника
     *
     * @param int $id ID пользователя
     * @return \Illuminate\Http\JsonResponse
     */
    public function getEmployeeBalanceHistory($id)
    {
        try {
            $targetUser = User::findOrFail($id);

            $this->authorize('viewClientBalance', $targetUser);

            $history = $this->itemsRepository->getEmployeeBalanceHistory($id);

            return $this->dataResponse(['history' => $history]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Пользователь не найден', 404);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении истории баланса: ' . $e->getMessage(), 500);
        }
    }
}
