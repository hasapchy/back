<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UsersRepository
{
    public function getItemsWithPagination($perPage = 20)
    {
        return User::with('permissions')->paginate($perPage);
    }

    public function getAllItems()
    {
        return User::with('permissions')->get();
    }

    public function createItem(array $data)
    {
        $user = new User();
        $user->name     = $data['name'];
        $user->email    = $data['email'];
        $user->password = Hash::make($data['password']);
        $user->save();

        if (isset($data['permissions'])) {
            $user->syncPermissions($data['permissions']);
        }

        return $user->load('permissions');
    }

    public function updateItem($id, array $data)
    {
        $user = User::findOrFail($id);
        $user->name  = $data['name'];
        $user->email = $data['email'];

        if (!empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        if (isset($data['permissions'])) {
            $user->syncPermissions($data['permissions']);
        }

        return $user->load('permissions');
    }

    public function deleteItem($id)
    {
        $user = User::findOrFail($id);
        $user->delete();
        return true;
    }

    public function getAll()
    {
        return User::with('permissions')->get();
    }
}
