<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\User;
use Spatie\Permission\Models\Role;


class Users extends Component
{
    public $users;
    public $userId;
    public $showForm = false;
    public $showConfirmationModal = false;
    public $name;
    public $email;
    public $password;
    public $hire_date;
    public $position;
    public $roleId; // Новое свойство для роли
    public $availableRoles = []; // Список доступных ролей
    public $isDirty = false; 

    protected $listeners = ['editUser', 'confirmClose'];
    public $columns = [
        'id',
        'name',
        'email',
        'hire_date',
        'position',
        'is_active',
        'created_at',
        'updated_at',
    ];

    public function mount()
    {
        $this->users = User::all();
        $this->availableRoles = Role::all(); // Загружаем роли для селекта
    }

    public function openForm()
    {
        $this->resetForm();
        $this->showForm = true;
        $this->isDirty = false;
    }

    public function closeForm()
    {
        if ($this->isDirty) {
            $this->showConfirmationModal = true;
        } else {
            $this->resetForm();
            $this->showForm = false;
        }
    }

    public function confirmClose($confirm = false)
    {
        if ($confirm) {
            $this->resetForm();
            $this->isDirty = false;
            $this->showForm = false;
        }
        $this->showConfirmationModal = false;
    }

    public function updated($propertyName)
    {
        $this->isDirty = true;
    }

    public function editUser($userId)
    {
        $user = User::findOrFail($userId);
        $this->userId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->hire_date = $user->hire_date;
        $this->position = $user->position;
        // Если у пользователя есть назначенная роль, берем первую
        $this->roleId = optional($user->roles->first())->id;
        $this->showForm = true;
        $this->isDirty = false;
    }

    public function saveUser()
    {
        $data = $this->validate([
            'name'      => 'required',
            'email'     => 'required|email|unique:users,email,' . $this->userId,
            'password'  => 'nullable|min:8',
            'hire_date' => 'nullable|date',
            'position'  => 'nullable|string',
            'roleId'    => 'nullable|exists:roles,id',
        ]);

        // Если значение password не задано, берем старый пароль
        $data['password'] = $this->password 
                              ? bcrypt($this->password) 
                              : optional(User::find($this->userId))->password;

        $user = User::updateOrCreate(
            ['id' => $this->userId],
            [
                'name'      => $this->name,
                'email'     => $this->email,
                'password'  => $data['password'],
                'hire_date' => $this->hire_date,
                'position'  => $this->position,
            ]
        );

        // Назначаем пользователю выбранную роль, если выбрано значение
        if ($this->roleId) {
            $role = \Spatie\Permission\Models\Role::find($this->roleId);
            if ($role) {
                $user->syncRoles($role);
            }
        }

        $this->dispatch('updated');
        $this->resetForm();
        $this->users = User::all();
        $this->isDirty = false;
    }

    public function deleteUser($userId)
    {
        User::findOrFail($userId)->delete();
        $this->users = User::all();
        session()->flash('success', 'Пользователь успешно удален.');
    }

    public function resetForm()
    {
        $this->userId = null;
        $this->name = '';
        $this->email = '';
        $this->password = '';
        $this->hire_date = null;
        $this->position = null;
        $this->roleId = null; // Сбрасываем выбранную роль
        $this->showForm = false;
        $this->isDirty = false;
    }

    public function render()
    {
        return view('livewire.admin.users');
    }
}