<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Sale;
use App\Models\SalesProduct;
use App\Models\WarehouseStock;
use App\Models\Currency;
use App\Models\Discount;
use App\Models\CashRegister;
use App\Models\ProductPrice;
use App\Models\ClientBalance;
use App\Models\FinancialTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\ClientService;
use App\Services\ProductService;
use App\Services\CurrencyConverter;
use App\Models\Project;


class Sales extends Component
{

    public $selectedProducts = [];
    public $clientId, $warehouseId, $date, $note, $saleId = null;
    public $productQuantity = 1, $productPrice;
    public $showPForm = false, $productId = null, $showForm = false, $showConfirmationModal = false;
    public $clientSearch = '', $clientResults = [], $selectedClient = null;
    public $productSearch = '', $productResults = [];
    public $cashId, $currencyId, $productPriceType = 'retail_price', $currentRetailPrice = 0, $currentWholesalePrice = 0;
    public $currencies, $displayCurrency, $sales, $warehouses, $cashRegisters, $projects, $projectId;
    public $totalDiscount = 0, $totalDiscountType = 'fixed', $totalDiscountAmount = 0, $totalPrice = 0, $paymentType;
    public $isDirty = false, $selectedProduct, $clients, $showDiscountModal = false;
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
        $this->displayCurrency = Currency::where('is_report', true)->first();
        $this->cashRegisters  = CashRegister::whereJsonContains('users', (string) Auth::id())->get();
        $this->sales          = Sale::with(['client', 'warehouse'])->latest()->get();
        $this->warehouses = Warehouse::whereJsonContains('users', (string) Auth::id())->get();
        $this->projects = Project::whereJsonContains('users', (string) Auth::id())->get();
    }

    public function render()
    {
        $this->clients = $this->clientService->searchClients($this->clientSearch);
        $this->sales = Sale::with(['client', 'warehouse', 'products'])
            ->latest()
            ->get();

        return view('livewire.admin.sales', [
            'sales' => $this->sales,
        ]);
    }

    public function openForm()
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function closeForm()
    {
        // if ($this->showPForm) return;
        // if ($this->isDirty) {
        //     $this->showConfirmationModal = true;
        //     return;
        // }
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
            'cashId',
            'currencyId',
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
        // 1. Валидация входных данных
        $rules = [
            'clientId'         => 'required|exists:clients,id',
            'warehouseId'      => 'required|exists:warehouses,id',
            'selectedProducts' => 'required|array|min:1',
            'note'             => 'nullable|string|max:255',
            'currencyId'       => 'required|exists:currencies,id',
        ];
        if ($this->paymentType == 1) {
            $rules['cashId'] = 'required|exists:cash_registers,id';
        }
        $this->validate($rules);

        // 2. Расчёт итоговой суммы продажи
        $totalAmount = 0;
        foreach ($this->selectedProducts as $details) {
            $totalAmount += $details['price'] * $details['quantity'];
        }
        $initialSum = $totalAmount;

        if ($this->totalDiscount > 0) {
            $finalSum = ($this->totalDiscountType === 'fixed')
                ? ($initialSum - $this->totalDiscount)
                : ($initialSum - ($initialSum * $this->totalDiscount / 100));
        } else {
            $finalSum = $initialSum;
        }

        // Проверка: итоговая сумма продажи должна быть больше нуля,
        // а значение скидки не может быть равно или больше исходной суммы.
        if ($this->totalDiscount > 0) {
            if (
                ($this->totalDiscountType === 'fixed' && $this->totalDiscount >= $initialSum) ||
                ($this->totalDiscountType === 'percent' && $this->totalDiscount >= 100) ||
                $finalSum <= 0
            ) {
                session()->flash('message', 'Значение скидки не может быть равно или больше суммы продажи.');
                return;
            }
        }

        // 3. Конвертация сумм, если валюта кассы отличается от валюты продажи
        $saleCurrency = $this->currencies->where('id', $this->currencyId)->first();
        $cashRegisterCurrency = null;
        if ($this->cashId) {
            $cashRegister = $this->cashRegisters->where('id', $this->cashId)->first();
            $cashRegisterCurrency = $this->currencies->where('id', $cashRegister->currency_id)->first();
        }
        if ($cashRegisterCurrency && $saleCurrency->id !== $cashRegisterCurrency->id) {
            $convertedInitialSum = CurrencyConverter::convert($initialSum, $saleCurrency, $cashRegisterCurrency);
            $convertedFinalSum   = CurrencyConverter::convert($finalSum, $saleCurrency, $cashRegisterCurrency);
        } else {
            $convertedInitialSum = $initialSum;
            $convertedFinalSum   = $finalSum;
        }

        // 4. Формирование текстовой заметки
        $discountText = $this->totalDiscount > 0 ? ', скидка: ' . ($initialSum - $finalSum) . ' ' . $saleCurrency->code : '';
        $noteText = 'Продажа (исходная сумма: ' . $initialSum . ' ' . $saleCurrency->code . $discountText . ')';

        // 5. Создание записи продажи одним запросом
        $sale = Sale::create([
            'client_id'        => $this->clientId,
            'warehouse_id'     => $this->warehouseId,
            'note'             => $noteText,
            'currency_id'      => $this->currencyId,
            'total_amount'     => $convertedFinalSum, // Итоговая сумма (если скидка есть, то цена со скидкой)
            'cash_register_id' => $this->cashId,
            'user_id'          => Auth::id(),
            'transaction_date' => now()->toDateString(),
            'price'            => $convertedInitialSum, // Исходная сумма
            'discount_price'   => $this->totalDiscount > 0 ? $convertedFinalSum : null,
            'discount_type'    => $this->totalDiscount > 0 ? $this->totalDiscountType : null,
            'project_id'       => $this->projectId,
        ]);
        $this->saleId = $sale->id;

        // 6. Сохранение деталей продажи и обновление остатков склада
        foreach ($this->selectedProducts as $productId => $details) {
            SalesProduct::updateOrCreate(
                ['sale_id' => $sale->id, 'product_id' => $productId],
                [
                    'quantity' => $details['quantity'],
                    'price'    => $details['price'],
                ]
            );

            // Обновление остатков склада: если ранее была запись, считаем разницу, иначе уменьшаем количество
            $previousProductSale = SalesProduct::where('sale_id', $sale->id)
                ->where('product_id', $productId)
                ->first();
            if ($previousProductSale) {
                $oldQuantity = $previousProductSale->quantity;
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
        }

        // 7. Регистрация платежа и обновление баланса клиента
        if ($this->paymentType == 1) {
            // Вариант "с кассы": создаём финансовую транзакцию без изменения баланса клиента
            $transactionData = [
                'client_id'         => $this->clientId,
                'amount'            => $convertedFinalSum,
                'currency_id'       => $cashRegisterCurrency->id,
                'transaction_date'  => now()->toDateString(),
                'note'              => $noteText,
                'sale_id'           => $sale->id,
                'user_id'           => Auth::id(),
                'cash_register_id'  => $this->cashId,
                'category_id'       => 1,
                'type'              => 1,
                'project_id'        => $this->projectId,
            ];
            $transaction = new FinancialTransaction($transactionData);
            $transaction->setSkipClientBalanceUpdate(true);
            $transaction->save();

            $sale->update(['transaction_id' => $transaction->id]);
        } else {
            // Вариант "с баланса": обновляем баланс клиента напрямую
            $clientBalance = ClientBalance::firstOrCreate(
                ['client_id' => $this->clientId],
                ['balance' => 0]
            );
            $clientBalance->increment('balance', $convertedFinalSum);
        }

        session()->flash('success', 'Продажа успешно сохранена.');
        $this->closeForm();
    }

    public function edit($id)
    {
        $sale = Sale::with('products')->findOrFail($id);
        $this->saleId         = $sale->id;
        $this->clientId       = $sale->client_id;
        $this->warehouseId    = $sale->warehouse_id;
        $this->note           = $sale->note;
        $this->currencyId     = $sale->currency_id;
        $this->totalPrice     = $sale->price;
        $this->totalDiscount  = $sale->discount_price ? ($sale->price - $sale->discount_price) : 0;
        $this->totalDiscountType  = $sale->discount_type ?? 'fixed';
        $this->cashId         = $sale->cash_register_id;
        $this->paymentType    = $sale->cash_register_id ? 1 : 0;
        $this->selectedProducts = [];

        foreach ($sale->products as $product) {
            $prodId = $product->pivot->product_id;
            $this->selectedProducts[$prodId] = [
                'name'     => $product->name,
                'quantity' => $product->pivot->quantity,
                'price'    => $product->pivot->price,
                'image'    => $product->image ?? null,
            ];
        }

        session()->flash('message', 'Нельзя редактировать, только удалить.');
        $this->showForm = true;
    }

    public function delete()
    {
        if (!$this->saleId) {
            session()->flash('message', 'Продажа не найдена.');
            return;
        }

        $sale = Sale::with('products')->findOrFail($this->saleId);
        $clientId = $sale->client_id;

        foreach ($sale->products as $product) {
            $prodId = $product->pivot->product_id;
            WarehouseStock::updateOrCreate(
                ['warehouse_id' => $sale->warehouse_id, 'product_id' => $prodId],
                ['quantity' => DB::raw('quantity + ' . $product->pivot->quantity)]
            );
        }

        // Удаляем связанную финансовую транзакцию, если она существует
        if (!empty($sale->transaction_id)) {
            FinancialTransaction::where('id', $sale->transaction_id)->delete();
        }

        // Сохраняем сумму продажи (total_amount) для возврата в баланс клиента
        $saleAmount = $sale->total_amount;
        $sale->delete();

        // Если продажа осуществлялась по варианту "с баланса" (cash_register_id не установлен),
        // то у клиента нужно забрать с баланса сумму продажи.
        if (empty($sale->cash_register_id)) {
            ClientBalance::where('client_id', $clientId)
                ->decrement('balance', $saleAmount);
        } else {
            // Если продажа через кассу, то вернуть сумму продажи в баланс клиента.
            ClientBalance::where('client_id', $clientId)
                ->increment('balance', $saleAmount);
        }

        session()->flash('success', 'Продажа и связанная транзакция удалены, баланс клиента обновлён.');
        $this->closeForm();
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

        $discount = Discount::where('client_id', $clientId)->first();
        if ($discount) {
            $this->totalDiscount = $discount->discount_value;
            $this->totalDiscountType = ($discount->discount_type === 'percentage') ? 'percent' : $discount->discount_type;
        } else {
            $this->totalDiscount = 0;
            $this->totalDiscountType = 'fixed';
        }

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
}
