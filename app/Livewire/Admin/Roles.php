<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;

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

    // При клике на мастер-чекбокс группы выбираем/снимаем все права в группе
    public function toggleGroup($group)
    {
        // Получаем все разрешения, имя которых начинается с префикса группы (например, "users_")
        $groupPermissions = Permission::where('name', 'like', $group.'_%')->pluck('id')->toArray();

        // Если мастер-чекбокс включен – добавляем в выбранные, иначе удаляем
        if (!empty($this->groupChecks[$group])) {
            $this->selectedPermissions = array_unique(array_merge($this->selectedPermissions, $groupPermissions));
        } else {
            $this->selectedPermissions = array_values(array_diff($this->selectedPermissions, $groupPermissions));
        }
    }

}