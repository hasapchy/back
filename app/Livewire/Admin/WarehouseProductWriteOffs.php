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
use Illuminate\Support\Facades\Auth;

class WarehouseProductWriteOffs extends Component
{
    public $warehouseId, $selectedProducts = [], $note, $date, $stockWriteOffs, $showPForm = false, $productQuantity = 1;
    public $productId, $warehouseProducts = [], $warehouses = [], $showForm = false, $showConfirmationModal = false;
    public $writeOffId, $startDate, $endDate, $isDirty = false, $selectedProduct, $productResults = [], $productSearch = '';
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
        $this->warehouses = Warehouse::whereJsonContains('users', (string) Auth::id())->get();
        $this->loadWriteOffs();
        $this->loadProducts();
    }

    public function render()
    {
        return view('livewire.admin.warehouses.write-offs');
    }

    public function loadWriteOffs()
    {
        $query = WarehouseProductWriteOff::with(['warehouse', 'writeOffProducts.product'])
            ->orderBy('created_at', 'desc');

        if ($this->startDate && $this->endDate) {
            $query->whereBetween('created_at', [$this->startDate, $this->endDate]);
        }
        $this->stockWriteOffs = $query->get();
    }

    public function loadProducts()
    {
        $this->warehouseProducts = $this->warehouseId
            ? WarehouseStock::with('product')
            ->where('warehouse_id', $this->warehouseId)
            ->where('quantity', '>', 0)
            ->get()
            : [];
    }

    public function openForm()
    {
        $this->resetForm();
        $this->showForm = true;
        $this->isDirty = false;
    }

    public function closeForm()
    {
        if ($this->showPForm) return;
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
        $this->reset(['productId', 'productQuantity',  'showPForm']);
    }

    public function updated($propertyName)
    {
        $this->isDirty = true;
    }

    public function updatedwarehouseId()
    {
        $this->loadProducts();
    }

    public function addProduct($productId)
    {
        $stock = WarehouseStock::with('product')
            ->where('warehouse_id', $this->warehouseId)
            ->where('product_id', $productId)
            ->first();

        if ($stock) {
            $this->selectedProducts[$productId] = [
                'name'     => $stock->product->name,
                'quantity' => 1,
                'image'    => $stock->product->image ?? null,
            ];
            $this->openPForm($productId);
        }
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

        $product = Product::find($this->productId);
        if (!$product) return;

        $this->selectedProducts[$this->productId] = [
            'name'     => $product->name,
            'quantity' => $this->productQuantity,
            'image'    => $product->image ?? null,
        ];
        $this->closePForm();
    }

    public function save()
    {
        $this->validate([
            'warehouseId' => 'required|exists:warehouses,id',
            'note'              => 'required|string|max:255',
            'selectedProducts'  => 'required|array|min:1',
        ]);

        // Проверка наличия достаточного количества товара на складе
        foreach ($this->selectedProducts as $productId => $details) {
            $stock = WarehouseStock::where('warehouse_id', $this->warehouseId)
                ->where('product_id', $productId)
                ->first();
            if (!$stock || $stock->quantity < $details['quantity']) {
                session()->flash('error', "Недостаточно товара для списания: {$details['name']}");
                return;
            }
        }

        DB::beginTransaction();
        try {
            // Если редактируем списание - возвращаем товар на склад
            if ($this->writeOffId) {
                $original = WarehouseProductWriteOff::with('writeOffProducts')->findOrFail($this->writeOffId);
                foreach ($original->writeOffProducts as $origProduct) {
                    WarehouseStock::where('warehouse_id', $original->warehouse_id)
                        ->where('product_id', $origProduct->product_id)
                        ->increment('quantity', $origProduct->quantity);
                }
            }

            $writeOff = WarehouseProductWriteOff::updateOrCreate(
                ['id' => $this->writeOffId],
                [
                    'warehouse_id' => $this->warehouseId,
                    'note'         => $this->note,
                ]
            );

            // Если редактирование, удаляем старые записи
            if ($this->writeOffId) {
                $writeOff->writeOffProducts()->delete();
            }

            // Создаем новые записи и обновляем остатки
            foreach ($this->selectedProducts as $productId => $details) {
                WarehouseProductWriteOffProduct::create([
                    'write_off_id' => $writeOff->id,
                    'product_id'   => $productId,
                    'quantity'     => $details['quantity'],
                ]);

                WarehouseStock::where('warehouse_id', $this->warehouseId)
                    ->where('product_id', $productId)
                    ->decrement('quantity', $details['quantity']);
            }

            DB::commit();
            session()->flash('success', 'Списание успешно выполнено.');
            $this->resetForm();
            $this->isDirty = false;
            $this->showForm = false;
            $this->loadWriteOffs();
        } catch (\Exception $e) {
            DB::rollBack();
            session()->flash('error', 'Ошибка при списании: ' . $e->getMessage());
        }
    }

    public function edit($writeOffId)
    {
        $writeOff = WarehouseProductWriteOff::with('writeOffProducts.product')->findOrFail($writeOffId);
        $this->warehouseId = $writeOff->warehouse_id;
        $this->note = $writeOff->note;
        $this->selectedProducts = [];
        foreach ($writeOff->writeOffProducts as $product) {
            $this->selectedProducts[$product->product_id] = [
                'name'     => $product->product->name,
                'quantity' => $product->quantity,
                'image'    => $product->product->image ?? null,
            ];
        }
        $this->writeOffId = $writeOffId;
        $this->showForm = true;
    }
    public function delete()
    {
        if (!$this->writeOffId) {
            session()->flash('error', 'Не найдено списание для удаления.');
            return;
        }

        $writeOff = WarehouseProductWriteOff::with('writeOffProducts')->findOrFail($this->writeOffId);
        foreach ($writeOff->writeOffProducts as $product) {
            WarehouseStock::where('warehouse_id', $writeOff->warehouse_id)
                ->where('product_id', $product->product_id)
                ->increment('quantity', $product->quantity);
        }
        $writeOff->delete();
        session()->flash('success', 'Списание успешно удалено.');
        $this->resetForm();
        $this->loadWriteOffs();
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
        $this->productResults = $this->productService->searchProductsByWarehouse('', $this->warehouseId);
    }

    public function updatedProductSearch()
    {
        $this->productResults = $this->productService->searchProductsByWarehouse($this->productSearch, $this->warehouseId);
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
        $this->reset(['warehouseId', 'selectedProducts', 'note', 'productSearch', 'warehouseProducts']);
        $this->writeOffId = null;
        $this->showForm = false;
    }
}
