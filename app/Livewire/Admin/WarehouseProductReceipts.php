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
use Illuminate\Support\Facades\Auth;

class WarehouseProductReceipts extends Component
{
    public $selectedProducts = [];
    public $clientId, $warehouseId, $date, $note;
    public $productQuantity = 1, $productPrice;
    public $showPForm = false, $productId, $products = [];
    public $showForm = false, $receptionId = null, $currency_id;
    public $clientSearch = '', $clientResults = [], $selectedClient = null, $clients = [];
    public $productResults = [], $selectedProduct = null, $productSearch = '';
    public $startDate, $endDate, $stockReceptions = [];
    public $isDirty = false, $showConfirmationModal = false;
    public $displayCurrency, $currencies, $warehouses = [];

    protected $clientService, $productService;
    protected $listeners = [
        'dateFilterUpdated' => 'updateDateFilter',
        'confirmClose'
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
        $this->warehouses = Warehouse::whereJsonContains('users', (string) Auth::id())->get();
    }

    public function render()
    {
        $this->clients = $this->clientService->searchClients($this->clientSearch);
        $this->load();
        return view('livewire.admin.warehouses.reception');
    }

    public function updateDateFilter($startDate, $endDate)
    {
        $this->startDate = $startDate;
        $this->endDate   = $endDate;
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

    public function openForm()
    {
        $this->resetForm();
        $this->showForm = true;
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
        $this->productPrice = $this->selectedProducts[$productId]['price'] ?? 0;
        $this->showPForm = true;
    }

    public function closePForm()
    {
        $this->reset(['productId', 'productQuantity', 'productPrice', 'showPForm']);
    }

    public function resetForm()
    {
        $this->reset([
            'clientId',
            'warehouseId',
            'selectedProducts',
            'note',
            'products',
            'currency_id',
            'selectedClient',
            'clientSearch',
            'showForm',
            'showPForm',
            'productQuantity',
            'productPrice',
            'receptionId'
        ]);
    }


    public function addProduct($productId)
    {
        $product = Product::find($productId);
        if ($product) {
            $this->selectedProducts[$productId] = [
                'name'     => $product->name,
                'quantity' => 1,
                'image'    => $product->image ?? null,
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
            'productPrice'    => 'required|numeric|min:0.01',
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
            'price'    => $this->productPrice,
            'image'    => $product->image ?? null,
        ];

        $this->closePForm();
    }

    public function saveReception()
    {
        $this->validate([
            'clientId'         => 'required|exists:clients,id',
            'warehouseId'      => 'required|exists:warehouses,id',
            'selectedProducts' => 'required|array|min:1',
            'note'             => 'nullable|string|max:255',
            'currency_id'      => 'required|exists:currencies,id',
        ]);

        $defaultCurrency = Currency::where('is_default', true)->first();
        $displayCurrency = Currency::where('is_currency_display', true)->first();
        $oldTotalAmount  = $this->receptionId
            ? (WarehouseProductReceipt::where('id', $this->receptionId)->value('converted_total') ?? 0)
            : 0;

        $reception = WarehouseProductReceipt::updateOrCreate(
            ['id' => $this->receptionId],
            [
                'supplier_id' => $this->clientId,
                'warehouse_id' => $this->warehouseId,
                'note'        => $this->note,
                'currency_id' => $this->currency_id,
            ]
        );

        $existingProducts = WarehouseProductReceiptProduct::where('receipt_id', $reception->id)
            ->pluck('product_id')
            ->toArray();
        $productsToDelete = array_diff($existingProducts, array_keys($this->selectedProducts));
        foreach ($productsToDelete as $productId) {
            $receiptProduct = WarehouseProductReceiptProduct::where('receipt_id', $reception->id)
                ->where('product_id', $productId)
                ->first();
            if ($receiptProduct) {
                WarehouseStock::where('warehouse_id', $this->warehouseId)
                    ->decrement('quantity', $receiptProduct->quantity);
                $receiptProduct->delete();
            }
        }

        // Обрабатываем продукты
        $totalAmount = 0;
        foreach ($this->selectedProducts as $productId => $details) {
            $prevReceipt = WarehouseProductReceiptProduct::where('receipt_id', $this->receptionId)
                ->where('product_id', $productId)
                ->first();

            $oldQuantity = $prevReceipt ? $prevReceipt->quantity : 0;
            WarehouseProductReceiptProduct::updateOrCreate(
                [
                    'receipt_id' => $reception->id,
                    'product_id' => $productId,
                ],
            );

            if ($prevReceipt) {
                $diff = $details['quantity'] - $oldQuantity;
                WarehouseStock::where('warehouse_id', $this->warehouseId)
                    ->increment('quantity', $diff);
            } else {
                WarehouseStock::updateOrCreate(
                    [
                        'warehouse_id' => $this->warehouseId,
                        'product_id'   => $productId,
                    ],
                    [
                        'quantity'     => $details['quantity']
                    ]
                );
            }

            $currency = Currency::find($this->currency_id);
            $convertedPrice = ($details['price'] / $currency->exchange_rate)
                * $defaultCurrency->exchange_rate
                * $displayCurrency->exchange_rate;
            ProductPrice::updateOrCreate(
                ['product_id' => $productId],
            );

            $totalAmount += $convertedPrice * $details['quantity'];
        }

        $reception->converted_total = $totalAmount;
        $reception->save();
        if ($this->receptionId) {
            $difference = $totalAmount - $oldTotalAmount;
            ClientBalance::updateOrCreate(
                ['client_id' => $this->clientId],
                ['balance'   => DB::raw("balance - {$difference}")]
            );
        } else {
            ClientBalance::updateOrCreate(
                ['client_id' => $this->clientId],
                ['balance'   => DB::raw('balance - ' . $totalAmount)]
            );
        }

        session()->flash('success', 'Оприходование успешно сохранено.');
        $this->resetForm();
        $this->isDirty = false;
        $this->closeForm();
    }

    public function edit($receptionId)
    {
        $reception = WarehouseProductReceipt::with('products.product')->findOrFail($receptionId);

        $this->receptionId  = $receptionId;
        $this->clientId     = $reception->supplier_id;
        $this->warehouseId  = $reception->warehouse_id;
        $this->note         = $reception->note;
        $this->currency_id  = $reception->currency_id;
        $this->selectedClient = $this->clientService->getClientById($this->clientId);
        $this->selectedProducts = [];
        foreach ($reception->products as $product) {
            $this->selectedProducts[$product->product_id] = [
                'name'     => $product->product->name,
                'quantity' => $product->quantity,
                'price'    => optional(ProductPrice::where('product_id', $product->product_id)->first())->purchase_price ?? 0,
                'image'    => $product->product->image ?? null,
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
                    ->decrement('quantity', $product->quantity);
            }

            ClientBalance::updateOrCreate(
                ['client_id' => $reception->supplier_id],
                ['balance' => DB::raw("balance + {$reception->converted_total}")]
            );

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
        $this->reset(['selectedClient', 'clientId', 'clientSearch', 'clientResults']);
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
        $this->productSearch = '';
        $this->productResults = [];
        $this->openPForm($productId);
    }

    public function deselectProduct()
    {
        $this->selectedProduct = null;
    }
    //поиск товара конец

    public function updated($propertyName)
    {
        $this->isDirty = true;
    }
}
