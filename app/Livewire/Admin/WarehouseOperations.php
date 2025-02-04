<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\Category;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class WarehouseOperations extends Component
{
    public $warehouseId;
    public $categoryFilter;
    public $stockData = [];
    public $warehouses;
    public $categories;

    public function mount()
    {
        $this->warehouses = Cache::remember("user:warehouses:" . Auth::id(), now()->addMinutes(5), function () {
            return Warehouse::whereJsonContains('users', (string) Auth::id())->get();
        });

        $this->categories = Cache::remember('categories', now()->addMinutes(5), function () {
            return Category::all();
        });

        $this->loadStockData();
    }

    public function render()
    {
        return view('livewire.admin.warehouses.operations');
    }

    public function loadStockData()
    {
        $query = WarehouseStock::query()
            ->with([
                'product.category:id,name',
                'warehouse:id,name'
            ])
            ->when($this->warehouseId, fn($q) => $q->where('warehouse_id', $this->warehouseId))
            ->when($this->categoryFilter, function ($q) {
                $q->whereHas('product.category', fn($q) => $q->where('id', $this->categoryFilter));
            });

        $this->stockData = $query->get()->map(function ($stock) {
            return [
                'name'       => $stock->product->name,
                'quantity'   => $stock->quantity,
                'category'   => $stock->product->category->name,
                'warehouse'  => $stock->warehouse->name,
                'image'      => $stock->product->image,
            ];
        })->toArray();
    }

    public function updatedWarehouseId()
    {
        $this->loadStockData();
    }

    public function updatedCategoryFilter()
    {
        $this->loadStockData();
    }
}
