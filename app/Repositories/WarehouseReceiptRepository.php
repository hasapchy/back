<?php

namespace App\Repositories;

use App\Enums\WhReceiptStatus;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\ProductPrice;
use App\Models\WarehouseStock;
use App\Models\WhReceipt;
use App\Models\WhReceiptProduct;
use App\Models\WhUser;
use App\Models\WhWaybill;
use App\Models\WhWaybillProduct;
use App\Services\CacheService;
use App\Services\CurrencyConverter;
use App\Services\InventoryLockService;
use App\Services\ReceiptExpenseAllocationService;
use App\Services\RoundingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WarehouseReceiptRepository extends BaseRepository
{
    /**
     * Получить оприходования с пагинацией
     *
     * @param  int  $userUuid  ID пользователя
     * @param  int  $perPage  Количество записей на страницу
     * @param  int  $page  Номер страницы
     * @param  int|null  $clientId  Фильтр по поставщику
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<WhReceipt>
     */
    public function getItemsWithPagination(int $userUuid, int $perPage = 20, int $page = 1, ?int $clientId = null)
    {
        /** @var \App\Models\User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('warehouse_receipts_paginated', [$userUuid, $perPage, $clientId, $currentUser?->id, $companyId]);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $page, $clientId) {
            $query = $this->buildBaseQuery($userUuid);
            if ((int) $clientId > 0) {
                $query->where('wh_receipts.supplier_id', (int) $clientId);
            }
            return $query->orderBy('wh_receipts.created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
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
            return $this->buildBaseQuery($userUuid)
                ->where('wh_receipts.id', $id)
                ->first();
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
            'wh_receipts.client_balance_id',
            'wh_receipts.amount',
            'wh_receipts.cash_id',
            'wh_receipts.project_id',
            'wh_receipts.note',
            'wh_receipts.creator_id',
            'wh_receipts.date',
            'wh_receipts.is_legacy',
            'wh_receipts.is_simple',
            'wh_receipts.status',
            'wh_receipts.created_at',
            'wh_receipts.updated_at',
            'clients.first_name as client_first_name',
            'clients.last_name as client_last_name'
        ])
            ->leftJoin('clients', 'wh_receipts.supplier_id', '=', 'clients.id')
            ->with([
                'warehouse:id,name',
                'cashRegister:id,name,currency_id,is_cash',
                'cashRegister.currency:id,name,symbol',
                'creator:id,name',
                'project:id,name',
                'supplier:id,first_name,last_name,status,balance',
                'supplier.phones:id,client_id,phone',
                'supplier.emails:id,client_id,email',
                'clientBalance:id,client_id,currency_id,type',
                'products:id,receipt_id,product_id,quantity,price',
                'products.product:id,name,image,unit_id',
                'products.product.unit:id,name,short_name',
                'waybills:id,receipt_id,date,number,note,creator_id',
                'waybills.lines:id,waybill_id,product_id,quantity,price',
                'waybills.lines.product:id,name,image,unit_id',
                'waybills.lines.product.unit:id,name,short_name',
                'waybills.creator:id,name',
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
        $date = $data['date'] ?? now();
        $note = $data['note'] ?? null;
        $products = $data['products'];
        $client_balance_id = $data['client_balance_id'] ?? null;
        $is_legacy = (bool) ($data['is_legacy'] ?? false);
        $is_simple = (bool) ($data['is_simple'] ?? false);
        $status = $this->resolveReceiptStatusFromInput($data['status'] ?? null);

        app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $warehouse_id);

        return DB::transaction(function () use ($data, $client_id, $warehouse_id, $cash_id, $date, $note, $products, $client_balance_id, $is_legacy, $is_simple, $status) {
            $simpleWaybillId = null;
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
            $companyId = $this->getCurrentCompanyId();
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

            $receipt = new WhReceipt();
            $receipt->supplier_id = $client_id;
            $receipt->client_balance_id = $client_balance_id;
            $receipt->warehouse_id = $warehouse_id;
            $receipt->project_id = $data['project_id'] ?? null;
            $receipt->cash_id = $cash_id;
            $receipt->date = $date;
            $receipt->note = $note;
            $receipt->amount = $total_amount;
            $receipt->creator_id = (int) auth('api')->id();
            $receipt->is_legacy = $is_legacy;
            $receipt->is_simple = $is_simple;
            $receipt->status = $status;
            $receipt->save();

            foreach ($products as $product) {
                $receiptProduct = new WhReceiptProduct();
                $receiptProduct->receipt_id = $receipt->id;
                $receiptProduct->product_id = $product['product_id'];
                $receiptProduct->quantity = $product['quantity'];
                $receiptProduct->price = $product['price'];
                $receiptProduct->save();
            }

            if ($is_legacy) {
                $this->applyLegacyReceiptProductLinesToStock((int) $warehouse_id, $products);
            } elseif ($is_simple) {
                $simpleWaybillId = $this->createPrimaryWaybillWithLinesAndPostStock($receipt, (int) $warehouse_id, $products, $date);

                $receipt->status = WhReceiptStatus::FullyReceived;
                $receipt->save();
            }

            $transactionData = $this->buildReceiptTransactionData([
                'amount' => $totalInDefault,
                'currency_id' => $defaultCurrency->id,
                'cash_id' => $cash_id,
                'client_id' => $client_id,
                'client_balance_id' => $client_balance_id,
                'project_id' => $data['project_id'] ?? null,
                'note' => $note,
                'date' => $date,
            ]);

            $this->createTransactionForSource($transactionData, \App\Models\WhReceipt::class, $receipt->id, true);

            Log::info('wh_receipt_created', [
                'receipt_id' => $receipt->id,
                'is_legacy' => $is_legacy,
                'is_simple' => $is_simple,
                'waybill_id' => $simpleWaybillId,
                'debt_amount_default' => $totalInDefault,
            ]);

            if (! $is_legacy) {
                $this->syncReceiptFulfillmentStatus($receipt->fresh(['products', 'waybills.lines']));
            }

            $this->invalidateCaches($data['project_id'] ?? null);

            return $receipt->id;
        });
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
        $project_id   = $data['project_id'] ?? null;

        app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $warehouse_id);

        $updated = DB::transaction(function () use ($receipt_id, $client_id, $warehouse_id, $cash_id, $date, $note, $products, $project_id, $data) {
            $receipt = WhReceipt::findOrFail($receipt_id);

            if (array_key_exists('status', $data) && $data['status'] !== null && $data['status'] !== '') {
                $receipt->status = $this->resolveReceiptStatusFromInput($data['status']);
                $receipt->saveQuietly();
            }

            $old_total_amount = $receipt->amount;

            $receipt->supplier_id = $client_id;
            $receipt->warehouse_id = $warehouse_id;
            $receipt->project_id = $project_id;
            $receipt->cash_id = $cash_id;
            $receipt->date = $date;
            $receipt->note = $note;
            $receipt->amount = 0;
            $receipt->save();

            $total_amount = 0;
            $existingProducts = WhReceiptProduct::where('receipt_id', $receipt_id)->get();
            $existingProductIds = $existingProducts->pluck('product_id')->toArray();

            foreach ($products as $product) {
                $product_id = $product['product_id'];
                $quantity = (new RoundingService())->roundQuantityForCompany($this->getCurrentCompanyId(), (float) ($product['quantity']));
                $price = $product['price'];

                WhReceiptProduct::updateOrCreate(
                    ['receipt_id' => $receipt->id, 'product_id' => $product_id],
                    ['quantity' => $quantity, 'price' => $price]
                );

                if ($receipt->is_legacy) {
                    $existingProduct = $existingProducts->firstWhere('product_id', $product_id);
                    $quantityDifference = $quantity - ($existingProduct ? $existingProduct->quantity : 0);
                    $this->updateStock($warehouse_id, $product_id, $quantityDifference);
                    $this->updateProductPurchasePrice($product_id, $price);
                }
                $total_amount += $price * $quantity;
            }

            $roundingService = new RoundingService();
            $companyId = $this->getCurrentCompanyId();
            $total_amount = $roundingService->roundForCompany($companyId, (float) $total_amount);

            $receipt->amount = $total_amount;
            $receipt->save();

            $transactions = $receipt->transactions()->get();
            if ($transactions->isEmpty()) {
                $this->updateClientBalance($client_id, $total_amount - $old_total_amount);
            }

            if ($receipt->is_legacy) {
                $deletedProducts = array_diff($existingProductIds, array_column($products, 'product_id'));
                foreach ($deletedProducts as $deletedProductId) {
                    $deletedProduct = $existingProducts->firstWhere('product_id', $deletedProductId);
                    $this->updateStock($warehouse_id, $deletedProductId, -$deletedProduct->quantity);
                    $deletedProduct->delete();
                }
            }

            if (! $receipt->is_legacy) {
                $this->syncReceiptFulfillmentStatus($receipt->fresh(['products', 'waybills.lines']));
            }

            app(ReceiptExpenseAllocationService::class)->syncAllForReceipt((int) $receipt_id);

            $this->invalidateCaches($project_id);

            return true;
        });

        return $updated;
    }

    /**
     * Удалить оприходование
     *
     * @param int $receipt_id ID оприходования
     * @return bool
     */
    public function deleteItem($receipt_id)
    {
        $projectId = null;
        $result = DB::transaction(function () use ($receipt_id, &$projectId) {
            $receipt = WhReceipt::findOrFail($receipt_id);
            app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $receipt->warehouse_id);
            $projectId = $receipt->project_id;

            $this->reverseReceiptStockAndDeleteLines($receipt);

            $receipt->delete();

            return true;
        });
        if ($result) {
            $this->invalidateCaches($projectId);
        }

        return $result;
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
            $receipt->project_id = null;

            $totalAmount = 0.0;
            foreach ($normalized as $product) {
                $totalAmount += $product['price'] * $product['quantity'];
            }
            $receipt->amount = $roundingService->roundForCompany($companyId, $totalAmount);
            $receipt->is_legacy = false;
            $receipt->is_simple = true;
            $receipt->status = WhReceiptStatus::Purchasing;
            $receipt->save();

            foreach ($normalized as $product) {
                $receiptProduct = new WhReceiptProduct();
                $receiptProduct->receipt_id = $receipt->id;
                $receiptProduct->product_id = $product['product_id'];
                $receiptProduct->quantity = $product['quantity'];
                $receiptProduct->price = $product['price'];
                $receiptProduct->save();
            }

            $this->createPrimaryWaybillWithLinesAndPostStock($receipt, $warehouseId, $normalized, $receipt->date);

            $receipt->status = WhReceiptStatus::FullyReceived;
            $receipt->save();

            $this->invalidateCaches(null);

            return $receipt->id;
        });
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float, price: float}>  $lines
     * @return int ID созданной накладной
     */
    private function createPrimaryWaybillWithLinesAndPostStock(
        WhReceipt $receipt,
        int $warehouseId,
        array $lines,
        mixed $waybillDate
    ): int {
        $waybill = new WhWaybill();
        $waybill->receipt_id = $receipt->id;
        $waybill->date = $waybillDate;
        $waybill->number = null;
        $waybill->note = null;
        $waybill->creator_id = (int) auth('api')->id();
        $waybill->save();

        foreach ($lines as $product) {
            $wp = new WhWaybillProduct();
            $wp->waybill_id = $waybill->id;
            $wp->product_id = (int) $product['product_id'];
            $wp->quantity = (string) $product['quantity'];
            $wp->price = (string) $product['price'];
            $wp->save();

            $this->updateStock($warehouseId, (int) $product['product_id'], (float) $product['quantity']);
            $this->updateProductPurchasePrice((int) $product['product_id'], (float) $product['price']);
        }

        return (int) $waybill->id;
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
    private function reverseReceiptStockAndDeleteLines(WhReceipt $receipt): void
    {
        $receiptId = (int) $receipt->id;
        if ($receipt->is_legacy) {
            foreach (WhReceiptProduct::query()->where('receipt_id', $receiptId)->get() as $p) {
                $this->updateStock((int) $receipt->warehouse_id, (int) $p->product_id, -(float) $p->quantity);
                $p->delete();
            }

            return;
        }

        $sums = WhWaybillProduct::query()
            ->whereHas('waybill', fn ($q) => $q->where('receipt_id', $receipt->id))
            ->selectRaw('product_id, sum(quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');
        foreach ($sums as $pid => $qty) {
            $this->updateStock((int) $receipt->warehouse_id, (int) $pid, -(float) $qty);
        }
        WhReceiptProduct::query()->where('receipt_id', $receiptId)->delete();
    }

    public function deleteReceiptWithoutInventoryLock(int $receiptId): void
    {
        $projectId = null;
        DB::transaction(function () use ($receiptId, &$projectId) {
            $receipt = WhReceipt::query()->lockForUpdate()->findOrFail($receiptId);
            $projectId = $receipt->project_id;

            $this->reverseReceiptStockAndDeleteLines($receipt);

            $receipt->delete();
        });

        $this->invalidateCaches($projectId);
    }

    public function invalidateWarehouseReceiptCaches(?int $projectId = null): void
    {
        $this->invalidateCaches($projectId);
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
        if ($receipt->is_legacy) {
            return;
        }

        $receipt->loadMissing(['products', 'waybills.lines']);
        if ($receipt->products->isEmpty()) {
            return;
        }
        $rounding = new RoundingService();
        $companyId = $this->getCurrentCompanyId();

        $waybillTotals = [];
        foreach ($receipt->waybills as $wb) {
            foreach ($wb->lines as $line) {
                $pid = (int) $line->product_id;
                $waybillTotals[$pid] = ($waybillTotals[$pid] ?? 0) + (float) $line->quantity;
            }
        }

        $matched = true;
        foreach ($receipt->products as $rp) {
            $pid = (int) $rp->product_id;
            $expected = $rounding->roundQuantityForCompany($companyId, (float) $rp->quantity);
            $actual = $rounding->roundQuantityForCompany($companyId, (float) ($waybillTotals[$pid] ?? 0));
            if (abs($actual - $expected) > 1e-9) {
                $matched = false;
                break;
            }
        }

        $current = $receipt->status;

        if ($matched) {
            if ($current !== WhReceiptStatus::FullyReceived) {
                $receipt->status = WhReceiptStatus::FullyReceived;
                $receipt->saveQuietly();
            }

            return;
        }

        if ($current === WhReceiptStatus::FullyReceived) {
            $receipt->status = WhReceiptStatus::Purchasing;
            $receipt->saveQuietly();
        }
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
            return WhReceiptStatus::Purchasing;
        }

        return WhReceiptStatus::tryFrom((string) $value) ?? WhReceiptStatus::Purchasing;
    }

    /**
     * Инвалидировать кэш
     *
     * @param int|null $projectId ID проекта
     * @return void
     */
    private function invalidateCaches($projectId = null)
    {
        CacheService::invalidateWarehouseReceiptsCache();
        CacheService::invalidateWarehouseStocksCache();
        CacheService::invalidateProductsCache();
        CacheService::invalidateClientsCache();
        if ($projectId) {
            CacheService::invalidateProjectsCache();
        }
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
     *   - project_id (int|null) ID проекта
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
            'project_id' => $data['project_id'] ?? null,
            'client_id' => $data['client_id'],
            'client_balance_id' => $data['client_balance_id'] ?? null,
            'note' => $data['note'],
            'date' => $data['date'],
            'is_debt' => true,
        ];
    }
}
