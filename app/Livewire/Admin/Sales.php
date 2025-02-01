<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Product;
use App\Models\Client;
use App\Models\Warehouse;
use App\Models\Sale;
use App\Models\SalesProduct;
use App\Models\WarehouseStock;
use App\Models\ClientBalance;
use App\Models\Currency;
use App\Models\CashRegister;
use App\Models\ProductPrice;
use App\Models\FinancialTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\ClientService;
use App\Services\ProductService;

class Sales extends Component
{

    public $selectedProducts = [];
    public $clientId;
    public $warehouseId;
    // public $invoiceNumber;
    public $date;
    public $note;
    public $whFrom;
    public $productQuantity = 1;
    public $productPrice;
    public $productDiscount = 0;
    public $showPForm = false;
    public $productId = null;
    public $invoiceString = '';
    public $invoiceDate;
    public $showConfirmationModal = false;
    public $products = [];
    public $showForm = false;
    public $saleId = null;
    public $clients = [];
    public $currency_id;
    public $clientSearch = '';
    public $clientResults = [];
    public $selectedClient = null;
    public $productSearch = '';
    public $cash_register_id;
    public $cashRegisters = [];
    public $isDirty = false;
    public $productStock;
    public $discountType = 'fixed';
    public $displayCurrency;
    public $productResults = [];
    public $selectedProduct;
    public $currencies;
    public $sales;
    public $warehouses;
    protected $clientService;
    protected $productService;

    public function boot(ClientService $clientService, ProductService $productService)
    {
        $this->clientService = $clientService;
        $this->productService = $productService;
    }

    public function render()
    {

        $this->clients = $this->clientService->searchClients($this->clientSearch);
        return view('livewire.admin.sales');
    }

    public function mount()
    {
        $this->date = now()->format('Y-m-d');
        $this->currencies = Currency::all();
        $this->displayCurrency = Currency::where('is_currency_display', true)->first();
        $this->cashRegisters = CashRegister::all();
        $this->sales = Sale::with(['client', 'warehouse'])->latest()->get();
        $this->warehouses = Warehouse::all();
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
        // $this->productId = isset($this->selectedProducts[$productId]) ? $productId : null;
        $product = Product::findOrFail($productId);
        $productPrice = ProductPrice::where('product_id', $productId)->first();
        $this->productQuantity = $this->selectedProducts[$productId]['quantity'] ?? 1;
        $this->productPrice = $this->selectedProducts[$productId]['price'] ?? $productPrice->retail_price;
        $this->productDiscount = $this->selectedProducts[$productId]['discount'] ?? 0;
        $this->discountType = 'fixed';
        $this->showPForm = true;
    }


    public function closePForm()
    {
        $this->showPForm = false;
        $this->whFrom = null;
        $this->productId = null;
        $this->productQuantity = 1;
        $this->productPrice = null;
        $this->productDiscount = 0;
    }

    public function addProduct($productId)
    {
        $product = Product::findOrFail($productId);

        $this->selectedProducts[$productId] = [
            'name' => $product->name ?? 'Название недоступно',
            'quantity' => 1,
            'price' => 0,
            'discount' => 0,
            'warehouse_id' => $this->warehouseId,
        ];

        $this->openPForm($productId);
    }

    public function removeProduct($productId)
    {
        unset($this->selectedProducts[$productId]);

        // if ($this->saleId) {
        //     $productSale = SalesProduct::where('sale_id', $this->saleId)
        //         ->where('product_id', $productId)
        //         ->first();

        //     if ($productSale) {
        //         WarehouseStock::where('warehouse_id', $productSale->warehouse_id)
        //             ->where('product_id', $productId)
        //             ->increment('quantity', $productSale->quantity);
        //         $productSale->delete();
        //     }
        // }
    }


    public function saveProductModal()
    {
        $this->validate([
            'productQuantity' => 'required|integer|min:1',
            'productPrice' => 'required|numeric|min:0.01',
            'productDiscount' => 'nullable|numeric|min:0',
        ]);

        if ($this->productQuantity <= 0) {
            session()->flash('error', 'Количество товара должно быть больше нуля.');
            return;
        }


        $productStock = WarehouseStock::where('product_id', $this->productId)
            ->sum('quantity');

        if ($this->productQuantity > $productStock) {
            session()->flash('message', 'Количество товара не может превышать количество на складе.');
            return;
        }

        $quantity = $this->productQuantity;
        $price = $this->productPrice;
        $discount = $this->productDiscount;

        if ($this->discountType == 'percent') {
            $discountAmount = ($price * $discount) / 100;
        } else {
            $discountAmount = $discount;
        }

        if ($discountAmount >= $price) {
            session()->flash('error', 'Скидка не может быть больше или равна цене.');
            return;
        }

        $finalPrice = $price - $discountAmount;

        $this->selectedProducts[$this->productId] = [
            'name' => Product::find($this->productId)->name,
            'quantity' => $quantity,
            'price' => $price, // Store original price
            'price_with_discount' => $discount > 0 ? $finalPrice : null, // Store discounted price if any
            'discount' => $discount,
            'discount_type' => $this->discountType,
        ];

        $this->closePForm();
    }

    public function saveSale()
    {
        $this->validate([
            'clientId' => 'required|exists:clients,id',
            'warehouseId' => 'required|exists:warehouses,id',
            // 'invoiceNumber' => 'nullable|string|max:255',
            'selectedProducts' => 'required|array|min:1',
            'note' => 'nullable|string|max:255',
            'currency_id' => 'required|exists:currencies,id',
            'cash_register_id' => 'required|exists:cash_registers,id',
        ]);

        $totalAmount = 0;
        $displayCurrency = Currency::where('is_currency_display', true)->first();
        $defaultCurrency = Currency::where('is_default', true)->first();

        $oldTotalAmount = 0;
        if ($this->saleId) {
            $oldTotalAmount = Sale::where('id', $this->saleId)->value('total_amount') ?? 0;
        }

        $sale = Sale::updateOrCreate(
            ['id' => $this->saleId],
            [
                'client_id' => $this->clientId,
                'warehouse_id' => $this->warehouseId,
                'note' => $this->note,
                'currency_id' => $this->currency_id,
                'total_amount' => $oldTotalAmount, // Retain the old total amount initially
                'cash_register_id' => $this->cash_register_id,
                'user_id' => Auth::id(),
                'transaction_date' => now()->toDateString(),
            ]
        );

        $productsModified = false;
        $financialTransaction = null; // Initialize the variable

        foreach ($this->selectedProducts as $productId => $details) {
            if (empty($productId)) {
                continue;
            }

            $previousProductSale = null;
            $oldQuantity = 0;

            if ($this->saleId) {
                $previousProductSale = SalesProduct::where('sale_id', $this->saleId)
                    ->where('product_id', $productId)
                    ->first();
                $oldQuantity = $previousProductSale ? $previousProductSale->quantity : 0;
            }

            $effectivePrice = $details['price_with_discount'] ?? $details['price']; // Use discounted price if present

            SalesProduct::updateOrCreate(
                ['sale_id' => $sale->id, 'product_id' => $productId],
                [
                    'quantity' => $details['quantity'],
                    'price' => $details['price'],
                    'price_with_discount' => $details['price_with_discount'],
                ]
            );

            if ($previousProductSale) {
                $difference = $details['quantity'] - $oldQuantity;
                WarehouseStock::where('warehouse_id', $this->warehouseId)
                    ->where('product_id', $productId)
                    ->decrement('quantity', $difference);
            } else {
                WarehouseStock::updateOrCreate(
                    [
                        'warehouse_id' => $this->warehouseId,
                        'product_id' => $productId,
                    ],
                    [
                        'quantity' => DB::raw('quantity - ' . $details['quantity']),
                    ]
                );
            }

            $currency = Currency::find($this->currency_id);
            $convertedPrice = $effectivePrice / $currency->exchange_rate * $defaultCurrency->exchange_rate * $displayCurrency->exchange_rate;

            $totalAmount += $convertedPrice * $details['quantity'];
            $productsModified = true;
        }
        if ($productsModified) {
            $sale->total_amount = $totalAmount;
            $sale->save();
            $financialTransaction = FinancialTransaction::create([
                'type' => 1,
                'amount' => $totalAmount,
                'cash_register_id' => $this->cash_register_id,
                'note' => 'Продажа товаров ."№' . $sale->id . '"',
                'transaction_date' => now()->toDateString(),
                'currency_id' => $this->currency_id,
                'category_id' => '1',
                'user_id' => Auth::id(),
                'client_id' => $this->clientId,
            ]);

            $sale->transaction_id = $financialTransaction->id;
            $sale->save();
        }
        $cashRegister = CashRegister::find($this->cash_register_id);
        if ($cashRegister) {
            $cashRegister->balance += $productsModified ? $totalAmount : $oldTotalAmount;
            $cashRegister->save();
        }

        session()->flash('success', 'Продажа успешно сохранена.');
        $this->isDirty = false;
        $this->resetForm();
        $this->closeForm();
    }

    public function edit($saleId)
    {

        $sale = Sale::with('products')->findOrFail($saleId);
        $this->saleId = $saleId;
        $this->clientId = $sale->client_id;
        $this->warehouseId = $sale->warehouse_id;
        $this->note = $sale->note;
        $this->currency_id = $sale->currency_id;
        $this->cash_register_id = $sale->cash_register_id;

        $this->selectedProducts = [];
        foreach ($sale->products as $product) {
            $productDetails = Product::find($product->pivot->product_id);
            $this->selectedProducts[$product->pivot->product_id] = [
                'name' => $productDetails->name,
                'quantity' => $product->pivot->quantity,
                'price' => $product->pivot->price,
                'price_with_discount' => $product->pivot->price_with_discount,
            ];
        }
        $this->clientId = $this->clientId;
        $client = Client::find($this->clientId);
        if ($client) {
            $this->clientSearch = $client->first_name . ' (' . $client->phones->first()->phone . ')';
            $this->selectedClient = [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'phone' => $client->phones->first()->phone ?? 'N/A',
                'balance' => $client->balance->balance ?? 0,
            ];
        }
        session()->flash('error', 'Нельзя редактировать продажу, только удалить.');

        $this->showForm = true;
    }

    public function deleteSale()
    {
        if ($this->saleId) {
            $sale = Sale::findOrFail($this->saleId);

            foreach ($sale->products as $product) {
                WarehouseStock::where('warehouse_id', $product->pivot->warehouse_id)
                    ->where('product_id', $product->product_id)
                    ->increment('quantity', $product->pivot->quantity);
            }
            $clientBalance = ClientBalance::where('client_id', $sale->client_id)->first();
            if ($clientBalance) {
                $clientBalance->balance += $sale->total_amount;
                $clientBalance->save();
            }

            if ($sale->transaction_id) {
                FinancialTransaction::where('id', $sale->transaction_id)->delete();
            }

            $cashRegister = CashRegister::find($sale->cash_register_id);
            if ($cashRegister) {
                $cashRegister->balance -= $sale->total_amount;
                $cashRegister->save();
            }

            $sale->delete();

            session()->flash('success', 'Продажа успешно удалена и баланс восстановлен.');
            $this->resetForm();
            $this->closeForm();
        }
    }

    public function resetForm()
    {
        $this->clientId = null;
        $this->warehouseId = null;
        // $this->invoiceNumber = null;
        $this->selectedProducts = [];
        $this->note = null;
        $this->products = [];
        $this->currency_id = null;
        $this->selectedClient = null;
        $this->clientSearch = '';
        $this->showForm = false;
        $this->showPForm = false;
        $this->whFrom = null;
        $this->productId = null;
        $this->productQuantity = 1;
        $this->productPrice = null;
        $this->productDiscount = 0;
        $this->saleId = null;
        $this->cash_register_id = null;
    }

    
    //начало поиск клиент
    public function updatedClientSearch()
    {
        $this->clientResults = $this->clientService->searchClients($this->clientSearch);
    }

    public function showAllClients()
    {
        $this->clientResults = $this->clientService->getAllClients();
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

    //конец поиск клиент

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

    public function updated($propertyName)
    {
        $this->isDirty = true;
    }
}
