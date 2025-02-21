<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\OrderCategory;
use Illuminate\Support\Facades\Auth;

class OrderCategories extends Component
{
    public $categories, $name, $userId, $categoryId;
    public $showForm = false;
    // public $showConfirmationModal = false;

    public function render()
    {
        $this->categories = OrderCategory::all();
        return view('livewire.admin.orders.order-categories');
    }


    public function openForm()
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function closeForm()
    {
        $this->showForm = false;
    }

    private function resetForm()
    {
        $this->name = '';
        $this->categoryId = null;
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
        ]);
    
        OrderCategory::updateOrCreate(['id' => $this->categoryId], [
            'name' => $this->name,
            'user_id' => Auth::id(),
        ]);
    
        session()->flash(
            'message',
            $this->categoryId ? 'Категория успешно обновлена.' : 'Категория успешно создана.'
        );
    
        $this->closeForm();
        $this->resetForm();
    }

    public function edit($id)
    {
        $category = OrderCategory::findOrFail($id);
        $this->categoryId = $id;
        $this->name = $category->name;
        $this->userId = $category->user_id;
        $this->showForm = true;
    }

    public function delete($id)
    {
        OrderCategory::find($id)->delete();
        session()->flash('message', 'Категория успешно удалена.');
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
