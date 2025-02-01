<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Category;
use App\Models\Product;

class Categories extends Component
{
    public $name, $parent_id, $categoryId;
    public $showForm = false;
    public $showConfirmationModal = false;
    public $columns = [
        'name',             // Название
        'parent'   // Родительская категория
    ];
    public $isDirty = false;          


    public function resetForm()
    {
        $this->categoryId = null;
        $this->name = '';
        $this->parent_id = null;
        $this->showForm = false;
    }

    public function updated($propertyName)
    {
        $this->isDirty = true;
    }

    public function openForm()
    {
        $this->resetForm();
        $this->showForm = true;
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
            $this->isDirty = false;    // Reset dirty status
            $this->showForm = false;   // Ensure the form is hidden
        }
        $this->showConfirmationModal = false;
    }

    public function closeModal($confirm = false)
    {
        if ($confirm) {
            $this->resetForm();
        }
        $this->showConfirmationModal = false;
    }

    public function isFormChanged()
    {
        $category = Category::find($this->categoryId);
        return $this->name !== ($category->name ?? '') || $this->parent_id !== ($category->parent_id ?? null);
    }

    public function saveCategory()
    {
        $rules = [
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
        ];

        $this->validate($rules);

        $category = Category::updateOrCreate(
            ['id' => $this->categoryId],
            [
                'name' => $this->name,
                'parent_id' => $this->parent_id,
            ]
        );

        session()->flash('success', $this->categoryId ? 'Категория обновлена.' : 'Категория добавлена.');
        $this->dispatch('updated');
        $this->dispatch('categorySaved', id: $category->id);
        $this->resetForm();
    }

    public function editCategory($id)
    {
        $category = Category::findOrFail($id);
        $this->categoryId = $category->id;
        $this->name = $category->name;
        $this->parent_id = $category->parent_id;
        $this->showForm = true;
    }

    public function hasProducts($categoryId)
    {
        return Product::where('category_id', $categoryId)->exists();
    }

    public function deleteCategory($id)
    {
        if ($this->hasProducts($id)) {
            session()->flash('error', 'Невозможно удалить категорию, так как к ней привязаны товары.');
            return;
        }

        Category::findOrFail($id)->delete();
        $this->dispatch('deleted');
        $this->showForm = false;
        session()->flash('success', 'Категория удалена.');
    }

    public function render()
    {
        return view('livewire.admin.categories', [
            'categories' => Category::with('parent')->get(),
            'allCategories' => Category::all(),
        ]);
    }
}
