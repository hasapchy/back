<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Order;
use App\Models\User;
use App\Models\OrderStatus;
use App\Models\OrderCategory;
use App\Services\ClientService;
use App\Models\OrderAf;
use App\Models\OrderAfValue;
use App\Models\Transaction;
use App\Models\Currency;
use App\Models\TransactionCategory;
use App\Models\CashRegister;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use App\Models\Product;
use App\Services\ProductService;
use App\Models\ProductPrice;

class Orders extends Component
{
    public $orders, $clients, $users, $statuses, $categories, $currencies;
    public $client_id, $user_id, $status_id, $category_id, $note, $date;
    public $order_id;
    public $showForm = false;
    public $showTrForm = false;
    public $showConfirmationModal = false;
    public $clientSearch = '';
    public $clientResults = [];
    public $selectedClient;
    public $afFields;
    public $afValues = [];
    public $tr_note, $tr_amount, $tr_date, $tr_category_id, $tr_currency_id, $tr_cash_id;
    public $incomeCategories = [], $transactions, $cashRegisters = [];
    public $isDirty = false;
    public $totalSum = 0;
    public $totalDiscount = 0;
    public $totalDiscountType = 'fixed';
    public $showDiscountModal = false;
    public $productPriceConverted;
    public $productPriceType = 'custom';
    public $selectedProducts = [];
    public $productId;
    public $productQuantity = 1;
    public $productPrice;
    public $productDiscount = 0;
    public $showPForm = false;
    public $productSearch = '';
    public $productResults = [];
    public $selectedProduct;
    public $currentRetailPrice = 0;
    public $currentWholesalePrice = 0;
    public $warehouses;
    public $warehouseId;
    public $totalPrice = 0;

    protected $clientService;
    protected $productService;
    public $tr_type = 1;
    public $expenseCategories = [];

    protected $listeners = [
        'productSelected' => 'selectProduct',
        'saveProductModal' => 'saveProductModal',
        'closeProductQuantityModal' => 'closePForm',
    ];

    public function boot(ClientService $clientService, ProductService $productService)
    {
        $this->clientService = $clientService;
        $this->productService = $productService;
    }

    public function mount()
    {
        $this->afFields = collect();
        $this->tr_date = now()->toDateString();
        $this->date = now()->format('Y-m-d');
        $this->currencies = Currency::all();
        $this->incomeCategories = TransactionCategory::where('type', 1)->get();
        $this->expenseCategories = TransactionCategory::where('type', 0)->get();
        $this->cashRegisters = CashRegister::whereJsonContains('users', (string) Auth::id())->get();
        $this->warehouses     = Warehouse::whereJsonContains('users', (string) Auth::id())->get();
    }


    public function render()
    {
        $this->totalPrice = collect($this->selectedProducts)
        ->sum(function ($product) {
            $price = (float)$product['price'];
            $quantity = (int)$product['quantity'];
            $rowTotal = $price * $quantity;
            $discount = isset($product['discount']) ? (float)$product['discount'] : 0;
            $discountType = $product['discount_type'] ?? 'fixed';
            if ($discountType === 'fixed') {
                $effective = $rowTotal - $discount;
            } else {
                $effective = $rowTotal - ($rowTotal * ($discount / 100));
            }
            return $effective;
        });
        $this->orders = Order::with(['client', 'user', 'status', 'category', 'orderProducts'])->get();
        $this->clients = $this->clientService->searchClients($this->clientSearch);
        $this->users = User::all();
        $this->statuses = OrderStatus::all();
        $this->categories = OrderCategory::all();
        $this->loadAfFields();

        return view('livewire.admin.orders.orders');
    }

    public function loadAfFields()
    {
        if ($this->category_id) {
            $this->afFields = OrderAf::whereJsonContains('category_ids', (string)$this->category_id)->get();

            if ($this->order_id) {
                // Загружаем существующие значения для редактируемого заказа
                $existingValues = OrderAfValue::where('order_id', $this->order_id)
                    ->whereIn('order_af_id', $this->afFields->pluck('id'))
                    ->pluck('value', 'order_af_id')
                    ->toArray();

                $this->afValues = $existingValues;
            } else {
                // Используем значения по умолчанию только для нового заказа
                $this->afValues = $this->afFields->pluck('default', 'id')->toArray();
            }
        } else {
            $this->afFields = collect();
            $this->afValues = [];
        }
    }

    //начало поиск клиент
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
        $this->client_id = $clientId;
        $this->clientResults = [];
        $this->totalDiscount = $this->selectedClient->discount ?? 0;
        $this->totalDiscountType = $this->selectedClient->discount_type ?? 'fixed';
    }

    public function deselectClient()
    {
        $this->selectedClient = null;
        $this->client_id = null;
        $this->clientSearch = '';
        $this->clientResults = [];
    }

    public function showAllProducts()
    {
        $this->productResults = $this->productService->getAllProductsServices();
    }

    public function updatedProductSearch()
    {
        $this->productResults = $this->productService->searchProductsByWarehouse($this->productSearch, $this->warehouseId);
    }

    public function selectProduct($productId)
    {
        $this->selectedProduct = $this->productService->getProductById($productId);
        // Получаем объект цены для данного товара
        $productPriceObj = \App\Models\ProductPrice::where('product_id', $productId)->first();
        $defaultRetailPrice = $productPriceObj ? $productPriceObj->retail_price : 0;
        // Добавляем товар в массив выбранных с ценой по умолчанию из retail_price
        $this->selectedProducts[$productId] = [
            'name'          => $this->selectedProduct->name,
            'quantity'      => 1,
            'price'         => $defaultRetailPrice,
            'warehouse_id'  => $this->warehouseId,
            'image'         => $this->selectedProduct->image ?? null,
            'discount'      => 0,       // скидка по умолчанию
            'discount_type' => 'fixed', // тип скидки по умолчанию
        ];
        $this->productSearch = '';
        $this->productResults = [];
    }

    public function deselectProduct()
    {
        $this->selectedProduct = null;
    }
    //поиск товара конец


    public function updatedCategoryId($value)
    {
        $this->afFields = OrderAf::whereJsonContains('category_ids', $value)->get();
        if ($this->order_id) {

            $existingValues = OrderAfValue::where('order_id', $this->order_id)
                ->whereIn('order_af_id', $this->afFields->pluck('id'))
                ->pluck('value', 'order_af_id')
                ->toArray();
            $this->afValues = $existingValues;
        } else {

            $this->afValues = [];
            foreach ($this->afFields as $field) {
                $this->afValues[$field->id] = $field->default;
            }
        }
    }

    public function save()
    {
        $this->validate([
            'client_id' => 'required|exists:clients,id',
            'category_id' => 'required|exists:order_categories,id',
            'date' => 'required|date',
        ]);

        $order = Order::updateOrCreate(['id' => $this->order_id], [
            // 'name' => $this->name,
            'client_id' => $this->client_id,
            'user_id' => Auth::id(),
            'status_id' => $this->status_id ?? 1,
            'category_id' => $this->category_id,
            'note' => $this->note,
            'date' => $this->date,
        ]);

        $validAfIds = $this->afFields->pluck('id')->toArray();

        foreach ($this->afValues as $afId => $value) {

            if (in_array($afId, $validAfIds)) {
                OrderAfValue::updateOrCreate(
                    ['order_id' => $order->id, 'order_af_id' => $afId],
                    ['value' => $value ?? '']
                );
            }
        }

        OrderAfValue::where('order_id', $order->id)
            ->whereNotIn('order_af_id', $validAfIds)
            ->delete();

        session()->flash(
            'message',
            $this->order_id ? 'Заказ успешно обновлен.' : 'Заказ успешно создан.'
        );
        $this->resetForm();
        $this->showForm = false;
        $this->isDirty = false;
    }

    public function edit($id)
    {
        $order = Order::findOrFail($id);
        $this->order_id = $id;
        $this->client_id = $order->client_id;
        $this->status_id = $order->status_id;
        $this->category_id = $order->category_id;
        $this->note = $order->note;
        $this->date = $order->date;
        $this->selectedClient = $this->clientService->getClientById($order->client_id);
        $this->loadAfFields();
        $this->transactions = Transaction::whereIn('id', json_decode($order->transaction_ids) ?? [])->get();
        $this->showForm = true;
        // Загрузка товаров заказа с добавлением ключа image
        $this->selectedProducts = $order->orderProducts->mapWithKeys(function ($item) {
            return [
                $item->product_id => [
                    'name'     => $item->product->name,
                    'quantity' => $item->quantity,
                    'price'    => $item->price,
                    'discount' => $item->discount,
                    'image'    => $item->product->image ?? null,
                ]
            ];
        })->toArray();
    }

    public function deleteOrderForm()
    {

        $order = Order::find($this->order_id);
        if ($order && $order->transaction_ids) {
            $transactionIds = json_decode($order->transaction_ids, true);
            Transaction::whereIn('id', $transactionIds)->delete();
        }

        Order::find($this->order_id)->delete();
        session()->flash('message', 'Order Deleted Successfully.');
        $this->showForm = false;
        $this->resetForm();
    }


    public function deleteTransaction($transactionId)
    {
        $order = Order::find($this->order_id);
        if ($order && $order->transaction_ids) {
            $transactionIds = json_decode($order->transaction_ids, true);
            $updatedTransactionIds = array_filter($transactionIds, function ($id) use ($transactionId) {
                return $id != $transactionId;
            });
            $order->transaction_ids = json_encode(array_values($updatedTransactionIds));
            $order->save();
        }

        $transaction = Transaction::find($transactionId);
        if ($transaction) {
            $cashRegister = $transaction->cashRegister;
            if ($cashRegister) {
                $cashRegister->balance -= $transaction->amount;
                $cashRegister->save();
            }
            $transaction->delete();
            session()->flash('message', 'Транзакция успешно удалена.');
        }
    }

    public function updateStatus($orderId, $newStatusId)
    {
        $order = Order::find($orderId);
        if ($order) {
            $order->status_id = $newStatusId;
            $order->save();

            session()->flash('message', 'Статус заказа обновлен.');
        }
    }

    public function openConfirmationModal()
    {
        $this->showConfirmationModal = true;
    }

    public function closeConfirmationModal()
    {
        $this->showConfirmationModal = false;
    }

    public function confirmClose($confirm = false)
    {
        if ($confirm) {
            $this->resetForm();
            $this->showForm = false;
            $this->isDirty = false;
        }
        $this->showConfirmationModal = false;
    }


    public function closeForm()
    {
        if ($this->isDirty) {
            $this->showConfirmationModal = true;
        } else {
            $this->resetForm();
            $this->showForm = false;
        }
    }

    public function openForm()
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function openTrForm()
    {
        $this->resetTrForm();
        $this->showTrForm = true;
    }

    public function closeTrForm()
    {
        if ($this->isDirty) {
            $this->showConfirmationModal = true;
        } else {
            $this->resetTrForm();
            $this->showTrForm = false;
        }
    }

    public function saveTransaction()
{
    $this->validate([
        'tr_note' => 'nullable|string|max:255',
        'tr_amount' => 'required|numeric',
        'tr_date' => 'required|date',
        'tr_category_id' => 'required|exists:transaction_categories,id',
        'tr_currency_id' => 'required|exists:currencies,id',
        'tr_cash_id' => 'required|exists:cash_registers,id',
        'tr_type' => 'required|in:0,1',
    ]);

    $transaction = Transaction::create([
        'type'            => $this->tr_type,
        'amount'          => $this->tr_amount,
        'note'            => 'Заказ номер ' . $this->order_id . ': ' . $this->tr_note,
        'date'            => $this->tr_date,
        'category_id'     => $this->tr_category_id,
        'currency_id'     => $this->tr_currency_id,
        'client_id'       => $this->client_id,
        'user_id'         => Auth::id(),
        'cash_id'         => $this->tr_cash_id,
    ]);

    $cashRegister = CashRegister::find($this->tr_cash_id);
    if ($cashRegister) {
        if ($this->tr_currency_id && $this->tr_currency_id != $cashRegister->currency_id) {
            $transactionCurrency = Currency::find($this->tr_currency_id);
            $cashRegisterCurrency = Currency::find($cashRegister->currency_id);
            $transactionExchangeRate = $transactionCurrency->currentExchangeRate()->exchange_rate;
            $cashRegisterExchangeRate = $cashRegisterCurrency->currentExchangeRate()->exchange_rate;
            $amountInDefaultCurrency = $this->tr_amount / $transactionExchangeRate;
            $convertedAmount = $amountInDefaultCurrency * $cashRegisterExchangeRate;

            $transaction->note .= " // Original Amount: {$this->tr_amount} {$transactionCurrency->symbol}";

            $transaction->update([
                'amount'      => $convertedAmount,
                'currency_id' => $cashRegister->currency_id,
            ]);
        } else {
            $convertedAmount = $this->tr_amount;
        }

        if ($this->tr_type == 0) {
            $cashRegister->balance -= $convertedAmount;
        } else {
            $cashRegister->balance += $convertedAmount;
        }
        $cashRegister->save();
    }

    $order = Order::find($this->order_id);
    $transactionIds = json_decode($order->transaction_ids, true) ?? [];
    $transactionIds[] = $transaction->id;
    $order->update(['transaction_ids' => json_encode($transactionIds)]);

    // Обновляем локальное свойство с транзакциями, чтобы сразу отобразить новую транзакцию
    $this->transactions = Transaction::whereIn('id', $transactionIds)->get();

    session()->flash('message', 'Транзакция успешно создана.');
    $this->resetTrForm();
    $this->showTrForm = false;
}

    public function editTransaction($transactionId)
    {
        $transaction = Transaction::find($transactionId);
        if ($transaction) {

            $this->tr_note = $transaction->note;
            $this->tr_amount = $transaction->amount;
            $this->tr_date = $transaction->tr_date;
            $this->tr_category_id = $transaction->category_id;
            $this->tr_currency_id = $transaction->currency_id;
            $this->tr_cash_id = $transaction->cash_id;
            $this->order_id = $transaction->order_id ?? $this->order_id;
            $this->showTrForm = true;
        }
    }

    private function resetTrForm()
    {
        $this->tr_note = '';
        $this->tr_amount = '';
        $this->tr_date = now()->toDateString();
        $this->tr_category_id = '';
        $this->tr_currency_id = '';
        $this->tr_cash_id = '';
        $this->tr_type = 1;
    }

    public function resetForm()
    {
        $this->reset([
            'client_id',
            'user_id',
            'status_id',
            'category_id',
            'note',
            'order_id',
            'clientSearch',
            'clientResults',
            'selectedClient',
            'afFields',
            'afValues',
            'tr_note',
            'tr_amount',
            'tr_date',
            'tr_category_id',
            'tr_currency_id',
            'tr_cash_id',
            'incomeCategories',
            'transactions',
            'cashRegisters',
            'totalDiscount',
            'totalDiscountType',
        ]);
        $this->date = now()->format('Y-m-d');
    }

    public function openDiscountModal()
    {
        $this->showDiscountModal = true;
    }

    public function closeDiscountModal()
    {
        $this->showDiscountModal = false;
    }

    public function addProduct()
    {

        $this->selectedProducts[] = [
            'name' => '',
            'quantity' => 1,
            'price' => 0,
        ];
    }

    public function saveProductModal()
    {
        $this->validate([
            'productQuantity' => 'required|integer|min:1',
            'productPrice' => 'required|numeric|min:0.01', // Использование 'productPrice'
            'productDiscount' => 'nullable|numeric|min:0',
        ]);

        if ($this->productQuantity <= 0) {
            session()->flash('error', 'Количество товара должно быть больше нуля.');
            return;
        }

        $product = Product::findOrFail($this->productId);

        $this->selectedProducts[$this->productId] = [
            'name' => $product->name,
            'quantity' => $this->productQuantity,
            'price' => $this->productPrice, // Использование 'productPrice'
        ];

        $this->closePForm();
    }

    public function saveOrderProducts()
    {
        if (!$this->order_id) {
            session()->flash('error', 'Сначала сохраните заказ.');
            return;
        }

        $order = Order::findOrFail($this->order_id);
        // Удаляем старые записи
        $order->orderProducts()->delete();

        // Добавляем новые записи из $selectedProducts
        foreach ($this->selectedProducts as $productId => $details) {
            $order->orderProducts()->create([
                'product_id' => $productId,
                'quantity' => $details['quantity'],
                'price' => $details['price'],
                'discount' => isset($details['discount']) ? $details['discount'] : 0,
            ]);
        }

        session()->flash('message', 'Товары успешно сохранены.');
    }

    public function saveOrder()
    {
        $this->validate([
            'client_id' => 'required|exists:clients,id',
            'category_id' => 'required|exists:order_categories,id',
            'date' => 'required|date',
            'selectedProducts' => 'required|array|min:1',
        ]);

        $order = Order::updateOrCreate(
            ['id' => $this->order_id],
            [
                'client_id'  => $this->client_id,
                'user_id'    => Auth::id(),
                'status_id'  => $this->status_id ?? 1,
                'category_id' => $this->category_id,
                'note'       => $this->note,
                'date'       => $this->date,
            ]
        );

        // Удаляем старые записи товаров заказа
        $order->orderProducts()->delete();

        // Добавляем новые записи, включая warehouse_id
        foreach ($this->selectedProducts as $productId => $details) {
            $order->orderProducts()->create([
                'product_id'   => $productId,
                'quantity'     => $details['quantity'],
                'price'        => $details['price'],
                'discount'     => $details['discount'],
                'warehouse_id' => $details['warehouse_id'] ?? null,
            ]);
        }

        session()->flash(
            'message',
            $this->order_id ? 'Заказ успешно обновлен.' : 'Заказ успешно создан.'
        );
        $this->resetForm();
        $this->showForm = false;
        $this->isDirty = false;
    }

    public function removeProduct($productId)
    {
        unset($this->selectedProducts[$productId]);
    }

    public function openPForm($productId)
    {
        $this->productId = $productId;
        $product = Product::findOrFail($productId);
        $productPriceObj = ProductPrice::where('product_id', $productId)->first();
        $this->productQuantity = $this->selectedProducts[$productId]['quantity'] ?? 1;
        $this->productPrice = $this->selectedProducts[$productId]['price'] ?? $productPriceObj->retail_price;
        $this->productPriceType = 'custom';
        $this->currentRetailPrice = $productPriceObj->retail_price;
        $this->currentWholesalePrice = $productPriceObj->wholesale_price;
        $this->showPForm = true;
    }
    public function updatePriceType()
    {
        if ($this->productPriceType === 'custom') {
            return;
        }
        $sessionCurrencyCode = session('currency', 'USD');
        $conversionService = app(\App\Services\CurrencySwitcherService::class);
        $displayRate = $conversionService->getConversionRate($sessionCurrencyCode, now());

        if ($this->productPriceType === 'retail_price') {
            $this->productPrice = $this->currentRetailPrice;
            $this->productPriceConverted = $this->currentRetailPrice * $displayRate;
        } elseif ($this->productPriceType === 'wholesale_price') {
            $this->productPrice = $this->currentWholesalePrice;
            $this->productPriceConverted = $this->currentWholesalePrice * $displayRate;
        }
    }

    public function updateProductPrice($price)
    {
        $sessionCurrencyCode = session('currency', 'USD');
        $conversionService = app(\App\Services\CurrencySwitcherService::class);
        $displayRate = $conversionService->getConversionRate($sessionCurrencyCode, now());
        $this->productPrice = $price / $displayRate;
        $this->productPriceConverted = $price;
        $this->productPriceType = 'custom';
    }


    public function closePForm()
    {
        $this->showPForm = false;
        $this->productId = null;
        $this->productQuantity = 1;
        $this->productPrice = null;
        $this->productDiscount = 0;
    }
}
