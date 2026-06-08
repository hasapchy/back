<?php

namespace App\Repositories;

use App\Enums\WhPurchaseStatus;
use App\Enums\WhReceiptStatus;
use App\Models\ClientBalance;
use App\Models\WhPurchase;
use App\Models\WhPurchaseProduct;
use App\Services\WarehouseDocumentPaymentStatusService;
use App\Services\WarehousePurchaseGoodsPaymentLimitService;
use App\Repositories\Concerns\ResolvesWarehouseLineOrigDisplay;
use App\Services\CacheService;
use App\Services\RoundingService;
use App\Models\Transaction;
use App\Support\TransactionCategoryBindingKeys;
use App\Services\Timeline\WarehouseTimelineCache;
use Illuminate\Support\Facades\DB;

class WarehousePurchaseRepository extends BaseRepository
{
    use ResolvesWarehouseLineOrigDisplay;

    /**
     * @return \Closure(\Illuminate\Database\Eloquent\Relations\MorphMany): void
     */
    protected function purchaseTransactionsWithRelations(): \Closure
    {
        return function ($query): void {
            $query->where('is_deleted', false)
                ->with([
                    'creator:id,name,surname,photo',
                    'category:id,name,type',
                    'cashRegister:id,name,currency_id,is_cash,icon,color',
                    'cashRegister.currency:id,name,code',
                    'currency:id,name,code',
                ]);
        };
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<WhPurchase>
     */
    public function getItemsWithPagination(int $perPage = 20, int $page = 1, ?int $supplierId = null, ?string $status = null, ?string $paymentStatus = null)
    {
        $cacheKey = $this->generateCacheKey('warehouse_purchases_paginated', [$perPage, $page, $supplierId, $status, $paymentStatus]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $page, $supplierId, $status, $paymentStatus) {
            $query = WhPurchase::query()
                ->with([
                    'supplier:id,first_name,last_name,status',
                    'supplier.phones:id,client_id,phone',
                    'supplier.emails:id,client_id,email',
                    'warehouse:id,name',
                    'clientBalance:id,client_id,currency_id,type',
                    'cashRegister:id,name,currency_id,is_cash',
                    'currency:id,code',
                    'origCurrency:id,code',
                    'creator:id,name,surname,photo',
                    'products:id,purchase_id,product_id,quantity,price,orig_unit_price,orig_currency_id,orig_unit_id,orig_quantity',
                    'products.product:id,name,image,unit_id',
                    'products.product.unit:id,name,short_name',
                    'products.origUnit:id,name,short_name',
                    'receipts:id,purchase_id,warehouse_id,supplier_id,amount,status,date,creator_id',
                    'transactions' => $this->purchaseTransactionsWithRelations(),
                ]);

            $query = $this->addCompanyFilterThroughRelation($query, 'supplier');

            if ($supplierId !== null) {
                $query->where('supplier_id', $supplierId);
            }

            $statusEnum = $status ? WhPurchaseStatus::tryFrom($status) : null;
            if ($statusEnum !== null) {
                $query->where('status', $statusEnum->value);
            }

            app(WarehouseDocumentPaymentStatusService::class)->applyPurchasePaymentStatusFilter($query, $paymentStatus);

            $paginator = $query->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', $page);
            app(WarehouseDocumentPaymentStatusService::class)->attachPaymentStatusToPurchases($paginator->getCollection());

            return $paginator;
        }, $page);
    }

    /**
     * @return WhPurchase|null
     */
    public function getItemById(int $id): ?WhPurchase
    {
        $cacheKey = $this->generateCacheKey('warehouse_purchase_item', [$id]);

        return CacheService::getReferenceData($cacheKey, function () use ($id) {
            $query = WhPurchase::query()
                ->with([
                    'supplier:id,first_name,last_name,status',
                    'supplier.phones:id,client_id,phone',
                    'supplier.emails:id,client_id,email',
                    'warehouse:id,name',
                    'clientBalance:id,client_id,currency_id,type',
                    'cashRegister:id,name,currency_id,is_cash',
                    'currency:id,code',
                    'origCurrency:id,code',
                    'creator:id,name,surname,photo',
                    'products:id,purchase_id,product_id,quantity,price,orig_unit_price,orig_currency_id,orig_unit_id,orig_quantity',
                    'products.product:id,name,image,unit_id',
                    'products.product.unit:id,name,short_name',
                    'products.origUnit:id,name,short_name',
                    'receipts:id,purchase_id,warehouse_id,supplier_id,amount,status,date,creator_id',
                    'transactions' => $this->purchaseTransactionsWithRelations(),
                ]);
            $query = $this->addCompanyFilterThroughRelation($query, 'supplier');

            $purchase = $query->find($id);
            if ($purchase) {
                app(WarehouseDocumentPaymentStatusService::class)->attachPaymentStatusToPurchases([$purchase]);
            }

            return $purchase;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createItem(array $data): int
    {
        return DB::transaction(function () use ($data): int {
            $rounding = new RoundingService();
            $companyId = $this->getCurrentCompanyId();
            $products = $this->mergePurchaseProductLines($data['products'] ?? []);
            $defaultCurrency = $this->getDefaultCurrency();
            $purchaseCurrencyId = $this->resolvePurchaseCurrencyId($data, $defaultCurrency->id);
            $purchaseCashId = $this->resolvePurchaseCashId($data);
            $amountOrig = 0.0;

            $linePayloads = [];
            foreach ($products as $idx => $product) {
                $products[$idx]['quantity'] = $rounding->roundQuantityForCompany($companyId, (float) $product['quantity']);
                $lineOrig = $this->resolveWarehouseLineOrigAmount($products[$idx], $purchaseCurrencyId, $data['date'] ?? null);
                $linePayloads[$idx] = array_merge($this->resolveWarehouseLineOrigDisplay($product), $lineOrig);
                $amountOrig += (float) $products[$idx]['quantity'] * (float) $lineOrig['orig_unit_price'];
            }
            $amountOrig = $rounding->roundWarehouseAmountForCompany($companyId, $amountOrig);
            $amountDefault = $purchaseCurrencyId === (int) $defaultCurrency->id
                ? $amountOrig
                : $rounding->roundWarehouseAmountForCompany($companyId, $this->convertCurrency($amountOrig, $purchaseCurrencyId, (int) $defaultCurrency->id));

            $purchase = new WhPurchase();
            $purchase->supplier_id = (int) $data['supplier_id'];
            $purchase->warehouse_id = isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null;
            $purchase->client_balance_id = $data['client_balance_id'] ?? null;
            $purchase->cash_id = $purchaseCashId;
            $purchase->currency_id = $purchaseCurrencyId;
            $purchase->creator_id = (int) auth('api')->id();
            $purchase->status = WhPurchaseStatus::Draft->value;
            $purchase->date = $data['date'] ?? now();
            $purchase->note = $data['note'] ?? null;
            $purchase->amount = $amountDefault;
            $purchase->orig_amount = $amountOrig;
            $purchase->orig_currency_id = $purchaseCurrencyId;
            $purchase->save();

            foreach ($products as $idx => $product) {
                WhPurchaseProduct::query()->create(array_merge([
                    'purchase_id' => $purchase->id,
                    'product_id' => (int) $product['product_id'],
                    'quantity' => (float) $product['quantity'],
                ], $linePayloads[$idx]));
            }

            $this->createTransactionForSource([
                'type' => 0,
                'creator_id' => (int) auth('api')->id(),
                'amount' => $amountDefault,
                'orig_amount' => $amountOrig,
                'skip_amount_rounding' => true,
                'currency_id' => $purchaseCurrencyId,
                'cash_id' => $purchaseCashId,
                'category_id' => $this->requireTransactionCategoryBinding(TransactionCategoryBindingKeys::WAREHOUSE_PURCHASE),
                'client_id' => $purchase->supplier_id,
                'client_balance_id' => $purchase->client_balance_id,
                'date' => $purchase->date,
                'note' => $purchase->note,
                'is_debt' => true,
            ], WhPurchase::class, (int) $purchase->id, true);

            $this->invalidateCaches();
            WarehouseTimelineCache::forgetPurchase((int) $purchase->id, (int) $purchase->supplier_id);

            return (int) $purchase->id;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateItem(int $id, array $data): bool
    {
        return DB::transaction(function () use ($id, $data): bool {
            $purchase = WhPurchase::query()->lockForUpdate()->findOrFail($id);

            if (isset($data['status']) && WhPurchaseStatus::tryFrom((string) $data['status']) === WhPurchaseStatus::Completed) {
                throw new \RuntimeException((string) __('warehouse_purchase.completion_is_automatic'));
            }

            if ($purchase->status !== WhPurchaseStatus::Draft) {
                throw new \RuntimeException((string) __('warehouse_purchase.edit_only_draft'));
            }

            $purchase->supplier_id = (int) ($data['supplier_id'] ?? $purchase->supplier_id);
            $purchase->warehouse_id = isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : $purchase->warehouse_id;
            $purchase->client_balance_id = $data['client_balance_id'] ?? $purchase->client_balance_id;
            $purchase->cash_id = isset($data['cash_id']) ? (int) $data['cash_id'] : $purchase->cash_id;
            if (! $purchase->cash_id) {
                throw new \RuntimeException(__('api.warehouse_purchase.cash_register_required'));
            }
            $purchase->currency_id = $this->resolvePurchaseCurrencyId($data, (int) ($purchase->currency_id ?? $this->getDefaultCurrency()->id), $purchase->client_balance_id);
            $purchase->date = $data['date'] ?? $purchase->date;
            $purchase->note = $data['note'] ?? $purchase->note;
            $status = isset($data['status']) ? WhPurchaseStatus::tryFrom((string) $data['status']) : null;
            if ($status !== null) {
                $purchase->status = $status->value;
            }

            $rounding = new RoundingService();
            $companyId = $this->getCurrentCompanyId();
            $defaultCurrency = $this->getDefaultCurrency();
            $purchaseCurrencyId = (int) ($purchase->currency_id ?? $defaultCurrency->id);
            $amountOrig = 0.0;
            if (isset($data['products']) && is_array($data['products'])) {
                $products = $this->mergePurchaseProductLines($data['products']);
                WhPurchaseProduct::query()->where('purchase_id', $purchase->id)->delete();
                foreach ($products as $product) {
                    $quantity = $rounding->roundQuantityForCompany($companyId, (float) $product['quantity']);
                    $lineOrig = $this->resolveWarehouseLineOrigAmount($product, $purchaseCurrencyId, $purchase->date);
                    $amountOrig += $quantity * (float) $lineOrig['orig_unit_price'];
                    WhPurchaseProduct::query()->create(array_merge([
                        'purchase_id' => $purchase->id,
                        'product_id' => (int) $product['product_id'],
                        'quantity' => $quantity,
                    ], $this->resolveWarehouseLineOrigDisplay($product), $lineOrig));
                }
            } else {
                $amountOrig = (float) WhPurchaseProduct::query()
                    ->where('purchase_id', $purchase->id)
                    ->selectRaw('COALESCE(SUM(quantity * COALESCE(orig_unit_price, price)), 0) as amount_orig')
                    ->value('amount_orig');
            }
            $amountOrig = $rounding->roundWarehouseAmountForCompany($companyId, $amountOrig);
            $purchase->amount = $purchaseCurrencyId === (int) $defaultCurrency->id
                ? $amountOrig
                : $rounding->roundWarehouseAmountForCompany($companyId, $this->convertCurrency($amountOrig, $purchaseCurrencyId, (int) $defaultCurrency->id));
            $purchase->orig_amount = $amountOrig;
            $purchase->orig_currency_id = $purchaseCurrencyId;

            $purchase->save();
            $this->syncPurchaseDebtTransaction($purchase, $amountOrig, (float) $purchase->amount, $purchaseCurrencyId);
            $this->invalidateCaches();
            WarehouseTimelineCache::forgetPurchase($id, (int) $purchase->supplier_id);

            return true;
        });
    }

    /**
     * @param  float  $amountOrig  Сумма в валюте документа
     * @param  float  $amountDefault  Сумма в дефолтной валюте
     */
    private function syncPurchaseDebtTransaction(WhPurchase $purchase, float $amountOrig, float $amountDefault, int $purchaseCurrencyId): void
    {
        $debtTx = $purchase->transactions()
            ->where('is_debt', true)
            ->where('is_deleted', false)
            ->orderBy('id')
            ->first();

        $txData = [
            'type' => 0,
            'creator_id' => (int) auth('api')->id(),
            'amount' => $amountDefault,
            'orig_amount' => $amountOrig,
            'skip_amount_rounding' => true,
            'currency_id' => $purchaseCurrencyId,
            'cash_id' => (int) $purchase->cash_id,
            'category_id' => $this->requireTransactionCategoryBinding(TransactionCategoryBindingKeys::WAREHOUSE_PURCHASE),
            'client_id' => (int) $purchase->supplier_id,
            'client_balance_id' => $purchase->client_balance_id,
            'date' => $purchase->date,
            'note' => $purchase->note,
            'is_debt' => true,
            'source_type' => WhPurchase::class,
            'source_id' => (int) $purchase->id,
        ];

        if ($debtTx) {
            app(TransactionsRepository::class)->updateItem((int) $debtTx->id, $txData);
        } else {
            $this->createTransactionForSource($txData, WhPurchase::class, (int) $purchase->id, true);
        }
    }

    /**
     * Удалить закупку в статусе «Черновик» и связанные с ней транзакции.
     *
     * @param  int  $id  ID закупки
     * @return bool
     */
    public function deleteItem(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $purchase = WhPurchase::query()->lockForUpdate()->findOrFail($id);
            if ($purchase->status !== WhPurchaseStatus::Draft) {
                throw new \RuntimeException((string) __('warehouse_purchase.delete_only_draft'));
            }
            if ($purchase->receipts()->exists()) {
                throw new \RuntimeException((string) __('warehouse_purchase.delete_forbidden_has_receipts'));
            }

            $supplierId = (int) $purchase->supplier_id;
            $transactions = $purchase->transactions()->where('is_deleted', false)->get();
            app(TransactionsRepository::class)->deleteLinkedTransactions($transactions);
            $purchase->delete();
            $this->invalidateCaches();
            WarehouseTimelineCache::forgetPurchase($id, $supplierId);

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addPayment(int $id, array $data): int
    {
        return DB::transaction(function () use ($id, $data): int {
            $purchase = WhPurchase::query()->lockForUpdate()->findOrFail($id);
            $amount = (float) $data['amount'];
            $defaultCurrency = $this->getDefaultCurrency();
            $paymentCurrencyId = (int) ($data['currency_id'] ?? $purchase->currency_id ?? $defaultCurrency->id);
            $incomingAmountDefault = $paymentCurrencyId === (int) $defaultCurrency->id
                ? $amount
                : app(RoundingService::class)->roundWarehouseAmountForCompany(
                    $this->getCurrentCompanyId(),
                    $this->convertCurrency($amount, $paymentCurrencyId, (int) $defaultCurrency->id)
                );
            $remaining = app(WarehousePurchaseGoodsPaymentLimitService::class)->remainingDefault($purchase, null);
            if ($incomingAmountDefault > $remaining + 1e-9) {
                throw new \RuntimeException((string) __('warehouse_purchase.goods_payment_exceeds_remaining'));
            }

            $txId = $this->createTransactionForSource([
                'type' => 0,
                'creator_id' => (int) auth('api')->id(),
                'amount' => $amount,
                'orig_amount' => $amount,
                'currency_id' => $paymentCurrencyId,
                'cash_id' => (int) $data['cash_id'],
                'category_id' => $this->requireTransactionCategoryBinding(TransactionCategoryBindingKeys::WAREHOUSE_PURCHASE),
                'client_id' => $purchase->supplier_id,
                'client_balance_id' => $purchase->client_balance_id,
                'date' => $data['date'] ?? now(),
                'note' => $data['note'] ?? null,
                'is_debt' => false,
            ], WhPurchase::class, (int) $purchase->id, true);

            $this->invalidateCaches();
            WarehouseTimelineCache::forgetPurchase($id, (int) $purchase->supplier_id);
            $this->syncPurchaseCompletionState($id);

            return (int) $txId;
        });
    }

    /**
     * Синхронизирует статус закупки после изменений оприходований или оплат.
     */
    public function syncPurchaseCompletionState(int $purchaseId): void
    {
        $this->tryRevertAutoCompleteFromReceipts($purchaseId);
        $this->tryAutoCompleteFromReceipts($purchaseId);
    }

    /**
     * Проверяет, оплачена ли закупка по товару (полная оплата в базовой валюте).
     */
    public function purchaseIsFullyPaid(WhPurchase $purchase): bool
    {
        $payment = app(WarehouseDocumentPaymentStatusService::class)->enrichPurchase($purchase);

        return ($payment['payment_status'] ?? '') === 'paid';
    }

    /**
     * Проверяет, выполнены ли условия автозакрытия закупки по оприходованиям.
     */
    public function purchaseFulfillsAutoCompletion(WhPurchase $purchase): bool
    {
        if (! $purchase->relationLoaded('products')) {
            $purchase->load('products');
        }

        if ($purchase->products->isEmpty()) {
            return false;
        }

        if (! $purchase->receipts()->exists()) {
            return false;
        }

        if ($purchase->receipts()->where('status', '!=', WhReceiptStatus::Completed->value)->exists()) {
            return false;
        }

        $remaining = app(WarehouseReceiptRepository::class)->remainingReceiptQuantityByProduct($purchase, null);
        foreach ($remaining as $left) {
            if ((float) $left > 1e-9) {
                return false;
            }
        }

        if (! $this->purchaseIsFullyPaid($purchase)) {
            return false;
        }

        return true;
    }

    /**
     * Переводит закупку в «Завершено», если она подтверждена и все оприходования закрыты.
     */
    public function tryAutoCompleteFromReceipts(int $purchaseId): void
    {
        $purchase = WhPurchase::query()->lockForUpdate()->find($purchaseId);
        if (! $purchase instanceof WhPurchase || $purchase->status !== WhPurchaseStatus::Approved) {
            return;
        }

        if (! $this->purchaseFulfillsAutoCompletion($purchase)) {
            return;
        }

        $purchase->status = WhPurchaseStatus::Completed->value;
        $purchase->save();
        $this->invalidateCaches();
        WarehouseTimelineCache::forgetPurchase($purchaseId, (int) $purchase->supplier_id);
    }

    /**
     * Возвращает закупку в «Подтверждено», если автозакрытие больше не выполняется.
     */
    public function tryRevertAutoCompleteFromReceipts(int $purchaseId): void
    {
        $purchase = WhPurchase::query()->lockForUpdate()->find($purchaseId);
        if (! $purchase instanceof WhPurchase || $purchase->status !== WhPurchaseStatus::Completed) {
            return;
        }

        if ($this->purchaseFulfillsAutoCompletion($purchase)) {
            return;
        }

        $purchase->status = WhPurchaseStatus::Approved->value;
        $purchase->save();
        $this->invalidateCaches();
        WarehouseTimelineCache::forgetPurchase($purchaseId, (int) $purchase->supplier_id);
    }

    private function invalidateCaches(): void
    {
        CacheService::invalidateWarehousePurchasesCache();
        CacheService::invalidateWarehouseReceiptsCache();
        CacheService::invalidateTransactionsCache();
        CacheService::invalidateClientsCache();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolvePurchaseCashId(array $data): int
    {
        if (empty($data['cash_id'])) {
            throw new \RuntimeException(__('api.warehouse_purchase.cash_register_required'));
        }

        return (int) $data['cash_id'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolvePurchaseCurrencyId(array $data, int $fallbackCurrencyId, ?int $currentClientBalanceId = null): int
    {
        if (! empty($data['currency_id'])) {
            return (int) $data['currency_id'];
        }

        $clientBalanceId = isset($data['client_balance_id'])
            ? (int) $data['client_balance_id']
            : $currentClientBalanceId;

        if ($clientBalanceId) {
            $balanceCurrencyId = (int) (ClientBalance::query()->where('id', $clientBalanceId)->value('currency_id') ?? 0);
            if ($balanceCurrencyId > 0) {
                return $balanceCurrencyId;
            }
        }

        return $fallbackCurrencyId;
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, array<string, mixed>>
     */
    private function mergePurchaseProductLines(array $products): array
    {
        $companyId = $this->getCurrentCompanyId();
        $rounding = new RoundingService();
        $merged = [];

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $productId = (int) ($product['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            if (! isset($merged[$productId])) {
                $product['quantity'] = $rounding->roundQuantityForCompany($companyId, (float) ($product['quantity'] ?? 0));
                $merged[$productId] = $product;

                continue;
            }

            $existingPrice = (float) ($merged[$productId]['price'] ?? 0);
            $incomingPrice = (float) ($product['price'] ?? 0);
            if (abs($existingPrice - $incomingPrice) > 1e-9) {
                throw new \RuntimeException((string) __('warehouse_purchase.duplicate_product_lines_price_mismatch'));
            }

            $merged[$productId]['quantity'] = $rounding->roundQuantityForCompany(
                $companyId,
                (float) ($merged[$productId]['quantity'] ?? 0) + (float) ($product['quantity'] ?? 0)
            );
        }

        return array_values($merged);
    }

}
