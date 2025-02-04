<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Sale;
use App\Models\SalesProduct;
use App\Models\WarehouseStock;
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
    public $clientId, $warehouseId, $date, $note, $saleId = null;
    public $productQuantity = 1, $productPrice;
    public $showPForm = false, $productId = null, $showForm = false, $showConfirmationModal = false;
    public $clientSearch = '', $clientResults = [], $selectedClient = null;
    public $productSearch = '', $productResults = [];
    public $cash_register_id, $currency_id, $productPriceType = 'retail_price', $currentRetailPrice = 0, $currentWholesalePrice = 0;
    public $currencies, $displayCurrency, $sales, $warehouses, $cashRegisters;
    public $totalDiscount = 0, $totalDiscountType = 'fixed', $totalDiscountAmount = 0, $totalPrice = 0;
    public $isDirty = false, $selectedProduct, $clients, $showTotalDiscountForm, $showDiscountModal = false;
    protected $clientService, $productService;

    public function boot(ClientService $clientService, ProductService $productService)
    {
        $this->clientService = $clientService;
        $this->productService = $productService;
    }

    public function mount()
    {
        $this->date = now()->format('Y-m-d');
        $this->currencies     = Currency::all();
        $this->displayCurrency = Currency::where('is_currency_display', true)->first();
        $this->cashRegisters  = CashRegister::whereJsonContains('users',  Auth::id())->get();
        $this->sales          = Sale::with(['client', 'warehouse'])->latest()->get();
        $this->warehouses = Warehouse::whereJsonContains('users', (string) Auth::id())->get();
    }

    public function render()
    {

        $this->clients = $this->clientService->searchClients($this->clientSearch);
        return view('livewire.admin.sales');
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
            return;
        }
        $this->resetForm();
        $this->showForm = false;
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
        $product = Product::findOrFail($productId);
        $productPriceObj = ProductPrice::where('product_id', $productId)->first();
        $this->productQuantity = $this->selectedProducts[$productId]['quantity'] ?? 1;
        $this->productPrice = $this->selectedProducts[$productId]['price'] ?? $productPriceObj->retail_price;
        $this->productPriceType = 'retail_price';
        $this->currentRetailPrice = $productPriceObj->retail_price;
        $this->currentWholesalePrice = $productPriceObj->wholesale_price;
        $this->showPForm = true;
    }

    public function closePForm()
    {
        $this->reset(['productId', 'productQuantity', 'productPrice',]);
        $this->showPForm = false;
    }

    public function openDiscountModal()
    {
        $this->showDiscountModal = true;
    }

    public function closeDiscountModal()
    {
        $this->showDiscountModal = false;
    }

    public function resetForm()
    {
        $this->reset([
            'selectedProducts',
            'clientId',
            'warehouseId',
            'date',
            'note',
            'saleId',
            'clientSearch',
            'clientResults',
            'selectedClient',
            'productSearch',
            'productResults',
            'productId',
            'productQuantity',
            'productPrice',
            // 'productDiscount',
            // 'discountType',
            'cash_register_id',
            'currency_id',
            'totalDiscount',
            'totalDiscountType',
            'totalDiscountAmount',
            'totalPrice'
        ]);
    }

    public function addProduct($productId)
    {
        $product = Product::findOrFail($productId);
        $this->selectedProducts[$productId] = [
            'name'         => $product->name ?? 'Название недоступно',
            'quantity'     => 1,
            'price'        => 0,
            'warehouse_id' => $this->warehouseId,
            'image'        => $product->image ?? null,
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
            'productPrice'    => 'required|numeric|min:0.01',
        ]);

        // Проверка количества и наличие товара на складе
        if ($this->productQuantity <= 0) {
            session()->flash('message', 'Количество товара должно быть больше нуля.');
            return;
        }
        $productStock = WarehouseStock::where('product_id', $this->productId)->sum('quantity');
        if ($this->productQuantity > $productStock) {
            session()->flash('message', 'Количество товара не может превышать количество на складе.');
            return;
        }

        // Скидка на товар отключена – обновляем данные товара без скидки
        $product = Product::find($this->productId);
        $this->selectedProducts[$this->productId] = [
            'name'     => $product->name,
            'quantity' => $this->productQuantity,
            'price'    => $this->productPrice,
            'image'    => $product->image ?? null,
        ];

        $this->closePForm();
    }

    public function save()
    {
        $this->validate([
            'clientId'         => 'required|exists:clients,id',
            'warehouseId'      => 'required|exists:warehouses,id',
            'selectedProducts' => 'required|array|min:1',
            'note'             => 'nullable|string|max:255',
            'currency_id'      => 'required|exists:currencies,id',
            'cash_register_id' => 'required|exists:cash_registers,id',
        ]);

        $displayCurrency = Currency::where('is_currency_display', true)->first();
        $defaultCurrency = Currency::where('is_default', true)->first();
        $oldTotalAmount  = $this->saleId ? Sale::where('id', $this->saleId)->value('total_amount') ?? 0 : 0;
        $totalAmount     = 0;
        $productsModified = false;

        $sale = Sale::updateOrCreate(
            ['id' => $this->saleId],
            [
                'client_id'        => $this->clientId,
                'warehouse_id'     => $this->warehouseId,
                'note'             => $this->note,
                'currency_id'      => $this->currency_id,
                'total_amount'     => $oldTotalAmount,
                'cash_register_id' => $this->cash_register_id,
                'user_id'          => Auth::id(),
                'transaction_date' => now()->toDateString(),
            ]
        );

        // Обработка каждого выбранного товара
        foreach ($this->selectedProducts as $productId => $details) {
            if (empty($productId)) continue;

            $previousProductSale = null;
            $oldQuantity = 0;

            if ($this->saleId) {
                $previousProductSale = SalesProduct::where('sale_id', $this->saleId)
                    ->where('product_id', $productId)
                    ->first();
                $oldQuantity = $previousProductSale ? $previousProductSale->quantity : 0;
            }

            // Цена берётся напрямую, скидка на товар отсутствует на данном этапе
            $effectivePrice = $details['price'];

            SalesProduct::updateOrCreate(
                ['sale_id' => $sale->id, 'product_id' => $productId],
                [
                    'quantity' => $details['quantity'],
                    'price'    => $details['price'],
                    // discount_price будет обновлена ниже, если скидка применена
                ]
            );

            if ($previousProductSale) {
                $difference = $details['quantity'] - $oldQuantity;
                WarehouseStock::where('warehouse_id', $this->warehouseId)
                    ->where('product_id', $productId)
                    ->decrement('quantity', $difference);
            } else {
                WarehouseStock::updateOrCreate(
                    ['warehouse_id' => $this->warehouseId, 'product_id' => $productId],
                    ['quantity' => DB::raw('quantity - ' . $details['quantity'])]
                );
            }

            $totalAmount += $effectivePrice * $details['quantity'];
            $productsModified = true;
        }

        // Логика подсчёта скидки на итоговую сумму и запись discount_price в SalesProduct
        $discountComment = '';
        if ($this->totalDiscount > 0) {
            if ($this->totalDiscount < 0) {
                $this->totalDiscount = 0;
            }
            if ($this->totalDiscountType === 'percent') {
                $discountValue = $totalAmount * ($this->totalDiscount / 100);
            } else {
                $discountValue = $this->totalDiscount;
            }
            $finalTotal = $totalAmount - $discountValue;

            $sale->discount_price = $discountValue;
            $totalAmount = $finalTotal;
            $sale->save();
        }

        if ($productsModified) {
            $sale->total_amount = $totalAmount;
            $sale->save();
            $financialTransaction = FinancialTransaction::create([
                'type'              => 1,
                'amount'            => $totalAmount,
                'cash_register_id'  => $this->cash_register_id,
                'note'              => 'Продажа товаров №' . $sale->id . ($discountComment ? " (" . $discountComment . ")" : ''),
                'transaction_date'  => now()->toDateString(),
                'currency_id'       => $this->currency_id,
                'category_id'       => '1',
                'user_id'           => Auth::id(),
                'client_id'         => $this->clientId,
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
        $this->saleId           = $saleId;
        $this->clientId         = $sale->client_id;
        $this->warehouseId      = $sale->warehouse_id;
        $this->note             = $sale->note;
        $this->currency_id      = $sale->currency_id;
        $this->totalDiscount = $sale->discount_price ?? 0;
        $this->cash_register_id = $sale->cash_register_id;
        $this->selectedProducts = [];

        foreach ($sale->products as $product) {
            $prodId = $product->pivot->product_id;
            $this->selectedProducts[$prodId] = [
                'name'                => $product->name,
                'quantity'            => $product->pivot->quantity,
                'price'               => $product->pivot->price,
                // 'price_with_discount' => $product->pivot->price_with_discount,
                'image'               => $product->image ?? null,
            ];
        }
        session()->flash('message', 'Нельзя редактировать продажу, только удалить.');
        $this->showForm = true;
    }

    public function delete()
    {
        if (!$this->saleId) {
            session()->flash('message', 'Продажа не найдена.');
            return;
        }
        $sale = Sale::findOrFail($this->saleId);
        // Restore stock for each product in sale
        foreach ($sale->products as $product) {
            $prodId = $product->pivot->product_id;
            WarehouseStock::updateOrCreate(
                ['warehouse_id' => $sale->warehouse_id, 'product_id' => $prodId],
                ['quantity' => DB::raw('quantity + ' . $product->pivot->quantity)]
            );
        }
        $sale->delete();
        session()->flash('success', 'Продажа успешно удалена.');
        $this->resetForm();
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

    public function updatePriceType()
    {
        if ($this->productPriceType === 'retail_price') {
            $this->productPrice = $this->currentRetailPrice;
        } elseif ($this->productPriceType === 'wholesale_price') {
            $this->productPrice = $this->currentWholesalePrice;
        }
    }

    public function updateProductPrice($price)
    {
        $this->productPrice = $price;
        $this->productPriceType = 'custom';
    }

    public function updatedTotalDiscount()
    {
        $this->calculateTotalDiscount();
    }

    public function updatedTotalDiscountType()
    {
        $this->calculateTotalDiscount();
    }

    public function openTotalDiscountForm()
    {
        $this->showTotalDiscountForm = true;
    }

    public function closeTotalDiscountForm()
    {
        $this->showTotalDiscountForm = false;
    }

    public function applyTotalDiscount()
    {
        $this->calculateTotalDiscount();
        $this->showTotalDiscountForm = false;
    }

    public function calculateTotalDiscount()
    {
        $this->totalDiscountAmount = 0;
        foreach ($this->selectedProducts as $details) {
            $this->totalDiscountAmount += ($details['discount'] ?? 0) * $details['quantity'];
        }
    }
}
