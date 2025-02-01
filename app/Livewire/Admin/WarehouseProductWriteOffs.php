<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\WarehouseStock;
use App\Models\WarehouseProductWriteOff;
use App\Models\WarehouseProductWriteOffProduct;
use App\Services\ProductService;
use Illuminate\Support\Facades\DB;

class WarehouseProductWriteOffs extends Component
{
    public $selectedWarehouse;
    public $selectedProducts = [];
    public $note;
    public $date;
    public $stockWriteOffs;
    public $showPForm = false;
    public $productQuantity = 1;
    public $productId;
    public $warehouseProducts = [];
    public $warehouses = [];
    public $showForm = false;
    public $showConfirmationModal = false;
    public $writeOffId;
    public $startDate;
    public $endDate;
    public $isDirty = false;
    public $selectedProduct;
    public $productResults = [];
    public $productSearch = '';
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
        $this->date = now()->format('Y-m-d');
        $this->load();
        $this->warehouses = Warehouse::all();
    }

    public function render()
    {
        // if ($this->selectedWarehouse != null && $this->selectedWarehouse != '') {
        //     $this->updatedSelectedWarehouse();
        // }

        $this->load();
        $this->loadProducts();
        return view('livewire.admin.warehouses.write-offs', [

            // 'products' => $this->warehouseProducts,
        ]);
    }


    public function openForm()
    {
        $this->resetForm();
        $this->showForm = true;
        $this->isDirty = false;
    }

    public function closeForm()
    {
        if ($this->showPForm) {
            return;
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
            $this->isDirty = false;
            $this->showForm = false;
        }
        $this->showConfirmationModal = false;
    }

    public function openPForm($productId)
    {
        $this->productId = $productId;
        $this->productQuantity = $this->selectedProducts[$productId]['quantity'] ?? 1;
        $this->showPForm = true;
    }

    public function closePForm()
    {
        $this->productId = null;
        $this->productQuantity = 1;
        $this->showPForm = false;
    }

    public function updated($propertyName)
    {
        $this->isDirty = true;
    }


    public function updatedSelectedWarehouse()
    {
        $this->loadProducts();
    }

    public function load()
    {
        $query = WarehouseProductWriteOff::with([
            'warehouse',
            'writeOffProducts.product',
        ])
            ->orderBy('created_at', 'desc');

        if ($this->startDate && $this->endDate) {
            $query->whereBetween('created_at', [$this->startDate, $this->endDate]);
        }

        $this->stockWriteOffs = $query->get();
    }

    public function loadProducts()
    {
        if ($this->selectedWarehouse) {
            $query = WarehouseStock::where('warehouse_id', $this->selectedWarehouse)
                ->with('product')
                ->where('quantity', '>', 0);

            $this->warehouseProducts = $query->get();
        } else {
            $this->warehouseProducts = [];
        }
    }

    public function addProduct($productId)
    {
        $stock = WarehouseStock::where('warehouse_id', $this->selectedWarehouse)
            ->where('product_id', $productId)
            ->with('product')
            ->first();


        $this->selectedProducts[$productId] = [
            'name' => $stock->product->name,
            'quantity' => 1,
        ];

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

        if ($this->productQuantity <= 0) {
            session()->flash('error', 'Количество товара должно быть больше нуля.');
            return;
        }

        $quantity = $this->productQuantity;
        $this->selectedProducts[$this->productId] = [
            'name' => Product::find($this->productId)->name,
            'quantity' => $quantity,
        ];

        $this->closePForm();
    }

    public function saveWriteOff()
    {
        $this->validate([
            'selectedWarehouse' => 'required|exists:warehouses,id',
            'note' => 'required|string|max:255',
            'selectedProducts' => 'required|array|min:1',
        ]);

        // Проверяем наличие всех товаров перед началом транзакции
        foreach ($this->selectedProducts as $productId => $details) {
            $stock = WarehouseStock::where('warehouse_id', $this->selectedWarehouse)
                ->where('product_id', $productId)
                ->first();

            if (!$stock || $stock->quantity < $details['quantity']) {
                session()->flash('error', "Недостаточно товара на складе для списания: {$details['name']}");
                return;
            }
        }

        DB::beginTransaction();

        try {
            if ($this->writeOffId) {
                $originalWriteOff = WarehouseProductWriteOff::with('writeOffProducts')->findOrFail($this->writeOffId);
                foreach ($originalWriteOff->writeOffProducts as $originalProduct) {
                    $stock = WarehouseStock::where('warehouse_id', $originalWriteOff->warehouse_id)
                        ->where('product_id', $originalProduct->product_id)
                        ->first();
                    if ($stock) {
                        $stock->increment('quantity', $originalProduct->quantity);
                    }
                }
            }

            $writeOff = WarehouseProductWriteOff::updateOrCreate(
                ['id' => $this->writeOffId],
                [
                    'warehouse_id' => $this->selectedWarehouse,
                    'note' => $this->note,
                ]
            );

            if ($this->writeOffId) {
                $writeOff->writeOffProducts()->delete();
            }

            foreach ($this->selectedProducts as $productId => $details) {
                WarehouseProductWriteOffProduct::create([
                    'write_off_id' => $writeOff->id,
                    'product_id' => $productId,
                    'quantity' => $details['quantity'],
                ]);

                $stock = WarehouseStock::where('warehouse_id', $this->selectedWarehouse)
                    ->where('product_id', $productId)
                    ->first();
                $stock->decrement('quantity', $details['quantity']);
            }

            DB::commit();

            session()->flash('success', 'Списание успешно выполнено.');
            $this->resetForm();
            $this->isDirty = false;
            $this->showForm = false;
        } catch (\Exception $e) {
            DB::rollBack();
            session()->flash('error', 'Ошибка при списании: ' . $e->getMessage());
        }
    }

    public function edit($writeOffId)
    {
        $writeOff = WarehouseProductWriteOff::with('writeOffProducts')->findOrFail($writeOffId);
        $this->selectedWarehouse = $writeOff->warehouse_id;
        $this->note = $writeOff->note;
        $this->selectedProducts = [];

        foreach ($writeOff->writeOffProducts as $product) {
            $this->selectedProducts[$product->product_id] = [
                'name' => $product->product->name,
                'quantity' => $product->quantity,
            ];
        }

        $this->writeOffId = $writeOffId;
        $this->showForm = true;
    }

    public function deleteWriteOff()
    {
        if ($this->writeOffId) {
            $writeOff = WarehouseProductWriteOff::with('writeOffProducts')->findOrFail($this->writeOffId);

            foreach ($writeOff->writeOffProducts as $product) {
                $productId = $product->product_id;
                $quantity = $product->quantity;

                $stock = WarehouseStock::where('warehouse_id', $writeOff->warehouse_id)
                    ->where('product_id', $productId)
                    ->first();

                if ($stock) {
                    $stock->update(['quantity' => $stock->quantity + $quantity]);
                }
            }

            $writeOff->delete();
            session()->flash('success', 'Списание успешно удалено.');
            $this->resetForm();
            $this->load();
        } else {
            session()->flash('error', 'Не удалось найти списание для удаления.');
        }
    }

    public function updateDateFilter($startDate, $endDate)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->load();
    }

    //поиск товара начало
    public function showAllProducts()
    {
        $this->productResults = $this->productService->searchProductsByWarehouse('', $this->selectedWarehouse);
    }

    public function updatedProductSearch()
    {
        $this->productResults = $this->productService->searchProductsByWarehouse($this->productSearch, $this->selectedWarehouse);
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

    public function resetForm()
    {
        $this->reset(['selectedWarehouse', 'selectedProducts', 'note', 'productSearch', 'warehouseProducts']);
        $this->writeOffId = null;
        $this->showForm = false;
    }
}
