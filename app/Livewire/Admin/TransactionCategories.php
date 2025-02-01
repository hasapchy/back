<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\TransactionCategory;

class TransactionCategories extends Component
{
    public $name;
    public $type;
    public $showForm = false;
    public $categories;
    public $categoryId;

    protected $rules = [
        'name' => 'required|string|max:255',
        'type' => 'required|boolean', // Updated to boolean
    ];

    public function mount()
    {
        $this->categories = TransactionCategory::all();
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

    public function submit()
    {
        $this->validate();

        TransactionCategory::updateOrCreate(
            ['id' => $this->categoryId],
            [
                'name' => $this->name,
                'type' => $this->type,
            ]
        );

        session()->flash('message', 'Категория транзакций успешно сохранена.');
        $this->categories = TransactionCategory::all(); // Refresh the categories list
        $this->closeForm();
    }

    public function edit($id)
    {
        $category = TransactionCategory::findOrFail($id);
        $this->categoryId = $category->id;
        $this->name = $category->name;
        $this->type = $category->type;
        $this->showForm = true;
    }

    public function confirmDelete($id)
    {
        $this->categoryId = $id;
        $this->emit('showDeleteConfirmationModal');
    }

    public function delete()
    {
        TransactionCategory::findOrFail($this->categoryId)->delete();
        session()->flash('message', 'Категория транзакций успешно удалена.');
        $this->categories = TransactionCategory::all(); // Refresh the categories list
        $this->emit('hideDeleteConfirmationModal');
    }

    public function resetForm()
    {
        $this->categoryId = null;
        $this->name = '';
        $this->type = 1; // Set a default value for type (1 for income)
    }

    public function render()
    {
        return view('livewire.admin.transaction-category-create');
    }
}
