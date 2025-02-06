<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class Categories extends Component
{
    public $name, $parent_id, $categories, $allCategories, $categoryId, $users, $allUsers;
    public $showForm = false, $showConfirmationModal = false, $isDirty = false;
    public $columns = [
        'name',
        'parent'
    ];

    public function mount()
    {
        $this->allUsers = User::all();
        $this->categories = Category::with('parent')->whereJsonContains('users', (string) Auth::id())->get();
        $this->allCategories = Category::whereJsonContains('users', (string) Auth::id())->get();
    }

    public function resetForm()
    {
        $this->categoryId = null;
        $this->name = '';
        $this->parent_id = null;
        $this->users = [];
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
            $this->isDirty = false;
            $this->showForm = false;
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

    public function save()
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
                'users' => $this->users,
                'user_id' => Auth::id(),
            ]
        );

        session()->flash('success', $this->categoryId ? 'Категория обновлена.' : 'Категория добавлена.');
        $this->dispatch('updated');
        $this->dispatch('categorySaved', id: $category->id);
        $this->resetForm();
    }

    public function edit($id)
    {
        $category = Category::findOrFail($id);
        $this->categoryId = $category->id;
        $this->name = $category->name;
        $this->parent_id = $category->parent_id;
        $this->users = $category->users;
        $this->showForm = true;
    }

    public function hasProducts($categoryId)
    {
        return Product::where('category_id', $categoryId)->exists();
    }

    public function delete($id)
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
        return view('livewire.admin.categories');
    }
}
