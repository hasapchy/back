<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WarehouseProductMovement;
use App\Models\WarehouseProductMovementProduct;
use App\Services\ProductService;

class WarehouseProductMovements extends Component
{
    public $products = [];
    public $whFrom;
    public $whTo;
    public $selectedProducts = [];
    public $note;
    public $showPForm = false;
    public $productId;
    public $productQuantity = 1;
    public $warehouses;
    public $selectedProduct = null;
    public $stockMovements = [];
    public $showForm = false;
    public $showConfirmationModal = false;
    public $transferId;
    public $productSearch = '';
    public $productResults = [];
    public $startDate;
    public $endDate;
    public $isDirty = false;
    protected $productService;
    protected $listeners = [
        'dateFilterUpdated' => 'updateDateFilter',
        'confirmClose',
    ];

    public function boot(ProductService $productService)
    {

        $this->productService = $productService;
    }

    public function mount()
    {
        $this->load();
        $this->warehouses = Warehouse::all();
    }

    public function render()
    {
        $this->load();
        return view('livewire.admin.warehouses.transfer');
    }

    public function openForm()
    {
        $this->resetForm();
        $this->showForm = true;
        $this->isDirty = false; // Reset dirty status when opening form
    }

    public function closeForm()
    {
        if ($this->showPForm) {
            $this->showPForm = false;
        }
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
            $this->isDirty = false; // Reset dirty status
            $this->showForm = false; // Ensure the form is hidden
        }
        $this->showConfirmationModal = false;
    }

    public function openPForm($productId)
    {
        $this->productId = $productId;
        $this->productQuantity = $this->selectedProducts[$productId]['quantity'] ?? 1;
        $this->showPForm = true;
    }


    public function closePFrom()
    {
        $this->showPForm = false;
    }


    public function addProduct($productId)
    {
        if (!isset($this->selectedProducts[$productId])) {
            $product = Product::findOrFail($productId);
            $this->selectedProducts[$productId] = [
                'name' => $product->name,
                'quantity' => 1,

            ];
        }
        $this->openPForm($productId);
    }

    public function removeProduct($productId)
    {
        unset($this->selectedProducts[$productId]);
    }


    public function saveProductModal()
    {
        $this->validate([
            'productQuantity' => 'required|integer|min:1',
        ]);

        $this->selectedProducts[$this->productId]['quantity'] = $this->productQuantity;
        $this->showPForm = false;
    }

    public function updated($propertyName)
    {

        $this->isDirty = true;
    }


    public function resetForm()
    {
        $this->whFrom = null;
        $this->whTo = null;
        $this->selectedProducts = [];
        $this->note = null;
        $this->productId = null;
        $this->productQuantity = 1;
        $this->selectedProduct = null;
        $this->transferId = null;
        $this->showForm = false;
    }

    public function saveTransfer()
    {
        $this->validate([
            'whFrom' => 'required|exists:warehouses,id',
            'whTo' => 'required|exists:warehouses,id|different:whFrom',
            'selectedProducts' => 'required|array|min:1',
        ]);

        if ($this->transferId) {
            $movement = WarehouseProductMovement::with('products')->findOrFail($this->transferId);
            foreach ($movement->products as $movementProduct) {
                $productId = $movementProduct->product_id;
                $quantity = $movementProduct->quantity;

                $stockFrom = WarehouseStock::where('warehouse_id', $movement->warehouse_from)
                    ->where('product_id', $productId)
                    ->first();

                $stockTo = WarehouseStock::where('warehouse_id', $movement->warehouse_to)
                    ->where('product_id', $productId)
                    ->first();

                if ($stockFrom) {
                    $stockFrom->update(['quantity' => $stockFrom->quantity + $quantity]);
                } else {
                    WarehouseStock::create([
                        'warehouse_id' => $movement->warehouse_from,
                        'product_id' => $productId,
                        'quantity' => $quantity,
                    ]);
                }

                if ($stockTo) {
                    $stockTo->update(['quantity' => $stockTo->quantity - $quantity]);
                }

                $movementProduct->delete();
            }

            $movement->update([
                'warehouse_from' => $this->whFrom,
                'warehouse_to' => $this->whTo,
                'note' => $this->note,
            ]);
        } else {
            $movement = WarehouseProductMovement::create([
                'warehouse_from' => $this->whFrom,
                'warehouse_to' => $this->whTo,
                'note' => $this->note,
            ]);
        }

        foreach ($this->selectedProducts as $productId => $details) {
            $product = Product::find($productId);
            if (!$product) {
                session()->flash('error', "Товар с ID {$productId} не найден.");
                return;
            }

            $stockFrom = WarehouseStock::where('warehouse_id', $this->whFrom)
                ->where('product_id', $productId)
                ->first();

            if (!$stockFrom || $stockFrom->quantity < $details['quantity'] || $details['quantity'] <= 0) {
                session()->flash('error', "Недостаточно товара на складе для перемещения: {$details['name']}. Доступно: " . ($stockFrom->quantity ?? 0));
                return;
            }

            $stockFrom->update(['quantity' => $stockFrom->quantity - $details['quantity']]);

            $stockTo = WarehouseStock::firstOrCreate(
                ['warehouse_id' => $this->whTo, 'product_id' => $productId],
                ['quantity' => 0]
            );
            $stockTo->update(['quantity' => $stockTo->quantity + $details['quantity']]);

            WarehouseProductMovementProduct::create([
                'movement_id' => $movement->id,
                'product_id' => $productId,
                'quantity' => $details['quantity'],
            ]);
        }

        if ($this->transferId) {
            session()->flash('success', 'Перемещение успешно обновлено.');
        } else {
            session()->flash('success', 'Перемещение успешно выполнено.');
        }

        $this->resetForm();
        $this->closeForm();
    }

    public function edit($transferId)
    {
        $movement = WarehouseProductMovement::findOrFail($transferId);
        $this->whFrom = $movement->warehouse_from;
        $this->whTo = $movement->warehouse_to;
        $this->note = $movement->note;

        $this->selectedProducts = [];
        foreach ($movement->products as $movementProduct) {
            $product = Product::find($movementProduct->product_id);
            if ($product) {
                $this->selectedProducts[$product->id] = [
                    'name' => $product->name,
                    'quantity' => $movementProduct->quantity,
                ];
            } else {
                session()->flash('error', "Товар с ID {$movementProduct->product_id} не найден.");
            }
        }

        $this->transferId = $transferId;
        $this->showForm = true;
    }

    public function deleteTransfer()
    {
        if ($this->transferId) {
            $movement = WarehouseProductMovement::with('products')->findOrFail($this->transferId);

            foreach ($movement->products as $movementProduct) {
                $productId = $movementProduct->product_id;
                $details = $movementProduct->toArray();
                $stockFrom = WarehouseStock::where('warehouse_id', $movement->warehouse_from)
                    ->where('product_id', $productId)
                    ->first();

                $stockTo = WarehouseStock::where('warehouse_id', $movement->warehouse_to)
                    ->where('product_id', $productId)
                    ->first();

                if (!$stockTo || $stockTo->quantity < $movementProduct->quantity) {
                    session()->flash('error', "Недостаточно товара на складе-получателе для удаления перемещения: {$movementProduct->product->name}. Доступно: " . ($stockTo->quantity ?? 0));
                    return;
                }

                if ($stockFrom) {
                    $stockFrom->update(['quantity' => $stockFrom->quantity + $movementProduct->quantity]);
                } else {
                    WarehouseStock::create([
                        'warehouse_id' => $movement->warehouse_from,
                        'product_id' => $productId,
                        'quantity' => $movementProduct->quantity,
                    ]);
                }

                $stockTo->update(['quantity' => $stockTo->quantity - $movementProduct->quantity]);
            }

            $movement->delete();
            session()->flash('success', 'Перемещение успешно удалено.');
            $this->resetForm();
        }
    }

    //поиск товара начало
    public function showAllProducts()
    {
        $this->productResults = $this->productService->searchProductsByWarehouse('', $this->whFrom);
    }

    public function updatedProductSearch()
    {
        $this->productResults = $this->productService->searchProductsByWarehouse($this->productSearch, $this->whFrom);
    }

    public function selectProduct($productId)
    {
        $this->selectedProduct = $this->productService->getProductById($productId);
        $this->productSearch = ''; // Очищаем поле поиска
        $this->productResults = []; // Очищаем результаты поиска
        $this->openPForm($productId); // Добавляем товар в выбранные
    }

    public function deselectProduct()
    {
        $this->selectedProduct = null;
    }
    //поиск товара конец

    public function updateDateFilter($startDate, $endDate)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->load();
    }

    public function load()
    {
        $query = WarehouseProductMovement::with(['warehouseFrom', 'warehouseTo', 'products.product']);

        if ($this->startDate && $this->endDate) {
            $query->whereBetween('created_at', [$this->startDate, $this->endDate]);
        }

        $this->stockMovements = $query->latest()->get();
    }
}
