<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Roles extends Component
{
    public $roleName;
    public $selectedPermissions = [];
    public $userId;
    public $selectedRoleId;
    public $showForm = false;

    // Для хранения состояния мастер-чекбокса по группам (ключ – префикс разрешения)
    public $groupChecks = [];

    protected $rules = [
        'roleName'           => 'required|string|unique:roles,name',
        'selectedPermissions' => 'array',
        'userId'             => 'nullable|exists:users,id',
        'selectedRoleId'     => 'nullable|exists:roles,id',
    ];

    public function render()
    {
        $roles = Role::with('permissions')->get();
        $permissions = Permission::all();
        $users = User::all();

        return view('livewire.admin.roles', compact('roles', 'permissions', 'users'));
    }

    public function openForm()
    {
        $this->resetValidation();
        $this->reset(['roleName', 'selectedPermissions', 'groupChecks']);
        $this->showForm = true;
    }

    public function closeForm()
    {
        $this->showForm = false;
    }

    public function save()
    {
        $this->validateOnly('roleName');

        $role = Role::create(['name' => $this->roleName]);

        if (!empty($this->selectedPermissions)) {
            $permissions = Permission::whereIn('id', $this->selectedPermissions)->get();
            $role->syncPermissions($permissions);
        }

        session()->flash('success', 'Роль успешно создана.');
        $this->closeForm();
    }

    public function edit($roleId)
    {
        $role = Role::with('permissions')->findOrFail($roleId);

        $this->selectedRoleId = $role->id;
        $this->roleName = $role->name;
        $this->selectedPermissions = $role->permissions->pluck('id')->toArray();

        // При необходимости можно сбросить состояние мастер-чекбоксов
        $this->groupChecks = [];

        $this->showForm = true;
    }
    public function delete ($roleId)
    {
        $role = Role::findOrFail($roleId);

        // Проверяем наличие привязанных пользователей через таблицу model_has_roles
        $attached = \DB::table('model_has_roles')
            ->where('role_id', $roleId)
            ->exists();

        if ($attached) {
            session()->flash('error', 'Невозможно удалить роль, к ней привязаны пользователи.');
            return;
        }

        $role->delete();
        session()->flash('success', 'Роль успешно удалена.');
    }


    // При клике на мастер-чекбокс группы выбираем/снимаем все права в группе
    public function toggleGroup($group)
    {
        // Получаем все разрешения, имя которых начинается с префикса группы (например, "users_")
        $groupPermissions = Permission::where('name', 'like', $group . '_%')->pluck('id')->toArray();

        // Если мастер-чекбокс включен – добавляем в выбранные, иначе удаляем
        if (!empty($this->groupChecks[$group])) {
            $this->selectedPermissions = array_unique(array_merge($this->selectedPermissions, $groupPermissions));
        } else {
            $this->selectedPermissions = array_values(array_diff($this->selectedPermissions, $groupPermissions));
        }
    }
}
