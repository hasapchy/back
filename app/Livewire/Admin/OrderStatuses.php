<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\OrderStatus;
use App\Models\OrderStatusCategory; // Added import

class OrderStatuses extends Component
{
    public $statuses, $name, $categoryId, $statusId, $categories = [];
    public $showForm = false;
    // public $showConfirmationModal = false; // Added for confirmation modal

    public function render()
    {
        $this->statuses = OrderStatus::with('category')->get();
        $this->categories = OrderStatusCategory::all();
        return view('livewire.admin.orders.order-statuses');
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
        // $this->reset('name', 'categoryId', 'statusId');
        $this->name = '';
        $this->categoryId = null;
        $this->statusId = null;
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'categoryId' => 'required|exists:order_status_categories,id',
        ]);

        OrderStatus::updateOrCreate(['id' => $this->statusId], [
            'name' => $this->name,
            'category_id' => $this->categoryId,
        ]);

        session()->flash(
            'message',
            $this->statusId ? 'Status Updated Successfully.' : 'Status Created Successfully.'
        );

        $this->closeForm();
    }

    public function edit($id)
    {
        $status = OrderStatus::findOrFail($id);
        $this->statusId = $status->$id;
        $this->name = $status->name;
        $this->categoryId = $status->category_id;
        $this->showForm = true;
    }

    public function delete($id)
    {
        OrderStatus::find($id)->delete();
        session()->flash('message', 'Status Deleted Successfully.');
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
