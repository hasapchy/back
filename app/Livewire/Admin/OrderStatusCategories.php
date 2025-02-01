<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\OrderStatusCategory;
use Illuminate\Support\Facades\Auth;

class OrderStatusCategories extends Component
{
    public $statusCategories, $name, $color, $category_id;
    public $showForm = false; 
    public $showConfirmationModal = false; 

    public function render()
    {
        $this->statusCategories = OrderStatusCategory::all();
        return view('livewire.admin.orders.order-status-categories');
    }

    public function create()
    {
        $this->resetInputFields();
        $this->openForm(); // Updated method name
    }

    public function openForm()
    {
        $this->showForm = true; // Updated to set $showForm
    }

    public function closeForm()
    {
        $this->showForm = false; // Updated to set $showForm
    }

    private function resetInputFields()
    {
        $this->name = '';
        $this->color = '';
        $this->category_id = null;
    }

    public function store()
    {
        $this->validate([
            'name' => 'required',
            'color' => 'required',
        ]);

        OrderStatusCategory::updateOrCreate(['id' => $this->category_id], [
            'name' => $this->name,
            'color' => $this->color,
            'user_id'=>Auth::id(),
        ]);

        session()->flash('message', 
            $this->category_id ? 'Status Category Updated Successfully.' : 'Status Category Created Successfully.');

        $this->closeForm();
        $this->resetInputFields();
    }

    public function edit($id)
    {
        $statusCategory = OrderStatusCategory::findOrFail($id);
        $this->category_id = $id;
        $this->name = $statusCategory->name;
        $this->color = $statusCategory->color;

        $this->openForm(); // Updated method name
    }

    public function deleteStatusCategoryForm($id)
    {
        $this->category_id = $id;
        $this->openConfirmationModal(); // Open confirmation modal instead of deleting immediately
    }

    public function confirmDelete()
    {
        OrderStatusCategory::find($this->category_id)->delete();
        session()->flash('message', 'Status Category Deleted Successfully.');
        $this->closeConfirmationModal();
        $this->resetInputFields();
    }

    public function openConfirmationModal()
    {
        $this->showConfirmationModal = true;
    }

    public function closeConfirmationModal()
    {
        $this->showConfirmationModal = false;
    }

    // Removed unused $isOpen variable
}