<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseProductReceipt;
use App\Models\WarehouseProductReceiptProduct;
use App\Models\WarehouseStock;
use App\Models\ClientBalance;
use App\Models\ProductPrice;
use App\Models\Currency;
use Illuminate\Support\Facades\DB;
use App\Services\ClientService;
use App\Services\ProductService;


class WarehouseProductReceipts extends Component
{
    public $selectedProducts = [];
    public $clientId;
    public $warehouseId;
    public $date;
    public $note;
    public $productQuantity = 1;
    public $productPrice;
    public $showPForm = false;
    public $productId;
    public $products = [];
    public $showForm = false;
    public $receptionId = null;
    public $currency_id;
    public $clientSearch = '';
    public $clientResults = [];
    public $selectedClient = null;
    public $clients = [];
    public $productResults = [];
    public $selectedProduct = null;
    public $productSearch = '';
    public $startDate;
    public $endDate;
    public $stockReceptions = [];
    public $isDirty = false;
    public $showConfirmationModal = false;
    public $displayCurrency;
    public $currencies;
    public $warehouses = [];
    protected $clientService;
    protected $productService;
    protected $listeners = [
        'dateFilterUpdated' => 'updateDateFilter',
        'confirmClose',
    ];

    public function boot(ClientService $clientService, ProductService $productService)
    {
        $this->clientService = $clientService;
        $this->productService = $productService;
    }

    public function mount()
    {
        $this->date = now()->format('Y-m-d');
        $this->displayCurrency = Currency::where('is_currency_display', true)->first();
        $this->currencies = Currency::all();
        $this->warehouses = Warehouse::all();
    }

    public function render()
    {
        $this->clients = $this->clientService->searchClients($this->clientSearch);
        $this->load();
        return view('livewire.admin.warehouses.reception');
    }

    public function openForm()
    {
        $this->resetForm();
        $this->showForm = true;
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
        $this->productPrice = $this->selectedProducts[$productId]['price'] ?? 0;
        $this->showPForm = true;
    }

    public function closePForm()
    {
        $this->showPForm = false;
        $this->productId = null;
        $this->productQuantity = 1;
        $this->productPrice = null;
    }


    public function addProduct($productId)
    {
        $stock = WarehouseStock::where('warehouse_id', $this->warehouseId)
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
            'productPrice' => 'required|numeric|min:0,01',
        ]);

        if ($this->productQuantity <= 0) {
            session()->flash('error', 'Количество товара должно быть больше нуля.');
            return;
        }
        $quantity = $this->productQuantity;
        $this->selectedProducts[$this->productId] = [
            'name' => Product::find($this->productId)->name,
            'quantity' => $quantity,
            'price' => $this->productPrice,
        ];

        $this->closePForm();
    }

    public function saveReception()
    {
        $this->validate([
            'clientId' => 'required|exists:clients,id',
            'warehouseId' => 'required|exists:warehouses,id',
            'selectedProducts' => 'required|array|min:1',
            'note' => 'nullable|string|max:255',
            'currency_id' => 'required|exists:currencies,id',
        ]);

        $totalAmount = 0;
        $displayCurrency = Currency::where('is_currency_display', true)->first();
        $defaultCurrency = Currency::where('is_default', true)->first();

        $oldTotalAmount = 0;
        if ($this->receptionId) {
            $oldTotalAmount = WarehouseProductReceipt::where('id', $this->receptionId)->value('converted_total') ?? 0;
        }

        $reception = WarehouseProductReceipt::updateOrCreate(
            ['id' => $this->receptionId],
            [
                'supplier_id' => $this->clientId,
                'warehouse_id' => $this->warehouseId,
                'note' => $this->note,
                'currency_id' => $this->currency_id,
            ]
        );
        $existingProducts = WarehouseProductReceiptProduct::where('receipt_id', $reception->id)
            ->pluck('product_id')
            ->toArray();

        $productsToDelete = array_diff($existingProducts, array_keys($this->selectedProducts));
        foreach ($productsToDelete as $productId) {
            $productReceipt = WarehouseProductReceiptProduct::where('receipt_id', $reception->id)
                ->where('product_id', $productId)
                ->first();

            if ($productReceipt) {
                // Уменьшаем количество на складе
                WarehouseStock::where('warehouse_id', $this->warehouseId)
                    ->where('product_id', $productId)
                    ->decrement('quantity', $productReceipt->quantity);

                // Удаляем запись о товаре
                $productReceipt->delete();
            }
        }

        // Обновляем или добавляем товары
        foreach ($this->selectedProducts as $productId => $details) {
            $previousProductReceipt = null;
            $oldQuantity = 0;

            if ($this->receptionId) {
                $previousProductReceipt = WarehouseProductReceiptProduct::where('receipt_id', $this->receptionId)
                    ->where('product_id', $productId)
                    ->first();
                $oldQuantity = $previousProductReceipt ? $previousProductReceipt->quantity : 0;
            }

            $productReceipt = WarehouseProductReceiptProduct::updateOrCreate(
                [
                    'receipt_id' => $reception->id,
                    'product_id' => $productId,
                ],
                [
                    'quantity' => $details['quantity'],
                ]
            );

            if ($previousProductReceipt) {
                $difference = $details['quantity'] - $oldQuantity;
                WarehouseStock::where('warehouse_id', $this->warehouseId)
                    ->where('product_id', $productId)
                    ->increment('quantity', $difference);
            } else {
                WarehouseStock::updateOrCreate(
                    [
                        'warehouse_id' => $this->warehouseId,
                        'product_id' => $productId,
                    ],
                    [
                        'quantity' => DB::raw('quantity + ' . $details['quantity']),
                    ]
                );
            }

            $currency = Currency::find($this->currency_id);
            $convertedPrice = $details['price'] / $currency->exchange_rate * $defaultCurrency->exchange_rate * $displayCurrency->exchange_rate;

            ProductPrice::updateOrCreate(
                [
                    'product_id' => $productId,
                ],
                [
                    'purchase_price' => $details['price'],
                    'currency_id' => $this->currency_id,
                ]
            );

            $totalAmount += $convertedPrice * $details['quantity'];
        }

        $reception->converted_total = $totalAmount;
        $reception->save();

        if ($this->receptionId) {
            $difference = $totalAmount - $oldTotalAmount;
            ClientBalance::updateOrCreate(
                ['client_id' => $this->clientId],
                ['balance' => DB::raw("balance - {$difference}")]
            );
        } else {
            ClientBalance::updateOrCreate(
                ['client_id' => $this->clientId],
                ['balance' => DB::raw('balance - ' . $totalAmount)]
            );
        }

        session()->flash('success', 'Оприходование успешно сохранено.');
        $this->resetForm();
        $this->isDirty = false;
        $this->closeForm();
    }

    public function edit($receptionId)
    {
        $reception = WarehouseProductReceipt::findOrFail($receptionId);

        $this->receptionId = $receptionId;
        $this->clientId = $reception->supplier_id;
        $this->warehouseId = $reception->warehouse_id;
        $this->note = $reception->note;
        $this->currency_id = $reception->currency_id;
        $this->selectedClient = $this->clientService->getClientById($this->clientId);
        $this->selectedProducts = [];
        foreach ($reception->products as $product) {
            $productPrice = ProductPrice::where('product_id', $product->product_id)->first();
            $this->selectedProducts[$product->product_id] = [
                'name' => $product->product->name,
                'quantity' => $product->quantity,
                'price' => $productPrice ? $productPrice->purchase_price : 0,
            ];
        }
        $this->showForm = true;
    }

    public function deleteReception()
    {
        if ($this->receptionId) {
            $reception = WarehouseProductReceipt::findOrFail($this->receptionId);
            foreach ($reception->products as $product) {
                WarehouseStock::where('warehouse_id', $reception->warehouse_id)
                    ->where('product_id', $product->product_id)
                    ->decrement('quantity', $product->quantity);
            }

            $supplierBalance = ClientBalance::where('client_id', $reception->supplier_id)->first();
            if ($supplierBalance) {
                $supplierBalance->balance += $reception->converted_total;
                $supplierBalance->save();
            }

            $reception->delete();

            session()->flash('success', 'Оприходование успешно удалено.');
            $this->resetForm();
            $this->closeForm();
        }
    }

    //поиск клиента

    public function showAllClients()
    {
        $this->clientResults = $this->clientService->getAllClients();
    }

    public function updatedClientSearch()
    {
        $this->clientResults = $this->clientService->searchClients($this->clientSearch);
    }

    public function selectClient($clientId)
    {
        $this->selectedClient = $this->clientService->getClientById($clientId);
        $this->clientId = $clientId;
        $this->clientResults = [];
    }

    public function deselectClient()
    {
        $this->selectedClient = null;
        $this->clientId = null;
        $this->clientSearch = '';
        $this->clientResults = [];
    }

    //поиск клиента конец

    //поиск товара начало
    public function showAllProducts()
    {
        $this->productResults = $this->productService->getAllProducts();
    }

    public function updatedProductSearch()
    {
        $this->productResults = $this->productService->searchProducts($this->productSearch);
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
        $query = WarehouseProductReceipt::with(['supplier', 'warehouse']);

        if ($this->startDate && $this->endDate) {
            $query->whereBetween('created_at', [$this->startDate, $this->endDate]);
        }

        $this->stockReceptions = $query->latest()->get();
    }

    public function resetForm()
    {
        $this->clientId = null;
        $this->warehouseId = null;
        $this->selectedProducts = [];
        $this->note = null;
        $this->products = [];
        $this->currency_id = null;
        $this->selectedClient = null;
        $this->clientSearch = '';
        $this->showForm = false;
        $this->showPForm = false;
        $this->productQuantity = 1;
        $this->productPrice = null;
        $this->receptionId = null;
    }

    public function updated($propertyName)
    {
        $this->isDirty = true;
    }
}
