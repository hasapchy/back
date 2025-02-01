<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\OrderCategory;
use Illuminate\Support\Facades\Auth;

class OrderCategories extends Component
{
    public $categories, $name, $user_id, $category_id;
    public $showForm = false;
    public $showConfirmationModal = false;

    public function render()
    {
        $this->categories = OrderCategory::all();
        return view('livewire.admin.orders.order-categories');
    }

    public function create()
    {
        $this->resetInputFields();
        $this->openForm();
    }

    public function openForm()
    {
        $this->showForm = true;
    }

    public function closeForm()
    {
        $this->showForm = false;
    }

    private function resetInputFields()
    {
        $this->name = '';
        $this->category_id = null;
    }

    public function store()
    {
        $this->validate([
            'name' => 'required',
        ]);

        OrderCategory::updateOrCreate(['id' => $this->category_id], [
            'name' => $this->name,
            'user_id' => Auth::id(),
        ]);

        session()->flash('message', 
            $this->category_id ? 'Категория успешно обновлена.' : 'Категория успешно создана.');

        $this->closeForm();
        $this->resetInputFields();
    }

    public function edit($id)
    {
        $category = OrderCategory::findOrFail($id);
        $this->category_id = $id;
        $this->name = $category->name;
        $this->user_id = $category->user_id;

        $this->openForm();
    }

    public function deleteCategoryForm($id)
    {
        $this->category_id = $id;
        $this->openConfirmationModal();
    }

    public function confirmDelete()
    {
        OrderCategory::find($this->category_id)->delete();
        session()->flash('message', 'Категория успешно удалена.');
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
}