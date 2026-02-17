<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Repositories\UsersRepository;
use App\Models\User;
use App\Models\EmployeeSalary;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Services\CacheService;

/**
 * Контроллер для работы с пользователями
 */
class UsersController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     *
     * @param UsersRepository $itemsRepository
     */
    public function __construct(UsersRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
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
        $perPage = $request->input('per_page', 20);

        return $this->paginatedResponse($this->itemsRepository->getItemsWithPagination($page, $perPage));
    }

    /**
     * Создать нового пользователя
     *
     * @param StoreUserRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreUserRequest $request)
    {
        $data = $request->validated();

        if ($request->hasFile('photo')) {
            $photo = $request->file('photo');
            $photoName = time() . '_' . $photo->getClientOriginalName();
            $photo->storeAs('public/uploads/users', $photoName);
            $data['photo'] = 'uploads/users/' . $photoName;
        }

        $user = $this->itemsRepository->createItem($data);

        return $this->userResponse($user);
    }

    /**
     * Обновить пользователя
     *
     * @param UpdateUserRequest $request
     * @param int $id ID пользователя
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $user = User::findOrFail($id);

        if (!$this->canPerformAction('users', 'view', $user)) {
            return $this->forbiddenResponse('Нет прав на просмотр этого пользователя');
        }

        return $this->userResponse($user);
    }

    public function update(UpdateUserRequest $request, $id)
    {
        $targetUser = User::findOrFail($id);

        if (!$this->canPerformAction('users', 'update', $targetUser)) {
            return $this->forbiddenResponse('Нет прав на редактирование этого пользователя');
        }

        $data = $request->validated();
        unset($data['photo']);

        $companies = $data['companies'] ?? null;
        $roles = $data['roles'] ?? null;
        $companyRoles = $data['company_roles'] ?? null;
        $position = $data['position'] ?? null;
        $hireDate = $data['hire_date'] ?? null;
        $birthday = $data['birthday'] ?? null;

        $hasPosition = array_key_exists('position', $request->all());
        $hasHireDate = array_key_exists('hire_date', $request->all());
        $hasBirthday = array_key_exists('birthday', $request->all());

        $data = array_filter($data, function ($value) {
            return $value !== null;
        });

        if ($companies !== null) {
            $data['companies'] = $companies;
        }
        if ($roles !== null) {
            $data['roles'] = $roles;
        }
        if ($companyRoles !== null) {
            $data['company_roles'] = $companyRoles;
        }
        if ($hasPosition) {
            $data['position'] = $position;
        }
        if ($hasHireDate) {
            $data['hire_date'] = $hireDate;
        }
        if ($hasBirthday) {
            $data['birthday'] = $birthday;
        }

        $user = $this->itemsRepository->updateItem($id, $data);
        $user = $this->handlePhotoUpload($request, $user);

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

            if (!$this->canPerformAction('users', 'delete', $targetUser)) {
                return $this->forbiddenResponse('Нет прав на удаление этого пользователя');
            }

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

        return response()->json([
            'creator_id' => $user->id,
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
        return response()->json(Permission::where('guard_name', 'api')->get());
    }

    /**
     * Получить все роли
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function roles()
    {
        return response()->json(Role::where('guard_name', 'api')->with('permissions:id,name')->get());
    }

    /**
     * Получить всех пользователей
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllUsers()
    {
        $items = $this->itemsRepository->getAllItems();
        return response()->json($items);
    }

    public function search(Request $request)
    {
        $search_request = $request->input('search_request');

        if (!$search_request || empty($search_request)) {
            return response()->json([]);
        }

        $items = $this->itemsRepository->searchUser($search_request);

        return response()->json($items);
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

        return response()->json([
            'user' => $user,
            'permissions' => $permissions,
            'roles' => $roles,
            'company_roles' => $user->company_roles
        ]);
    }

    /**
     * Получить текущего пользователя
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCurrentUser(Request $request)
    {
        $user = $request->user()->load(['clientAccounts' => function ($query) {
            $query->where('status', 'active')
                ->select('id', 'employee_id', 'client_type', 'first_name', 'balance', 'status', 'company_id');
        }]);

        return response()->json(['user' => $user]);
    }

    /**
     * Обновить профиль текущего пользователя
     *
     * @bodyParam name string Имя. Example: Иван
     * @bodyParam surname string Фамилия. Example: Петров
     * @bodyParam email string Email. Example: ivan@example.com
     * @bodyParam birthday string Дата рождения (Y-m-d). Example: 1990-01-15
     * @bodyParam current_password string Текущий пароль (обязателен при смене пароля). Example: oldpass123
     * @bodyParam password string Новый пароль (min 6 символов). Example: newpass456
     * @bodyParam photo file Фото (jpeg, png, jpg, gif, max 2MB). Example:
     *
     * @response 200 {"user":{"id":1,"name":"Иван","surname":"Петров","email":"ivan@example.com","birthday":"1990-01-15","photo":null,"roles":[],"permissions":[]}}
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        try {
            if ($request->has('birthday') && $request->input('birthday') === '') {
                $request->merge(['birthday' => null]);
            }
            $data = $request->validate([
                'name' => 'nullable|string|max:255',
                'surname' => 'nullable|string|max:255',
                'email' => "nullable|email|unique:users,email,{$user->id},id",
                'birthday' => 'nullable|date',
                'current_password' => 'nullable|string',
                'password' => 'nullable|string|min:6',
                'photo' => 'nullable|file|mimes:jpeg,png,jpg,gif|max:2048',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->validator);
        }

        if ($request->filled('current_password') && !$request->filled('password')) {
            return $this->errorResponse('Новый пароль обязателен при указании текущего пароля', 422);
        }

        if ($request->filled('password')) {
            if (!$request->filled('current_password')) {
                return $this->errorResponse('Текущий пароль обязателен для смены пароля', 422);
            }

            if (!Hash::check($request->input('current_password'), $user->password)) {
                return $this->errorResponse('Неверный текущий пароль', 422);
            }
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

        return response()->json(['user' => $user]);
    }

    /**
     * Обработать загрузку фотографии пользователя
     *
     * @param Request $request
     * @param User $user Пользователь
     * @return User
     */
    private function handlePhotoUpload(Request $request, $user)
    {
        if ($request->hasFile('photo')) {
            if ($user->photo) {
                Storage::disk('public')->delete($user->photo);
            }
            $photo = $request->file('photo');
            $photoName = time() . '_' . $photo->getClientOriginalName();
            $photo->storeAs('public/uploads/users', $photoName);
            $photoData = ['photo' => 'uploads/users/' . $photoName];
            $user = $this->itemsRepository->updateItem($user->id, $photoData);
        } elseif ($request->has('photo') && ($request->input('photo') === '' || $request->input('photo') === null)) {
            if ($user->photo) {
                Storage::disk('public')->delete($user->photo);
            }
            $photoData = ['photo' => ''];
            $user = $this->itemsRepository->updateItem($user->id, $photoData);
        }
        return $user;
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
            $currentUser = $this->getAuthenticatedUser();

            if (!$this->hasPermission('employee_salaries_view_all') && $user->id !== $currentUser->id) {
                return $this->forbiddenResponse('Нет прав на просмотр зарплат');
            }

            $salaries = $this->itemsRepository->getSalaries($id);

            return response()->json(['salaries' => $salaries]);
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
    public function createSalary(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            if (!$this->hasPermission('employee_salaries_create')) {
                return $this->forbiddenResponse('Нет прав на создание зарплат');
            }

            $validatedData = $request->validate([
                'start_date' => 'required|date',
                'end_date' => 'nullable|date|after:start_date',
                'amount' => 'required|numeric|min:0',
                'currency_id' => 'required|exists:currencies,id',
                'payment_type' => 'required|boolean',
                'note' => 'nullable|string|max:120',
            ]);

            $salary = $this->itemsRepository->createSalary($id, $validatedData);

            return response()->json(['salary' => $salary, 'message' => 'Зарплата создана успешно']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Пользователь не найден', 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->validator);
        } catch (\Exception $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'активная зарплата') || str_contains($message, 'пересекается по датам')) {
                return $this->errorResponse($message, 422);
            }
            return $this->errorResponse('Ошибка при создании зарплаты: ' . $message, 500);
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
    public function updateSalary(Request $request, $userId, $salaryId)
    {
        try {
            $salary = EmployeeSalary::findOrFail($salaryId);

            if (!$this->canPerformAction('employee_salaries', 'update', $salary)) {
                return $this->forbiddenResponse('Нет прав на обновление этой зарплаты');
            }

            $validatedData = $request->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after:start_date',
                'amount' => 'nullable|numeric|min:0',
                'currency_id' => 'nullable|exists:currencies,id',
                'payment_type' => 'nullable|boolean',
                'note' => 'nullable|string|max:120',
            ]);

            $updatedSalary = $this->itemsRepository->updateSalary($salaryId, $validatedData);

            return response()->json(['salary' => $updatedSalary, 'message' => 'Зарплата обновлена успешно']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Пользователь или зарплата не найдены', 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->validator);
        } catch (\Exception $e) {
            $message = $e->getMessage();
            if (
                str_contains($message, 'активная зарплата') ||
                str_contains($message, 'более новая активная') ||
                str_contains($message, 'разблокировать') ||
                str_contains($message, 'пересекается по датам')
            ) {
                return $this->errorResponse($message, 422);
            }
            return $this->errorResponse('Ошибка при обновлении зарплаты: ' . $message, 500);
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
            $salary = EmployeeSalary::findOrFail($salaryId);

            if (!$this->canPerformAction('employee_salaries', 'delete', $salary)) {
                return $this->forbiddenResponse('Нет прав на удаление этой зарплаты');
            }

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
        $currentUser = $this->requireAuthenticatedUser();

        try {
            $user = User::findOrFail($id);

            if (!$this->hasPermission('settings_client_balance_view', $currentUser) &&
                (!$this->hasPermission('settings_client_balance_view_own', $currentUser) || $user->id !== $currentUser->id)) {
                return $this->forbiddenResponse('Нет доступа к просмотру баланса');
            }

            $balance = $this->itemsRepository->getEmployeeBalance($id);

            return response()->json(['balance' => $balance]);
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
        $currentUser = $this->requireAuthenticatedUser();

        try {
            $targetUser = User::findOrFail($id);

            if (!$this->hasPermission('settings_client_balance_view', $currentUser) &&
                (!$this->hasPermission('settings_client_balance_view_own', $currentUser) || $targetUser->id !== $currentUser->id)) {
                return $this->forbiddenResponse('Нет доступа к просмотру баланса');
            }

            $history = $this->itemsRepository->getEmployeeBalanceHistory($id);

            return response()->json(['history' => $history]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Пользователь не найден', 404);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении истории баланса: ' . $e->getMessage(), 500);
        }
    }
}
