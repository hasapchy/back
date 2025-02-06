<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\OrderStatusCategory;
use Illuminate\Support\Facades\Auth;

class OrderStatusCategories extends Component
{
    public $categories, $name, $color, $categoryId;
    public $showForm = false;
    // public $showConfirmationModal = false; 

    public function render()
    {
        $this->categories = OrderStatusCategory::all();
        return view('livewire.admin.orders.order-status-categories');
    }


    public function openForm()
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function closeForm()
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function resetForm()
    {
        $this->name = '';
        $this->color = '';
        $this->categoryId = null;
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'color' => 'required|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        OrderStatusCategory::updateOrCreate(['id' => $this->categoryId], [
            'name' => $this->name,
            'color' => $this->color,
            'user_id' => Auth::id(),
        ]);

        session()->flash(
            'message',
            $this->categoryId ? 'Status Category Updated Successfully.' : 'Status Category Created Successfully.'
        );

        $this->closeForm();
    }

    public function edit($id)
    {
        $category = OrderStatusCategory::findOrFail($id);
        $this->categoryId = $category->id;
        $this->name = $category->name;
        $this->color = $category->color;
        $this->showForm = true;
    }

    public function delete($id)
    {
        OrderStatusCategory::find($id)->delete();
        session()->flash('message', 'Status Category Deleted Successfully.');
        $this->resetForm();
    }

    // public function openConfirmationModal()
    // {
    //     $this->showConfirmationModal = true;
    // }

    // public function closeConfirmationModal()
    // {
    //     $this->showConfirmationModal = false;
    // }

}
