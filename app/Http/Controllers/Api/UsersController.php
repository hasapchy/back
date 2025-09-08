<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\UsersRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Permission;


class UsersController extends Controller
{
    protected $itemsRepository;

    public function __construct(UsersRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    public function index()
    {
        return response()->json($this->itemsRepository->getItemsWithPagination());
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|unique:users,email',
            'password' => 'required|string|min:6',
            'hire_date' => 'nullable|date',
            'is_active'   => 'nullable|boolean',
            'is_admin'   => 'nullable|boolean',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name',
            'companies' => 'nullable|array',
            'companies.*' => 'integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $this->itemsRepository->createItem($request->all());

        return response()->json(['user' => $user]);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'email'    => "required|email|unique:users,email,{$id}",
            'password' => 'nullable|string|min:6',
            'hire_date' => 'nullable|date',
            'is_active'   => 'nullable|boolean',
            'is_admin'   => 'nullable|boolean',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name',
            'companies' => 'nullable|array',
            'companies.*' => 'integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $this->itemsRepository->updateItem($id, $request->all());

        return response()->json(['user' => $user]);
    }

    public function destroy($id)
    {
        $this->itemsRepository->deleteItem($id);
        return response()->json(['message' => 'User deleted']);
    }

    public function permissions()
    {
        return response()->json(Permission::where('guard_name', 'api')->get());
    }
    public function getAllUsers()
    {
        return response()->json($this->itemsRepository->getAll());
    }
}
