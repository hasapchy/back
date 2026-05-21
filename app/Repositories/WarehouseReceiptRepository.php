<?php

namespace App\Repositories;

use App\Enums\WhReceiptStatus;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\ProductPrice;
use App\Models\Transaction;
use App\Models\WarehouseStock;
use App\Models\WhReceipt;
use App\Models\WhReceiptProduct;
use App\Models\WhPurchase;
use App\Models\WhPurchaseProduct;
use App\Models\WhUser;
use App\Repositories\Concerns\ResolvesWarehouseLineOrigDisplay;
use App\Services\CacheService;
use App\Services\WarehouseDocumentPaymentStatusService;
use App\Services\Timeline\WarehouseTimelineCache;
use App\Services\CurrencyConverter;
use App\Services\InventoryLockService;
use App\Services\ReceiptExpenseAllocationService;
use App\Services\RoundingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WarehouseReceiptRepository extends BaseRepository
{
    use ResolvesWarehouseLineOrigDisplay;

    /**
     * Получить оприходования с пагинацией
     *
     * @param  int  $userUuid  ID пользователя
     * @param  int  $perPage  Количество записей на страницу
     * @param  int  $page  Номер страницы
     * @param  int|null  $clientId  Фильтр по поставщику
     * @param  string|null  $status  Фильтр по статусу закупки (значение WhReceiptStatus)
     * @param  int|null  $warehouseId  Фильтр по складу
     * @param  int|null  $productId  Фильтр по товару в строках прихода
     * @param  string  $dateFilter  Период: all_time, today, custom и т.д.
     * @param  string|null  $startDate  Начало периода при dateFilter=custom
     * @param  string|null  $endDate  Конец периода при dateFilter=custom
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<WhReceipt>
     */
    public function getItemsWithPagination(
        int $userUuid,
        int $perPage = 20,
        int $page = 1,
        ?int $clientId = null,
        ?string $status = null,
        ?int $warehouseId = null,
        ?int $productId = null,
        string $dateFilter = 'all_time',
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $paymentStatus = null,
    ) {
        /** @var \App\Models\User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('warehouse_receipts_paginated', [
            $userUuid,
            $perPage,
            $clientId,
            $status,
            $warehouseId,
            $productId,
            $dateFilter,
            $startDate,
            $endDate,
            $paymentStatus,
            $currentUser?->id,
            $companyId,
        ]);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $page, $clientId, $status, $warehouseId, $productId, $dateFilter, $startDate, $endDate, $paymentStatus) {
            $query = $this->buildBaseQuery($userUuid);
            if ($clientId) {
                $query->where('wh_receipts.supplier_id', $clientId);
            }
            $statusEnum = $status ? WhReceiptStatus::tryFrom($status) : null;
            if ($statusEnum !== null) {
                $query->where('wh_receipts.status', $statusEnum);
            }
            if ($warehouseId) {
                $query->where('wh_receipts.warehouse_id', $warehouseId);
            }
            if ($productId) {
                $query->whereHas('products', fn ($q) => $q->where('product_id', $productId));
            }
            if ($dateFilter !== '' && $dateFilter !== 'all_time') {
                $this->applyDateFilter($query, $dateFilter, $startDate, $endDate, 'wh_receipts.date');
            }

            app(WarehouseDocumentPaymentStatusService::class)->applyReceiptPaymentStatusFilter($query, $paymentStatus);

            $paginator = $query->orderBy('wh_receipts.created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int) $page);
            app(WarehouseDocumentPaymentStatusService::class)->attachPaymentStatusToReceipts($paginator->getCollection());

            return $paginator;
        }, (int) $page);
    }

    /**
     * Получить оприходование по ID
     *
     * @param  int  $id  ID оприходования
     * @param  int  $userUuid  ID пользователя
     * @return WhReceipt|null
     */
    public function getItemById(int $id, int $userUuid): ?WhReceipt
    {
        $cacheKey = $this->generateCacheKey('warehouse_receipts_item', [$id, $userUuid]);

        return CacheService::getReferenceData($cacheKey, function () use ($id, $userUuid) {
            $receipt = $this->buildBaseQuery($userUuid)
                ->where('wh_receipts.id', $id)
                ->first();
            if ($receipt) {
                app(WarehouseDocumentPaymentStatusService::class)->attachPaymentStatusToReceipts([$receipt]);
            }

            return $receipt;
        });
    }

    /**
     * Построить базовый запрос для оприходований
     *
     * @param  int  $userUuid  ID пользователя
     * @return Builder<WhReceipt>
     */
    protected function buildBaseQuery(int $userUuid): Builder
    {
        $query = WhReceipt::select([
            'wh_receipts.id',
            'wh_receipts.warehouse_id',
            'wh_receipts.supplier_id',
            'wh_receipts.purchase_id',
            'wh_receipts.client_balance_id',
            'wh_receipts.amount',
            'wh_receipts.orig_amount',
            'wh_receipts.orig_currency_id',
            'wh_receipts.cash_id',
            'wh_receipts.note',
            'wh_receipts.creator_id',
            'wh_receipts.date',
            'wh_receipts.status',
            'wh_receipts.created_at',
            'wh_receipts.updated_at',
            'clients.first_name as client_first_name',
            'clients.last_name as client_last_name'
        ])
            ->leftJoin('clients', 'wh_receipts.supplier_id', '=', 'clients.id')
            ->with([
                'warehouse:id,name,company_id',
                'cashRegister:id,name,currency_id,is_cash',
                'cashRegister.currency:id,name,symbol',
                'origCurrency:id,name,symbol',
                'creator:id,name',
                'supplier:id,first_name,last_name,status,balance',
                'supplier.phones:id,client_id,phone',
                'supplier.emails:id,client_id,email',
                'purchase:id,supplier_id,status,amount',
                'clientBalance:id,client_id,currency_id,type',
                'products:id,receipt_id,product_id,quantity,price,orig_unit_price,orig_currency_id,orig_unit_id,orig_quantity',
                'products.product:id,name,image,unit_id',
                'products.product.unit:id,name,short_name',
                'products.origUnit:id,name,short_name',
            ]);

        if ($this->shouldApplyUserFilter('warehouses')) {
            $filterUserId = $this->getFilterUserIdForPermission('warehouses', $userUuid);
            $warehouseIds = WhUser::where('user_id', $filterUserId)
                ->pluck('warehouse_id')
                ->toArray();

            if (empty($warehouseIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('wh_receipts.warehouse_id', $warehouseIds);
            }
        }

        return $this->addCompanyFilterThroughRelation($query, 'warehouse');
    }


    /**
     * Создать оприходование
     *
     * @param  array<string, mixed>  $data  Данные оприходования (в т.ч. client_balance_id)
     * @return int ID созданного прихода
     *
     * @throws \Exception
     */
    public function createItem(array $data): int
    {
        $client_id = $data['client_id'];
        $warehouse_id = $data['warehouse_id'];
        $cash_id = $this->normalizeOptionalPositiveIntId($data['cash_id'] ?? null);
        $purchase_id = $this->normalizeOptionalPositiveIntId($data['purchase_id'] ?? null);
        $date = $data['date'] ?? now();
        $note = $data['note'] ?? null;
        $products = $data['products'];
        $client_balance_id = $data['client_balance_id'] ?? null;
        $status = $this->resolveReceiptStatusFromInput($data['status'] ?? null);

        if ($status === WhReceiptStatus::Completed) {
            throw new \RuntimeException((string) __('warehouse_receipt.cannot_create_completed'));
        }

        app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $warehouse_id);

        return DB::transaction(function () use ($data, $client_id, $warehouse_id, $cash_id, $date, $note, $products, $client_balance_id, $status, $purchase_id) {
            $defaultCurrency = Currency::firstWhere('is_default', true);
            $lineCurrency = $defaultCurrency;
            if ($cash_id !== null) {
                $cash = CashRegister::query()->find($cash_id);
                if ($cash === null) {
                    throw new \RuntimeException((string) __('warehouse_receipt.cash_register_not_found'));
                }
                if ($cash->currency_id) {
                    $lineCurrency = Currency::query()->findOrFail((int) $cash->currency_id);
                }
            }

            $roundingService = new RoundingService();
            $companyId = (int) ($this->getCurrentCompanyId() ?? 0);
            foreach ($products as $idx => $product) {
                $q = $roundingService->roundQuantityForCompany($companyId, (float) ($product['quantity']));
                $products[$idx]['quantity'] = $q;
            }

            $total_amount = 0;
            foreach ($products as $product) {
                $total_amount += $product['price'] * $product['quantity'];
            }
            $total_amount = $roundingService->roundForCompany($companyId, (float) $total_amount);

            $totalInDefault = $this->sumReceiptLinesInDefaultCurrency($products, $lineCurrency, $companyId, $date);

            if ($purchase_id !== null) {
                $purchase = WhPurchase::query()->with('products')->lockForUpdate()->findOrFail($purchase_id);
                if ($purchase->status === \App\Enums\WhPurchaseStatus::Draft) {
                    throw new \RuntimeException('Нельзя создать оприходование из закупки в статусе Черновик');
                }
                $this->assertPurchaseReceiptQuantities($purchase, $products, null);
            }

            $receipt = new WhReceipt();
            $receipt->supplier_id = $client_id;
            $receipt->purchase_id = $purchase_id;
            $receipt->client_balance_id = $client_balance_id;
            $receipt->warehouse_id = $warehouse_id;
            $receipt->cash_id = $cash_id;
            $receipt->date = $date;
            $receipt->note = $note;
            $lineCurrencyId = (int) $lineCurrency->id;
            $receipt->orig_amount = $total_amount;
            $receipt->orig_currency_id = $lineCurrencyId;
            $receipt->amount = $totalInDefault;
            $receipt->creator_id = (int) auth('api')->id();
            $receipt->status = $status;
            $receipt->save();

            foreach ($products as $product) {
                $lineOrig = $this->resolveWarehouseLineOrigAmount($product, $lineCurrencyId);
                $receiptProduct = new WhReceiptProduct();
                $receiptProduct->receipt_id = $receipt->id;
                $receiptProduct->product_id = $product['product_id'];
                $receiptProduct->quantity = $product['quantity'];
                $receiptProduct->price = $lineOrig['price'];
                $receiptProduct->orig_unit_price = $lineOrig['orig_unit_price'];
                $receiptProduct->orig_currency_id = $lineOrig['orig_currency_id'];
                $orig = $this->resolveWarehouseLineOrigDisplay($product);
                $receiptProduct->orig_unit_id = $orig['orig_unit_id'];
                $receiptProduct->orig_quantity = $orig['orig_quantity'];
                $receiptProduct->save();
            }

            if ($purchase_id === null) {
                $debtTransactionData = $this->buildReceiptTransactionData([
                    'amount' => $totalInDefault,
                    'currency_id' => $defaultCurrency->id,
                    'cash_id' => $cash_id,
                    'client_id' => $client_id,
                    'client_balance_id' => $client_balance_id,
                    'note' => $note,
                    'date' => $date,
                    'is_debt' => true,
                ]);
                $this->createTransactionForSource($debtTransactionData, \App\Models\WhReceipt::class, $receipt->id, true);
            }

            Log::info('wh_receipt_created', [
                'receipt_id' => $receipt->id,
                'debt_amount_default' => $totalInDefault,
            ]);

            $this->invalidateCaches();
            WarehouseTimelineCache::forgetReceipt((int) $receipt->id);

            return $receipt->id;
        });
    }

    /**
     * @param  array<int, array{product_id:int, quantity:float|int|string, price:float|int|string}>  $products
     */
    private function assertPurchaseReceiptQuantities(WhPurchase $purchase, array $products, ?int $editingReceiptId): void
    {
        $companyId = $this->getCurrentCompanyId();
        $rounding = new RoundingService();
        $planned = [];
        $plannedPrices = [];
        /** @var WhPurchaseProduct $line */
        foreach ($purchase->products as $line) {
            $planned[(int) $line->product_id] = $rounding->roundQuantityForCompany($companyId, (float) $line->quantity);
            $plannedPrices[(int) $line->product_id] = $line->documentCurrencyUnitPrice();
        }

        $received = WhReceiptProduct::query()
            ->whereHas('receipt', function ($q) use ($purchase, $editingReceiptId) {
                $q->where('purchase_id', $purchase->id);
                if ($editingReceiptId !== null) {
                    $q->where('id', '!=', $editingReceiptId);
                }
            })
            ->selectRaw('product_id, sum(quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id')
            ->map(fn ($v) => (float) $v)
            ->all();

        foreach ($products as $product) {
            $productId = (int) ($product['product_id'] ?? 0);
            $incoming = $rounding->roundQuantityForCompany($companyId, (float) ($product['quantity'] ?? 0));
            $plannedQty = (float) ($planned[$productId] ?? 0);
            $receivedQty = (float) ($received[$productId] ?? 0);
            if ($plannedQty <= 0 || $incoming + $receivedQty > $plannedQty + 1e-9) {
                throw new \RuntimeException('Количество в оприходовании не может быть больше, чем в закупке');
            }
            $incomingPrice = (float) ($product['price'] ?? 0);
            $plannedPrice = (float) ($plannedPrices[$productId] ?? 0);
            if (abs($incomingPrice - $plannedPrice) > 1e-9) {
                throw new \RuntimeException('Цена в оприходовании из закупки должна совпадать с ценой закупки');
            }
        }
    }

    /**
     * Обновить оприходование
     *
     * @param  int  $receipt_id  ID оприходования
     * @param  array<string, mixed>  $data  Данные для обновления
     *
     * @throws \Exception
     */
    public function updateReceipt(int $receipt_id, array $data): bool
    {
        $client_id    = $data['client_id'];
        $warehouse_id = $data['warehouse_id'];
        $cash_id      = $this->normalizeOptionalPositiveIntId($data['cash_id'] ?? null);
        $date         = $data['date'];
        $note         = $data['note'] ?? null;
        $products     = $data['products'];

        app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $warehouse_id);

        $updated = DB::transaction(function () use ($receipt_id, $client_id, $warehouse_id, $cash_id, $date, $note, $products, $data) {
            $receipt = WhReceipt::findOrFail($receipt_id);

            if ($receipt->status === WhReceiptStatus::Completed) {
                throw new \RuntimeException((string) __('warehouse_receipt.receipt_completed_readonly'));
            }

            if (array_key_exists('status', $data) && $data['status'] !== null && $data['status'] !== '') {
                $incomingStatus = $this->resolveReceiptStatusFromInput($data['status']);
                if ($incomingStatus === WhReceiptStatus::Completed) {
                    throw new \RuntimeException((string) __('warehouse_receipt.completion_via_update_forbidden'));
                }
                $receipt->status = $incomingStatus;
                $receipt->saveQuietly();
            }

            $old_total_amount = $receipt->amount;

            if ($receipt->purchase_id !== null) {
                $purchase = WhPurchase::query()->with('products')->lockForUpdate()->findOrFail((int) $receipt->purchase_id);
                if ($purchase->status === \App\Enums\WhPurchaseStatus::Draft) {
                    throw new \RuntimeException('Нельзя создать оприходование из закупки в статусе Черновик');
                }
                $this->assertPurchaseReceiptQuantities($purchase, $products, (int) $receipt_id);
            }

            $receipt->supplier_id = $client_id;
            $receipt->warehouse_id = $warehouse_id;
            $receipt->cash_id = $cash_id;
            $receipt->date = $date;
            $receipt->note = $note;

            $total_amount = 0.0;
            $totalInDefault = 0.0;
            $existingProducts = WhReceiptProduct::where('receipt_id', $receipt_id)->get();
            $existingProductIds = $existingProducts->pluck('product_id')->toArray();

            $lineCurrency = Currency::firstWhere('is_default', true);
            if ($cash_id !== null) {
                $cash = CashRegister::query()->find($cash_id);
                if ($cash === null) {
                    throw new \RuntimeException((string) __('warehouse_receipt.cash_register_not_found'));
                }
                if ($cash->currency_id) {
                    $lineCurrency = Currency::query()->findOrFail((int) $cash->currency_id);
                }
            }
            $lineCurrencyId = (int) $lineCurrency->id;

            foreach ($products as $product) {
                $product_id = $product['product_id'];
                $quantity = (new RoundingService())->roundQuantityForCompany($this->getCurrentCompanyId(), (float) ($product['quantity']));
                $lineOrig = $this->resolveWarehouseLineOrigAmount($product, $lineCurrencyId, $date);

                WhReceiptProduct::updateOrCreate(
                    ['receipt_id' => $receipt->id, 'product_id' => $product_id],
                    array_merge(
                        [
                            'quantity' => $quantity,
                            'price' => $lineOrig['price'],
                            'orig_unit_price' => $lineOrig['orig_unit_price'],
                            'orig_currency_id' => $lineOrig['orig_currency_id'],
                        ],
                        $this->resolveWarehouseLineOrigDisplay($product)
                    )
                );

                $total_amount += (float) $lineOrig['orig_unit_price'] * $quantity;
                $totalInDefault += (float) $lineOrig['price'] * $quantity;
            }

            $roundingService = new RoundingService();
            $companyId = (int) ($this->getCurrentCompanyId() ?? 0);
            $total_amount = $roundingService->roundForCompany($companyId, (float) $total_amount);
            $totalInDefault = $roundingService->roundForCompany($companyId, (float) $totalInDefault);
            $receipt->orig_amount = $total_amount;
            $receipt->orig_currency_id = $lineCurrencyId;
            $receipt->amount = $totalInDefault;
            $receipt->save();

            $transactions = $receipt->transactions()->get();
            if ($transactions->isEmpty()) {
                $this->updateClientBalance($client_id, $totalInDefault - $old_total_amount);
            }
            $this->syncDraftAutoTransactions($receipt, $totalInDefault);

            $incomingProductIds = collect($products)
                ->map(fn ($product) => (int) ($product['product_id'] ?? 0))
                ->filter(fn (int $id) => $id > 0)
                ->values()
                ->all();
            $deletedProducts = array_diff($existingProductIds, $incomingProductIds);
            foreach ($deletedProducts as $deletedProductId) {
                $deletedProduct = $existingProducts->firstWhere('product_id', $deletedProductId);
                if ($deletedProduct) {
                    $deletedProduct->delete();
                }
            }

            app(ReceiptExpenseAllocationService::class)->syncAllForReceipt((int) $receipt_id);

            $this->invalidateCaches();
            WarehouseTimelineCache::forgetReceipt((int) $receipt_id);

            return true;
        });

        return $updated;
    }

    /**
     * Синхронизировать автотранзакцию долга по товарам чернового оприходования.
     */
    private function syncDraftAutoTransactions(WhReceipt $receipt, float $totalInDefault): void
    {
        if ($receipt->purchase_id !== null) {
            return;
        }

        /** @var Transaction|null $debtTx */
        $debtTx = Transaction::query()
            ->where('source_type', WhReceipt::class)
            ->where('source_id', (int) $receipt->id)
            ->where('category_id', 6)
            ->where('is_debt', true)
            ->where('is_deleted', false)
            ->orderBy('id')
            ->first();

        if (! $debtTx) {
            return;
        }

        $defaultCurrency = $this->getDefaultCurrency();

        app(TransactionsRepository::class)->updateItem((int) $debtTx->id, [
            'type' => 0,
            'category_id' => 6,
            'client_id' => (int) $receipt->supplier_id,
            'project_id' => null,
            'date' => $receipt->date,
            'note' => $receipt->note,
            'source_type' => WhReceipt::class,
            'source_id' => (int) $receipt->id,
            'cash_id' => $receipt->cash_id,
            'client_balance_id' => $receipt->client_balance_id,
            'is_debt' => true,
            'orig_amount' => $totalInDefault,
            'currency_id' => (int) $defaultCurrency->id,
        ]);
    }

    /**
     * Закрыть оприходование: пересчитать разнесение расходов, записать unit landed cost в purchase_price, статус completed.
     *
     * @param  string|null  $date
     * @param  string|null  $note
     *
     * @throws \Throwable
     */
    public function completeReceipt(int $receipt_id, ?string $date = null, ?string $note = null): void
    {
        DB::transaction(function () use ($receipt_id, $date, $note): void {
            $receipt = WhReceipt::query()->lockForUpdate()->findOrFail($receipt_id);

            if ($receipt->status === WhReceiptStatus::Completed) {
                throw new \RuntimeException((string) __('warehouse_receipt.already_completed'));
            }

            if (! $this->receiptEligibleForCompletion($receipt)) {
                throw new \RuntimeException((string) __('warehouse_receipt.completion_not_ready'));
            }

            app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $receipt->warehouse_id);

            if ($date !== null && $date !== '') {
                $receipt->date = $date;
            }
            if ($note !== null) {
                $receipt->note = $note;
            }
            if (($date !== null && $date !== '') || $note !== null) {
                $receipt->saveQuietly();
            }

            app(ReceiptExpenseAllocationService::class)->syncAllForReceipt($receipt_id);

            $receiptForSummary = $receipt->fresh([
                'products.product',
                'products.product.unit',
                'cashRegister.currency',
                'warehouse',
                'expenseAllocations',
            ]);

            if (! $receiptForSummary instanceof WhReceipt) {
                throw new \RuntimeException((string) __('warehouse_receipt.not_found'));
            }

            $summary = app(ReceiptExpenseAllocationService::class)->buildLandedCostSummary($receiptForSummary);
            $companyId = (int) ($receiptForSummary->warehouse?->company_id ?? 0);
            $rounding = new RoundingService();

            foreach ($summary['lines'] as $line) {
                $productId = (int) ($line['product_id'] ?? 0);
                $qty = (float) ($line['quantity'] ?? 0);
                if ($productId <= 0 || $qty <= 1e-12) {
                    continue;
                }

                $landedLine = (float) ($line['landed_line_total_default'] ?? 0);
                $unit = $landedLine / $qty;
                if ($companyId > 0) {
                    $unit = $rounding->roundForCompany($companyId, $unit);
                }
                if ($unit <= 1e-12) {
                    continue;
                }

                $this->updateProductPurchasePrice($productId, $unit);
            }

            $this->applyReceiptProductsToStock($receipt);

            $receipt->status = WhReceiptStatus::Completed;
            $receipt->saveQuietly();

            $this->invalidateCaches();
            WarehouseTimelineCache::forgetReceipt($receipt_id);
        });
    }

    /**
     * @return bool
     */
    private function receiptEligibleForCompletion(WhReceipt $receipt): bool
    {
        return true;
    }

    /**
     * Удалить оприходование
     *
     * @param int $receipt_id ID оприходования
     * @return bool
     */
    public function deleteItem($receipt_id)
    {
        $meta = DB::transaction(function () use ($receipt_id) {
            $receipt = WhReceipt::findOrFail($receipt_id);
            app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $receipt->warehouse_id);
            $reverseStock = $receipt->status === WhReceiptStatus::Completed;
            $this->reverseReceiptStockAndDeleteLines($receipt, $reverseStock);

            $rid = (int) $receipt->id;
            $wid = (int) $receipt->warehouse_id;
            $receipt->delete();

            return ['rid' => $rid, 'wid' => $wid];
        });
        if (is_array($meta)) {
            WarehouseTimelineCache::forgetReceipt($meta['rid'], $meta['wid']);
            $this->invalidateCaches();
        }

        return is_array($meta);
    }

    /**
     * Оприходование излишка после инвентаризации (без транзакций взаиморасчётов).
     *
     * @param  array<int, array{product_id: int, quantity: float}>  $products
     */
    public function createInventoryOverageReceipt(int $warehouseId, string $note, array $products): int
    {
        if ($products === []) {
            throw new \RuntimeException('EMPTY_RECEIPT_PRODUCTS');
        }

        app(InventoryLockService::class)->checkWarehouseIsUnlocked($warehouseId);

        return (int) DB::transaction(function () use ($warehouseId, $note, $products) {
            $roundingService = new RoundingService();
            $companyId = $this->getCurrentCompanyId();
            $normalized = [];
            foreach ($products as $product) {
                $q = $roundingService->roundQuantityForCompany($companyId, (float) ($product['quantity'] ?? 0));
                if ($q > 0) {
                    $pid = (int) $product['product_id'];
                    $normalized[] = [
                        'product_id' => $pid,
                        'quantity' => $q,
                        'price' => $this->resolveCurrentPurchasePrice($pid),
                    ];
                }
            }
            if ($normalized === []) {
                throw new \RuntimeException('EMPTY_RECEIPT_PRODUCTS');
            }

            $receipt = new WhReceipt();
            $receipt->supplier_id = null;
            $receipt->warehouse_id = $warehouseId;
            $receipt->note = $note;
            $receipt->date = now();
            $receipt->creator_id = (int) auth('api')->id();
            $receipt->cash_id = null;
            $receipt->client_balance_id = null;

            $totalAmount = 0.0;
            foreach ($normalized as $product) {
                $totalAmount += $product['price'] * $product['quantity'];
            }
            $defaultCurrency = Currency::firstWhere('is_default', true);
            $defaultCurrencyId = (int) $defaultCurrency->id;
            $receipt->orig_amount = $roundingService->roundForCompany($companyId, $totalAmount);
            $receipt->orig_currency_id = $defaultCurrencyId;
            $receipt->amount = $receipt->orig_amount;
            $receipt->status = WhReceiptStatus::Completed;
            $receipt->save();

            foreach ($normalized as $product) {
                $price = (float) $product['price'];
                $receiptProduct = new WhReceiptProduct();
                $receiptProduct->receipt_id = $receipt->id;
                $receiptProduct->product_id = $product['product_id'];
                $receiptProduct->quantity = $product['quantity'];
                $receiptProduct->price = $price;
                $receiptProduct->orig_unit_price = $price;
                $receiptProduct->orig_currency_id = $defaultCurrencyId;
                $receiptProduct->save();
            }

            $this->applyLegacyReceiptProductLinesToStock($warehouseId, $normalized);

            $this->invalidateCaches();
            WarehouseTimelineCache::forgetReceipt((int) $receipt->id);

            return $receipt->id;
        });
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float, price: float}>  $lines
     */
    private function applyLegacyReceiptProductLinesToStock(int $warehouseId, array $lines): void
    {
        foreach ($lines as $product) {
            $this->updateStock($warehouseId, (int) $product['product_id'], (float) $product['quantity']);
            $this->updateProductPurchasePrice((int) $product['product_id'], (float) $product['price']);
        }
    }

    private function applyReceiptProductsToStock(WhReceipt $receipt): void
    {
        foreach ($receipt->products()->get() as $line) {
            $this->updateStock((int) $receipt->warehouse_id, (int) $line->product_id, (float) $line->quantity);
        }
    }

    /**
     * Актуальная закупочная цена из последней записи product_prices.
     *
     * @throws \RuntimeException
     */
    private function resolveCurrentPurchasePrice(int $productId): float
    {
        $pp = ProductPrice::query()
            ->where('product_id', $productId)
            ->orderByDesc('id')
            ->first();

        if (! $pp) {
            throw new \RuntimeException('PRODUCT_PURCHASE_PRICE_MISSING');
        }

        return (float) $pp->purchase_price;
    }

    /**
     * @return void
     */
    private function reverseReceiptStockAndDeleteLines(WhReceipt $receipt, bool $reverseStock): void
    {
        $receiptId = (int) $receipt->id;
        if ($reverseStock) {
            $this->assertReceiptDeletionWillNotMakeNegativeStock($receipt);
        }
        foreach (WhReceiptProduct::query()->where('receipt_id', $receiptId)->get() as $p) {
            if ($reverseStock) {
                $this->updateStock((int) $receipt->warehouse_id, (int) $p->product_id, -(float) $p->quantity);
            }
        }
        WhReceiptProduct::query()->where('receipt_id', $receiptId)->delete();
    }

    private function assertReceiptDeletionWillNotMakeNegativeStock(WhReceipt $receipt): void
    {
        $receiptLines = WhReceiptProduct::query()
            ->where('receipt_id', (int) $receipt->id)
            ->get();

        foreach ($receiptLines as $line) {
            $stock = WarehouseStock::query()
                ->where('warehouse_id', (int) $receipt->warehouse_id)
                ->where('product_id', (int) $line->product_id)
                ->lockForUpdate()
                ->first();

            $currentQty = (float) ($stock?->quantity ?? 0.0);
            $removeQty = (float) $line->quantity;
            if (($currentQty - $removeQty) < -1e-9) {
                throw new \RuntimeException('Нельзя удалить оприходование: остаток по товару уйдет в минус');
            }
        }
    }

    public function deleteReceiptWithoutInventoryLock(int $receiptId): void
    {
        $warehouseId = 0;
        DB::transaction(function () use ($receiptId, &$warehouseId): void {
            $receipt = WhReceipt::query()->lockForUpdate()->findOrFail($receiptId);
            $warehouseId = (int) $receipt->warehouse_id;

            $this->reverseReceiptStockAndDeleteLines($receipt, true);

            $receipt->delete();
        });

        WarehouseTimelineCache::forgetReceipt($receiptId, $warehouseId > 0 ? $warehouseId : null);
        $this->invalidateCaches();
    }

    public function invalidateWarehouseReceiptCaches(): void
    {
        $this->invalidateCaches();
    }

    public function applyWarehouseStockDelta(int $warehouseId, int $productId, float $delta): void
    {
        $this->updateStock($warehouseId, $productId, $delta);
    }

    public function applyProductPurchasePriceUpdate(int $productId, float $price): void
    {
        $this->updateProductPurchasePrice($productId, $price);
    }

    public function syncReceiptFulfillmentStatus(WhReceipt $receipt): void
    {
        if ($receipt->status === WhReceiptStatus::Completed) {
            return;
        }
        $receipt->status = WhReceiptStatus::Draft;
        $receipt->saveQuietly();
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float, price: float}>  $products
     */
    private function sumReceiptLinesInDefaultCurrency(array $products, Currency $lineCurrency, int $companyId, mixed $date): float
    {
        $defaultCurrency = Currency::firstWhere('is_default', true);
        $rounding = new RoundingService();
        $sum = 0.0;
        $dateStr = \Illuminate\Support\Carbon::parse($date)->format('Y-m-d');

        foreach ($products as $p) {
            $line = (float) $p['price'] * (float) $p['quantity'];
            if ($lineCurrency->id !== $defaultCurrency->id) {
                $line = CurrencyConverter::convert($line, $lineCurrency, $defaultCurrency, $defaultCurrency, $companyId, $dateStr);
            }
            $sum += $line;
        }

        return $rounding->roundForCompany($companyId, $sum);
    }

    /**
     * @param  mixed  $value
     */
    private function normalizeOptionalPositiveIntId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        $id = (int) $value;
        if ($id <= 0) {
            return null;
        }

        return $id;
    }

    private function resolveReceiptStatusFromInput(mixed $value): WhReceiptStatus
    {
        if ($value === null || $value === '') {
            return WhReceiptStatus::Draft;
        }
        return WhReceiptStatus::tryFrom((string) $value) ?? WhReceiptStatus::Draft;
    }

    /**
     * Инвалидировать кэш
     *
     * @return void
     */
    private function invalidateCaches(): void
    {
        CacheService::invalidateWarehouseReceiptsCache();
        CacheService::invalidateWarehouseStocksCache();
        CacheService::invalidateProductsCache();
        CacheService::invalidateClientsCache();
        CacheService::invalidateWarehousePurchasesCache();
    }

    /**
     * Обновить остатки на складе
     *
     * @param int $warehouse_id ID склада
     * @param int $product_id ID товара
     * @param float $add_quantity Количество для добавления
     * @return bool
     */
    private function updateStock($warehouse_id, $product_id, $add_quantity)
    {
        $quantity = is_numeric($add_quantity) ? (float)$add_quantity : 0.0;

        $existingStock = WarehouseStock::where('warehouse_id', $warehouse_id)
            ->where('product_id', $product_id)
            ->lockForUpdate()
            ->first();

        if ($existingStock) {
            $existingStock->increment('quantity', $quantity);
        } else {
            WarehouseStock::create([
                'warehouse_id' => $warehouse_id,
                'product_id'   => $product_id,
                'quantity'    => $quantity,
            ]);
        }

        return true;
    }

    /**
     * Обновить цену покупки товара
     *
     * @param int $product_id ID товара
     * @param float $price Цена покупки
     * @return bool
     */
    private function updateProductPurchasePrice($product_id, $price)
    {
        ProductPrice::updateOrCreate(
            ['product_id' => $product_id],
            [
                'purchase_price' => $price,
                'date'           => now(),
            ]
        );
        return true;
    }

    /**
     * Обновить баланс клиента
     *
     * @param int $client_id ID клиента
     * @param float $amount Сумма
     * @return bool
     */
    private function updateClientBalance($client_id, $amount)
    {
        $amount = is_numeric($amount) ? (float)$amount : 0.0;
        \App\Models\Client::where('id', $client_id)->decrement('balance', $amount);
        return true;
    }

    /**
     * Построить данные транзакции для оприходования
     *
     * @param array<string, mixed> $data Данные для транзакции:
     *   - amount (float) Сумма транзакции
     *   - currency_id (int) ID валюты
     *   - cash_id (int|null) ID кассы
     *   - client_id (int) ID поставщика
     *   - note (string|null) Примечание
     *   - date (string) Дата транзакции
     *   - client_balance_id (int|null) Явный баланс поставщика
     * @return array<string, mixed> Данные транзакции для создания
     */
    private function buildReceiptTransactionData(array $data): array
    {
        return [
            'type' => 0,
            'creator_id' => auth('api')->id(),
            'amount' => $data['amount'],
            'orig_amount' => $data['amount'],
            'currency_id' => $data['currency_id'],
            'cash_id' => $data['cash_id'],
            'category_id' => 6,
            'client_id' => $data['client_id'],
            'client_balance_id' => $data['client_balance_id'] ?? null,
            'note' => $data['note'],
            'date' => $data['date'],
            'is_debt' => (bool) ($data['is_debt'] ?? true),
        ];
    }
}
