<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Role;
use App\Models\Permission;

class Roles extends Component
{
    public $roles;
    public $permissions;
    public $roleId;
    public $name;
    public $selectedPermissions = [];
    public $showForm = false;
    public $showConfirmationModal = false;
    protected $listeners = ['editRole'];

    public function mount()
    {
        $this->roles = Role::with('permissions')->get();
        $this->permissions = Permission::all();
    }

    public function openForm()
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function closeForm()
    {
        if ($this->isDirty()) {
            $this->showConfirmationModal = true;
        } else {
            $this->resetForm();
        }
    }

    public function confirmClose($confirm = false)
    {
        if ($confirm) {
            $this->resetForm();
        }
        $this->showConfirmationModal = false;
    }

    public function isDirty()
    {
        return !empty($this->name) || !empty($this->selectedPermissions);
    }

    public function editRole($id)
    {
        $role = Role::with('permissions')->findOrFail($id);
        $this->roleId = $role->id;
        $this->name = $role->name;
        $this->selectedPermissions = $role->permissions->pluck('id')->toArray();
        $this->showForm = true;
    }

    public function saveRole()
    {
        $validatedData = $this->validate([
            'name' => 'required|string|unique:roles,name,' . $this->roleId,
            'selectedPermissions' => 'array',
        ]);

        $role = Role::updateOrCreate(
            ['id' => $this->roleId],
            ['name' => $this->name]
        );

        $role->permissions()->sync($this->selectedPermissions);
        $this->resetForm();
        $this->roles = Role::with('permissions')->get();
        session()->flash('message', 'Роль успешно сохранена.');
        session()->flash('type', 'success');
        $this->dispatch('updated');
        $this->dispatch('refreshPage');
    }

    public function deleteRole($id)
    {
        $role = Role::findOrFail($id);
        $role->permissions()->detach();
        $role->delete();
        $this->roles = Role::with('permissions')->get();

        if ($this->roleId == $id) {
            $this->resetForm();
        }

        $this->dispatch('deleted');
        $this->dispatch('refreshPage');
    }

    public function resetForm()
    {
        $this->roleId = null;
        $this->name = '';
        $this->selectedPermissions = [];
        $this->showForm = false;
    }

    public function render()
    {
        return view('livewire.admin.roles');
    }
}
