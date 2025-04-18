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
use App\Models\ClientBalance;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\ClientService;
use App\Services\ProductService;
use App\Services\CurrencyConverter;
use App\Models\Project;

class Sales extends Component
{
    // Основные публичные свойства для работы компонента
    public $selectedProducts = [];
    public $clientId, $warehouseId, $date, $note, $saleId = null;
    public $productQuantity = 1, $productPrice;
    public $showPForm = false, $productId = null, $showForm = false, $showConfirmationModal = false;
    public $clientSearch = '', $clientResults = [], $selectedClient = null;
    public $productSearch = '', $productResults = [];
    public $cashId, $currencyId, $productPriceType = 'custom', $currentRetailPrice = 0, $currentWholesalePrice = 0;
    public $currencies, $sales, $warehouses, $cashRegisters, $projects, $projectId;
    public $totalDiscount = 0, $totalDiscountType = 'fixed', $totalDiscountAmount = 0, $totalPrice = 0, $paymentType;
    public $selectedProduct, $clients, $showDiscountModal = false;
    public $cash_price;
    public $productPriceConverted;

    // Сервисы, внедряемые через boot()
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
        $this->cashRegisters  = CashRegister::whereJsonContains('users', (string) Auth::id())->get();
        $this->sales          = Sale::with(['client', 'warehouse'])->latest()->get();
        $this->warehouses     = Warehouse::whereJsonContains('users', (string) Auth::id())->get();
        $this->projects       = Project::whereJsonContains('users', (string) Auth::id())->get();
    }

    public function render()
    {
        // Пересчитываем итоговую сумму на основе выбранных товаров с учетом скидки
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
        $this->resetForm();
        $this->showForm = false;
    }

    public function confirmClose($confirm = false)
    {
        if ($confirm) {
            $this->resetForm();
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
        $this->productPriceType = 'custom';
        $this->currentRetailPrice = $productPriceObj->retail_price;
        $this->currentWholesalePrice = $productPriceObj->wholesale_price;
        $this->showPForm = true;
    }

    public function closePForm()
    {
        $this->reset(['productId', 'productQuantity', 'productPrice']);
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
        $this->date = now()->format('Y-m-d');
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

        $rules = [
            'clientId'         => 'required|exists:clients,id',
            'warehouseId'      => 'required|exists:warehouses,id',
            'selectedProducts' => 'required|array|min:1',
            'note'             => 'nullable|string|max:255',
        ];

        if ($this->paymentType == 1) {
            $rules['cashId'] = 'required|exists:cash_registers,id';
        }

        $this->validate($rules);


        DB::transaction(function () {
            // 1. Создание или обновление записи продажи
            $sale = $this->createOrUpdateSale();

            // 2. Обработка товаров и обновление остатков склада
            $totalAmount = $this->processProducts($sale->id);

            // 3. Расчёт итоговой суммы и скидки
            list($initialSum, $finalSum) = $this->calculateTotals($totalAmount);

            // 4. Конвертация сумм в валюту кассы, если необходимо
            $convertedSums = $this->convertSums($initialSum, $finalSum);

            // 6. Обновление записи продажи с рассчитанными суммами и скидкой (в исходной валюте)
            $this->updateSaleRecord($sale, $convertedSums, $initialSum, $finalSum);

            // 7. Обработка платежа с использованием стратегии (касса или баланс клиента)
            $this->processPayment($sale, $convertedSums['finalSum'], $initialSum);
        });

        session()->flash('success', 'Продажа успешно сохранена.');
        $this->closeForm();
    }

    /**
     * Создает или обновляет запись продажи.
     */
    private function createOrUpdateSale()
    {
        $sale = Sale::firstOrNew(['id' => $this->saleId]);
        $sale->fill([
            'client_id'    => $this->clientId,
            'warehouse_id' => $this->warehouseId,
            // 'currency_id'  => $this->cashId
            //     ? $this->currencies->firstWhere('id', $this->cashRegisters->firstWhere('id', $this->cashId)->currency_id)->id
            //     : $this->currencies->firstWhere('is_default', true)->id,
            'cash_id'      => $this->cashId,
            'user_id'      => Auth::id(),
            'date'         => now(),
            'project_id'   => $this->projectId ?: null,
            'price'        => $sale->exists ? $sale->price : 0,
            'cash_price'   => $sale->exists ? $sale->cash_price : 0,
            'total_price'  => $sale->exists ? $sale->total_price : 0,
            'discount'     => $sale->exists ? $sale->discount : 0,
        ]);
        $sale->save();
        $this->saleId = $sale->id;
        return $sale;
    }

    /**
     * Обрабатывает товары продажи, обновляет записи SalesProduct и инвентарь.
     * Использует коллекционные методы для обхода массива товаров.
     */
    private function processProducts($saleId)
    {
        $totalAmount = 0;
        collect($this->selectedProducts)->each(function ($details, $productId) use ($saleId, &$totalAmount) {
            $effectivePrice = $details['price'];

            // Обновляем или создаём запись для товара в продаже
            SalesProduct::updateOrCreate(
                ['sale_id' => $saleId, 'product_id' => $productId],
                [
                    'quantity' => $details['quantity'],
                    'price'    => $effectivePrice,
                ]
            );

            // Обновление остатков склада
            $previousSale = SalesProduct::where('sale_id', $saleId)
                ->where('product_id', $productId)
                ->first();
            if ($previousSale) {
                $difference = $details['quantity'] - $previousSale->quantity;
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
        });
        return $totalAmount;
    }

    /**
     * Рассчитывает итоговую сумму до и после применения скидки.
     *
     * @param float $totalAmount
     * @return array [$initialSum, $finalSum]
     */
    private function calculateTotals($totalAmount)
    {
        $initialSum = $totalAmount;
        $conversionService = app(\App\Services\CurrencySwitcherService::class);
        $displayRate = $conversionService->getConversionRate(session('currency', 'USD'), now());

        // Проверка на отрицательную скидку
        if ($this->totalDiscount < 0) {
            session()->flash('message', 'Скидка не может быть отрицательной.');
            // Сбрасываем скидку и возвращаем исходную сумму
            $this->totalDiscount = 0;
            return [$initialSum, $initialSum];
        }

        if ($this->totalDiscount > 0) {
            if ($this->totalDiscountType === 'fixed') {
                // Переводим введённую скидку в базовую валюту
                $discountConverted = $this->totalDiscount / $displayRate;
                if ($discountConverted >= $initialSum) {
                    session()->flash('message', 'Фиксированная скидка не может быть больше или равна сумме продажи.');
                    // Ограничим скидку, чтобы итоговая сумма была минимально положительной (0.01)
                    $discountConverted = $initialSum - 0.01;
                }
                $finalSum = $initialSum - $discountConverted;
            } else { // процентная скидка
                if ($this->totalDiscount >= 100) {
                    session()->flash('message', 'Процент скидки не может быть 100% или больше.');
                    $finalSum = 0.01;
                } else {
                    $discountAmount = $initialSum * ($this->totalDiscount / 100);
                    if ($discountAmount >= $initialSum) {
                        session()->flash('message', 'Скидка не может быть больше или равна сумме продажи.');
                        $discountAmount = $initialSum - 0.01;
                    }
                    $finalSum = $initialSum - $discountAmount;
                }
            }
        } else {
            $finalSum = $initialSum;
        }

        return [$initialSum, $finalSum];
    }



    private function convertSums($initialSum, $finalSum)
    {

        $saleCurrency = $this->currencyId
            ? $this->currencies->firstWhere('id', $this->currencyId)
            : $this->currencies->firstWhere('is_default', true);

        $cashRegisterCurrency = null;
        if ($this->cashId) {
            $cashRegister = $this->cashRegisters->firstWhere('id', $this->cashId);
            $cashRegisterCurrency = $this->currencies->firstWhere('id', $cashRegister->currency_id);
        }

        if ($cashRegisterCurrency && $saleCurrency->id !== $cashRegisterCurrency->id) {
            $convertedInitialSum = CurrencyConverter::convert($initialSum, $saleCurrency, $cashRegisterCurrency);
            $convertedFinalSum   = CurrencyConverter::convert($finalSum, $saleCurrency, $cashRegisterCurrency);
        } else {
            $convertedInitialSum = $initialSum;
            $convertedFinalSum   = $finalSum;
        }
        return [
            'initialSum'   => $convertedInitialSum,
            'finalSum'     => $convertedFinalSum,
            'currencyCode' => $saleCurrency->code,
        ];
    }


    /**
     * Обновляет запись продажи с рассчитанными суммами, исходной ценой, валютой и скидкой.
     */
    private function updateSaleRecord($sale, $convertedSums, $initialSum, $finalSum)
    {
        // Определяем валюту кассы: если касса выбрана, берём её валюту, иначе – базовую (доллар)
        $saleCurrencyId = $this->cashId
            ? $this->currencies->firstWhere('id', $this->cashRegisters->firstWhere('id', $this->cashId)->currency_id)->id
            : $this->currencies->firstWhere('is_default', true)->id;

        $sale->update([
            'client_id'    => $this->clientId,
            'warehouse_id' => $this->warehouseId,
            'note'         => $this->note,
            // 'currency_id'  => $saleCurrencyId,
            'total_price'  => $finalSum,
            'cash_id'      => $this->cashId,
            'user_id'      => Auth::id(),
            'date'         => now(),
            'price'        => $initialSum,
            'cash_price'   => $convertedSums['initialSum'],
            'project_id'   => $this->projectId,
            'discount'     => $initialSum - $finalSum,
        ]);
    }

    /**
     * Обрабатывает платеж в зависимости от выбранного типа оплаты.
     */
    private function processPayment($sale, $finalConvertedSum, $initialSum)
    {
        if ($this->paymentType == 1) {
            // Оплата через кассу
            $this->registerCashTransaction($sale, $finalConvertedSum, $initialSum);
        } else {
            // Оплата через баланс клиента
            $this->updateClientBalance($sale, $finalConvertedSum);
        }
    }
    private function updateClientBalance($sale, $finalConvertedSum)
    {
        // Обновляем только, если запись баланса существует
        $clientBalance = ClientBalance::where('client_id', $this->clientId)->first();


        // При оплате через баланс клиент платит, поэтому баланс уменьшается
        $clientBalance->balance += $finalConvertedSum;
        $clientBalance->save();
    }

    /**
     * Регистрирует финансовую транзакцию для оплаты через кассу.
     */
    private function registerCashTransaction($sale, $finalConvertedSum, $initialSum)
{
    $cashRegister = $this->cashRegisters->firstWhere('id', $this->cashId);
    $cashRegisterCurrency = $this->currencies->firstWhere('id', $cashRegister->currency_id);

    $transactionData = [
        'client_id'         => $this->clientId,
        'orig_amount'       => $sale->cash_price,
        'amount'            => $finalConvertedSum,
        'currency_id'       => $cashRegisterCurrency->id,
        'date'              => $sale->date,
        'note'              => $this->note . ' Продажа',
        'sale_id'           => $sale->id,
        'user_id'           => Auth::id(),
        'cash_id'           => $this->cashId,
        'category_id'       => 1,
        'type'              => 1,
        'project_id'        => $this->projectId,
    ];

    if (empty($sale->transaction_id)) {
        // Создаём экземпляр транзакции, устанавливаем флаг и сохраняем
        $transaction = new Transaction();
        $transaction->fill($transactionData);
        $transaction->setSkipClientBalanceUpdate(true);
        $transaction->save();
        // dd($transaction->getSkipClientBalanceUpdate()); // отладка: теперь должно вывести true
        $sale->update(['transaction_id' => $transaction->id]);
    } else {
        $transaction = Transaction::find($sale->transaction_id);
        $transaction->fill($transactionData);
        $transaction->setSkipClientBalanceUpdate(true);
        $transaction->save();
    }

    $cashRegister->balance += $finalConvertedSum;
    $cashRegister->save();
}

    public function edit($id)
    {
        $sale = Sale::with('products')->findOrFail($id);
        $this->saleId        = $sale->id;
        $this->clientId      = $sale->client_id;
        $this->warehouseId   = $sale->warehouse_id;
        $this->note          = $sale->note;
        $this->currencyId    = $sale->currency_id;
        $this->totalPrice    = $sale->total_price;
        $this->totalDiscount = $sale->discount;
        $this->cashId        = $sale->cash_id;
        $this->cash_price     = $sale->cash_price;

        $this->selectedProducts = collect($sale->products)
            ->mapWithKeys(function ($product) {
                return [
                    $product->pivot->product_id => [
                        'name'     => $product->name,
                        'quantity' => $product->pivot->quantity,
                        'price'    => $product->pivot->price,
                        'image'    => $product->image ?? null,
                    ]
                ];
            })->toArray();

        session()->flash('message', 'Нельзя редактировать, только удалить.');
        $this->showForm = true;
    }

    public function delete()
    {
        if (!$this->saleId) {
            session()->flash('message', 'Продажа не найдена.');
            return;
        }

        DB::transaction(function () {
            $sale = Sale::with('products')->findOrFail($this->saleId);
            $clientId = $sale->client_id;

            // Восстанавливаем остатки склада для каждого товара
            collect($sale->products)->each(function ($product) use ($sale) {
                WarehouseStock::updateOrCreate(
                    ['warehouse_id' => $sale->warehouse_id, 'product_id' => $product->pivot->product_id],
                    ['quantity' => DB::raw('quantity + ' . $product->pivot->quantity)]
                );
            });

            // Отменяем эффект транзакции в кассе, если продажа оплачена через кассу
            if (!empty($sale->transaction_id)) {
                $transaction = Transaction::find($sale->transaction_id);
                if ($transaction) {
                    $cashRegister = \App\Models\CashRegister::find($sale->cash_id);
                    // Так как при регистрации продажи баланс увеличивался, теперь его уменьшаем
                    $cashRegister->balance -= $transaction->amount;
                    $cashRegister->save();
                }
                Transaction::where('id', $sale->transaction_id)->delete();
            }

            $saleAmount = $sale->total_price;


            $saleCurrency   = $this->currencies->firstWhere('id', $sale->currency_id);
            $defaultCurrency = $this->currencies->firstWhere('is_default', true);

            if ($saleCurrency->id != $defaultCurrency->id) {
                $convertedSaleAmount = CurrencyConverter::convert($saleAmount, $saleCurrency, $defaultCurrency);
            } else {
                $convertedSaleAmount = $saleAmount;
            }

            if (empty($sale->cash_id)) {
                ClientBalance::where('client_id', $clientId)
                    ->decrement('balance', $convertedSaleAmount);
            } else {
                ClientBalance::where('client_id', $clientId)
                    ->increment('balance', $convertedSaleAmount);
            }
        });

        session()->flash('success', 'Продажа и связанная транзакция удалены, баланс клиента обновлён.');
        $this->closeForm();
    }


    // Поиск клиентов
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
        $this->totalDiscount = $this->selectedClient->discount ?? 0;
        $this->totalDiscountType = $this->selectedClient->discount_type ?? 'fixed';
    }

    public function deselectClient()
    {
        $this->selectedClient = null;
        $this->clientId = null;
        $this->clientSearch = '';
        $this->clientResults = [];
    }

    // Поиск товаров
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
}
