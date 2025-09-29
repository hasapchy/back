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
        return response()->json($this->itemsRepository->getItemsWithPagination($page));
    }

    public function store(Request $request)
    {
        $data = $request->all();

        // Обрабатываем boolean поля
        if (isset($data['is_active'])) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['is_admin'])) {
            $data['is_admin'] = filter_var($data['is_admin'], FILTER_VALIDATE_BOOLEAN);
        }

        // Обрабатываем массивы из FormData
        if (isset($data['permissions']) && is_string($data['permissions'])) {
            $data['permissions'] = explode(',', $data['permissions']);
        }
        if (isset($data['companies']) && is_string($data['companies'])) {
            $data['companies'] = array_filter(explode(',', $data['companies']), function ($c) {
                return trim($c) !== '';
            });
        }

        $validator = Validator::make($data, [
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|unique:users,email',
            'password' => 'required|string|min:6',
            'hire_date' => 'nullable|date',
            'is_active'   => 'nullable|boolean',
            'is_admin'   => 'nullable|boolean',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name',
            'companies' => 'nullable|array',
            'companies.*' => 'integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Handle photo upload
        if ($request->hasFile('photo')) {
            $photo = $request->file('photo');
            $photoName = time() . '_' . $photo->getClientOriginalName();
            $photo->storeAs('public/uploads/users', $photoName);
            $data['photo'] = 'uploads/users/' . $photoName;
        }

        $user = $this->itemsRepository->createItem($data);


        return response()->json([
            'user' => $user,
            'permissions' => $user->permissions->pluck('name')->toArray()
        ]);
    }

    public function update(Request $request, $id)
    {
        $data = $request->all();


        if (isset($data['is_active'])) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['is_admin'])) {
            $data['is_admin'] = filter_var($data['is_admin'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($data['permissions']) && is_string($data['permissions'])) {
            $data['permissions'] = explode(',', $data['permissions']);
        }
        if (isset($data['companies']) && is_string($data['companies'])) {
            $data['companies'] = array_filter(explode(',', $data['companies']), function ($c) {
                return trim($c) !== '';
            });
        }

        $request->merge($data);

        try {
            $data = $request->validate([
                'name'     => 'nullable|string|max:255',
                'email'    => "nullable|email|unique:users,email,{$id},id",
                'password' => 'nullable|string|min:6',
                'hire_date' => 'nullable|date',
                'is_active'   => 'nullable|boolean',
                'is_admin'   => 'nullable|boolean',
                'photo' => 'nullable|file|mimes:jpeg,png,jpg,gif|max:2048',
                'permissions' => 'nullable|array',
                'permissions.*' => 'string|exists:permissions,name',
                'companies' => 'nullable|array',
                'companies.*' => 'integer|exists:companies,id',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }

        unset($data['photo']);

        $data = array_filter($data, function ($value) {
            return !is_null($value);
        });

        $user = $this->itemsRepository->updateItem($id, $data);
        $user = $this->handlePhotoUpload($request, $user);

        $user = $user->fresh();


        return response()->json([
            'user' => $user,
            'permissions' => $user->permissions->pluck('name')->toArray()
        ]);
    }

    public function destroy($id)
    {
        $this->itemsRepository->deleteItem($id);
        return response()->json(['message' => 'User deleted']);
    }

    public function checkPermissions($id)
    {
        $user = User::with('permissions')->findOrFail($id);

        return response()->json([
            'user_id' => $user->id,
            'user_email' => $user->email,
            'permissions' => $user->permissions->pluck('name')->toArray(),
            'permissions_count' => $user->permissions->count()
        ]);
    }

    public function permissions()
    {
        return response()->json(Permission::where('guard_name', 'api')->get());
    }
    public function getAllUsers()
    {
        return response()->json($this->itemsRepository->getAll());
    }

    public function getCurrentUser(Request $request)
    {
        return response()->json($request->user());
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        try {
            $data = $request->validate([
                'name' => 'nullable|string|max:255',
                'email' => "nullable|email|unique:users,email,{$user->id},id",
                'current_password' => 'nullable|string',
                'password' => 'nullable|string|min:6',
                'photo' => 'nullable|file|mimes:jpeg,png,jpg,gif|max:2048',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }

        if ($request->filled('current_password') && !$request->filled('password')) {
            return response()->json(['errors' => ['password' => ['Новый пароль обязателен при указании текущего пароля']]], 422);
        }

        if ($request->filled('password')) {
            if (!$request->filled('current_password')) {
                return response()->json(['errors' => ['current_password' => ['Текущий пароль обязателен для смены пароля']]], 422);
            }

            if (!Hash::check($request->input('current_password'), $user->password)) {
                return response()->json(['errors' => ['current_password' => ['Неверный текущий пароль']]], 422);
            }
        }

        if (isset($data['password'])) {
            $data['password'] = bcrypt($data['password']);
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
            return !is_null($value);
        });

        $user = $this->itemsRepository->updateItem($user->id, $data);
        $user = $this->handlePhotoUpload($request, $user);

        $user = $user->fresh();

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
