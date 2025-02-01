<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\Category;

class WarehouseOperations extends Component
{
    public $selectedWarehouse;
    public $categoryFilter;
    public $stockData = [];

    public function mount()
    {
        $this->selectedWarehouse = null;
        $this->categoryFilter = null;
        $this->loadStockData();
    }

    public function updatedSelectedWarehouse()
    {
        $this->loadStockData();
    }

    public function updatedCategoryFilter()
    {
        $this->loadStockData();
    }

    public function loadStockData()
    {
        $query = WarehouseStock::query()
            ->with(['product.category', 'warehouse'])
            ->when($this->selectedWarehouse, function ($q) {
                $q->where('warehouse_id', $this->selectedWarehouse);
            });

        if ($this->categoryFilter) {
            $query->whereHas('product.category', function ($q) {
                $q->where('id', $this->categoryFilter);
            });
        }

        $this->stockData = $query->get()->map(function ($stock) {
            return [
             
                'name' => $stock->product->name,
                'quantity' => $stock->quantity,
                'category' => $stock->product->category->name,
                'warehouse' => $stock->warehouse->name,
            ];
        });
    }

    public function render()
    {
        return view('livewire.admin.warehouses.operations', [
            'warehouses' => Warehouse::all(),
            'categories' => Category::all(),
        ]);
    }
}
