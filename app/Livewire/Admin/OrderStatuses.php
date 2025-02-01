<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\OrderStatus;
use App\Models\OrderStatusCategory; // Added import

class OrderStatuses extends Component
{
    public $statuses, $name, $category_id, $status_id, $categories = [];
    public $showForm = false; // Renamed from $isOpen
    public $showConfirmationModal = false; // Added for confirmation modal

    public function render()
    {
        $this->statuses = OrderStatus::with('category')->get();
        $this->categories = OrderStatusCategory::all(); // Fetch categories
        return view('livewire.admin.orders.order-statuses');
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
        $this->category_id = '';
        $this->status_id = null;
    }

    public function store()
    {
        $this->validate([
            'name' => 'required',
            'category_id' => 'required|exists:order_status_categories,id',
        ]);

        OrderStatus::updateOrCreate(['id' => $this->status_id], [
            'name' => $this->name,
            'category_id' => $this->category_id,
        ]);

        session()->flash(
            'message',
            $this->status_id ? 'Status Updated Successfully.' : 'Status Created Successfully.'
        );

        $this->closeForm();
        $this->resetInputFields();
    }

    public function edit($id)
    {
        $status = OrderStatus::findOrFail($id);
        $this->status_id = $id;
        $this->name = $status->name;
        $this->category_id = $status->category_id;

        $this->openForm(); // Updated method name
    }

    public function deleteStatusForm($id)
    {
        $this->status_id = $id;
        $this->openConfirmationModal(); // Open confirmation modal instead of deleting immediately
    }

    public function confirmDelete()
    {
        OrderStatus::find($this->status_id)->delete();
        session()->flash('message', 'Status Deleted Successfully.');
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
