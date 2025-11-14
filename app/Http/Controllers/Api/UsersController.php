<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\UsersRepository;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Services\CacheService;


class UsersController extends Controller
{
    protected $itemsRepository;

    public function __construct(UsersRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    public function index(Request $request)
    {
        $page = $request->input('page', 1);
        return $this->paginatedResponse($this->itemsRepository->getItemsWithPagination($page));
    }

    public function store(Request $request)
    {
        $data = $request->all();

        if (isset($data['is_active'])) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['is_admin'])) {
            $data['is_admin'] = filter_var($data['is_admin'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($data['roles']) && is_string($data['roles'])) {
            $data['roles'] = explode(',', $data['roles']);
        }

        if (isset($data['companies'])) {
            if (is_string($data['companies'])) {
                $data['companies'] = array_filter(explode(',', $data['companies']), function ($c) {
                    return trim($c) !== '';
                });
            }
            if (is_array($data['companies'])) {
                $data['companies'] = array_values(array_map('intval', $data['companies']));
            }
        }

        if (isset($data['company_roles']) && is_string($data['company_roles'])) {
            try {
                $data['company_roles'] = json_decode($data['company_roles'], true);
            } catch (\Exception $e) {
                $data['company_roles'] = [];
            }
        }

        if (isset($data['position']) && trim($data['position']) === '') {
            $data['position'] = null;
        }
        if (isset($data['hire_date']) && trim($data['hire_date']) === '') {
            $data['hire_date'] = null;
        }
        if (isset($data['birthday']) && trim($data['birthday']) === '') {
            $data['birthday'] = null;
        }

        $validator = Validator::make($data, [
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|unique:users,email',
            'password' => 'required|string|min:6',
            'hire_date' => 'nullable|date',
            'birthday' => 'nullable|date',
            'position' => 'nullable|string|max:255',
            'is_active'   => 'nullable|boolean',
            'is_admin'   => 'nullable|boolean',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'roles' => 'nullable|array',
            'roles.*' => 'string|exists:roles,name,guard_name,api',
            'companies' => 'nullable|array',
            'companies.*' => 'integer|exists:companies,id',
            'company_roles' => 'nullable|array',
            'company_roles.*.company_id' => 'required_with:company_roles|integer|exists:companies,id',
            'company_roles.*.role_ids' => 'required_with:company_roles|array',
            'company_roles.*.role_ids.*' => 'string|exists:roles,name,guard_name,api',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        if ($request->hasFile('photo')) {
            $photo = $request->file('photo');
            $photoName = time() . '_' . $photo->getClientOriginalName();
            $photo->storeAs('public/uploads/users', $photoName);
            $data['photo'] = 'uploads/users/' . $photoName;
        }

        $user = $this->itemsRepository->createItem($data);

        CacheService::invalidateUsersCache();

        return $this->userResponse($user);
    }

    public function update(Request $request, $id)
    {
        $targetUser = User::findOrFail($id);

        // Проверяем права с учетом _all/_own
        if (!$this->canPerformAction('users', 'update', $targetUser)) {
            return $this->forbiddenResponse('Нет прав на редактирование этого пользователя');
        }

        $data = $request->all();


        if (isset($data['is_active'])) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['is_admin'])) {
            $data['is_admin'] = filter_var($data['is_admin'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($data['roles']) && is_string($data['roles'])) {
            $data['roles'] = explode(',', $data['roles']);
        }

        if (isset($data['companies'])) {
            if (is_string($data['companies'])) {
                $data['companies'] = array_filter(explode(',', $data['companies']), function ($c) {
                    return trim($c) !== '';
                });
            }
            if (is_array($data['companies'])) {
                $data['companies'] = array_values(array_map('intval', $data['companies']));
            }
        }

        if (isset($data['company_roles']) && is_string($data['company_roles'])) {
            try {
                $data['company_roles'] = json_decode($data['company_roles'], true);
            } catch (\Exception $e) {
                $data['company_roles'] = [];
            }
        }

        $hasPosition = array_key_exists('position', $data);
        $hasHireDate = array_key_exists('hire_date', $data);
        $hasBirthday = array_key_exists('birthday', $data);

        if (isset($data['position']) && trim($data['position']) === '') {
            $data['position'] = null;
        }
        if (isset($data['hire_date']) && trim($data['hire_date']) === '') {
            $data['hire_date'] = null;
        }
        if (isset($data['birthday']) && trim($data['birthday']) === '') {
            $data['birthday'] = null;
        }

        $request->merge($data);

        try {
            $data = $request->validate([
                'name'     => 'nullable|string|max:255',
                'email'    => "nullable|email|unique:users,email,{$id},id",
                'password' => 'nullable|string|min:6',
                'hire_date' => 'nullable|date',
                'birthday' => 'nullable|date',
                'position' => 'nullable|string|max:255',
                'is_active'   => 'nullable|boolean',
                'is_admin'   => 'nullable|boolean',
                'photo' => 'nullable|file|mimes:jpeg,png,jpg,gif|max:2048',
                'roles' => 'nullable|array',
                'roles.*' => 'string|exists:roles,name,guard_name,api',
                'companies' => 'nullable|array',
                'companies.*' => 'integer|exists:companies,id',
                'company_roles' => 'nullable|array',
                'company_roles.*.company_id' => 'required_with:company_roles|integer|exists:companies,id',
                'company_roles.*.role_ids' => 'required_with:company_roles|array',
                'company_roles.*.role_ids.*' => 'string|exists:roles,name,guard_name,api',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }

        unset($data['photo']);

        $companies = $data['companies'] ?? null;
        $roles = $data['roles'] ?? null;
        $companyRoles = $data['company_roles'] ?? null;
        $position = $data['position'] ?? null;
        $hireDate = $data['hire_date'] ?? null;
        $birthday = $data['birthday'] ?? null;

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

        CacheService::invalidateUsersCache();

        return $this->userResponse($user);
    }

    public function destroy($id)
    {
        $targetUser = User::findOrFail($id);

        // Проверяем права с учетом _all/_own
        if (!$this->canPerformAction('users', 'delete', $targetUser)) {
            return $this->forbiddenResponse('Нет прав на удаление этого пользователя');
        }

        $this->itemsRepository->deleteItem($id);

        CacheService::invalidateUsersCache();

        return response()->json(['message' => 'User deleted']);
    }

    public function checkPermissions($id)
    {
        $user = User::with('permissions', 'roles')->findOrFail($id);
        $companyId = $this->getCurrentCompanyId();
        $permissions = $companyId ? $user->getAllPermissionsForCompany((int)$companyId) : $user->getAllPermissions();

        return response()->json([
            'user_id' => $user->id,
            'user_email' => $user->email,
            'permissions' => $permissions->pluck('name')->toArray(),
            'permissions_count' => $permissions->count()
        ]);
    }

    public function permissions()
    {
        return response()->json(Permission::where('guard_name', 'api')->get());
    }

    public function roles()
    {
        return response()->json(Role::where('guard_name', 'api')->with('permissions:id,name')->get());
    }
    public function getAllUsers()
    {
        $items = $this->itemsRepository->getAllItems();
        return response()->json($items);
    }

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

    public function getCurrentUser(Request $request)
    {
        $user = $request->user()->load(['clientAccounts' => function($query) {
            $query->where('status', 'active')
                  ->select('id', 'employee_id', 'client_type', 'first_name', 'balance', 'status', 'company_id');
        }]);

        return response()->json(['user' => $user]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        try {
            if ($request->has('birthday') && $request->input('birthday') === '') {
                $request->merge(['birthday' => null]);
            }
            $data = $request->validate([
                'name' => 'nullable|string|max:255',
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

        return response()->json(['user' => $user]);
    }

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
}
