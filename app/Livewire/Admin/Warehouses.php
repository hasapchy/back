<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Warehouse;
use App\Models\User;

class Warehouses extends Component
{
    public $warehouseId, $name, $users, $selectedUsers = [], $showForm = false;

    protected $rules = [
        'name' => 'required|string|max:255',
        'selectedUsers' => 'nullable|array',
        'selectedUsers.*' => 'exists:users,id',
    ];

    public function mount()
    {
        $this->users = User::all();
    }

    public function render()
    {
        return view('livewire.admin.warehouses.warehouses', [
            'warehouses' => Warehouse::with('stocks')->paginate(20),
        ]);
    }

    public function openForm()
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function closeForm()
    {
        $this->resetForm();
    }

    public function resetForm()
    {
        $this->reset(['warehouseId', 'name', 'selectedUsers', 'showForm']);
    }

    public function save()
    {
        $this->validate();

        $warehouse = Warehouse::updateOrCreate(
            ['id' => $this->warehouseId],
            ['name' => $this->name]
        );

        $warehouse->update(['users' => $this->selectedUsers]);
        session()->flash('success', $this->warehouseId ? 'Склад обновлён.' : 'Склад создан.');
        $this->resetForm();
    }

    public function edit(Warehouse $warehouse)
    {
        $this->warehouseId = $warehouse->id;
        $this->name = $warehouse->name;
        $this->selectedUsers = $warehouse->users ?: [];
        $this->showForm = true;
    }

    public function delete(Warehouse $warehouse)
    {
        if ($warehouse->stocks()->exists()) {
            session()->flash('error', 'Нельзя удалить склад, так как он используется в запасах.');
            return;
        }

        $warehouse->delete();
        session()->flash('success', 'Склад удалён.');
        $this->resetForm();
    }
}
