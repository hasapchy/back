<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\OrderAf;
use App\Models\OrderCategory;
use Illuminate\Support\Facades\Auth;

class Af extends Component
{
    public $name;
    public $type;
    public $category_ids = [];
    public $required = false;
    public $default;
    public $user_id;
    public $fields;
    public $categories;
    public $field_id;
    public $showForm = false;
    public $showConfirmationModal = false;

    protected $rules = [
        'name' => 'required|string|max:255',
        'type' => 'required|in:int,string',
        'category_ids' => 'required|array',
        'required' => 'boolean',
        'default' => 'nullable|string',
    ];

    public function mount()
    {
        $this->fields = OrderAf::all();
        $this->categories = OrderCategory::all();
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

    public function resetForm()
    {
        $this->name = '';
        $this->type = '';
        $this->category_ids = [];
        $this->required = false;
        $this->default = '';
        $this->field_id = null;
    }

    public function store()
    {
        $this->validate();

        if ($this->type === 'int' && !is_numeric($this->default)) {
            $this->addError('default', 'Значение по умолчанию должно быть числом.');
            return;
        }

        if ($this->type === 'string' && !is_string($this->default)) {
            $this->addError('default', 'Значение по умолчанию должно быть строкой.');
            return;
        }

        OrderAf::updateOrCreate(['id' => $this->field_id], [
            'name' => $this->name,
            'type' => $this->type,
            'category_ids' => $this->category_ids,
            'required' => $this->required,
            'default' => $this->default,
            'user_id' => Auth::id()
        ]);

        $this->fields = OrderAf::all();
        $this->closeForm();
        session()->flash('message', 'Поле успешно сохранено.');
    }

    public function edit($id)
    {
        $field = OrderAf::findOrFail($id);
        $this->field_id = $field->id;
        $this->name = $field->name;
        $this->type = $field->type;
        $this->category_ids = $field->category_ids;
        $this->required = $field->required;
        $this->default = $field->default;
        $this->showForm = true;
    }

    public function deleteField($id)
    {
        $this->field_id = $id;
        $this->showConfirmationModal = true;
    }

    public function closeConfirmationModal()
    {
        $this->showConfirmationModal = false;
    }

    public function confirmDelete()
    {
        OrderAf::destroy($this->field_id);
        $this->fields = OrderAf::all();
        $this->closeConfirmationModal();
        session()->flash('message', 'Поле успешно удалено.');
    }

    public function render()
    {
        return view('livewire.admin.orders.af');
    }
}
