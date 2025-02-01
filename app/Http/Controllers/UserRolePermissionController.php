<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\Request;

class UserRolePermissionController extends Controller
{
    // Методы для управления правами
    public function indexPermissions()
    {
        // Проверяем, есть ли у пользователя право на просмотр списка прав
        if (!auth()->user()->hasPermission('view_clients')) {
            return redirect()->route('admin.dashboard')->with('error', 'У вас нет прав для просмотра клиентов.');
        }

        $permissions = Permission::all();
        return view('admin.permissions.index', compact('permissions'));
    }

    public function createPermission()
    {
        return view('admin.permissions.create');
    }

    public function storePermission(Request $request)
    {
        $request->validate(['name' => 'required|unique:permissions']);

        Permission::create(['name' => $request->name]);

        return redirect()->route('admin.permissions.index')->with('success', 'Право создано');
    }

    public function destroyPermission(Permission $permission)
    {
        $permission->delete();
        return redirect()->route('admin.permissions.index')->with('success', 'Право удалено');
    }
}
