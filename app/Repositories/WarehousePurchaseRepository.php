<?php

namespace App\Repositories;

use App\Enums\WhPurchaseStatus;
use App\Models\ClientBalance;
use App\Models\WhPurchase;
use App\Models\WhPurchaseProduct;
use App\Services\CacheService;
use App\Services\RoundingService;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class WarehousePurchaseRepository extends BaseRepository
{
    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<WhPurchase>
     */
    public function getItemsWithPagination(int $perPage = 20, int $page = 1, ?int $supplierId = null, ?string $status = null)
    {
        $cacheKey = $this->generateCacheKey('warehouse_purchases_paginated', [$perPage, $page, $supplierId, $status]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $page, $supplierId, $status) {
            $query = WhPurchase::query()
                ->with([
                    'supplier:id,first_name,last_name,status',
                    'supplier.phones:id,client_id,phone',
                    'supplier.emails:id,client_id,email',
                    'warehouse:id,name',
                    'clientBalance:id,client_id,currency_id,type',
                    'cashRegister:id,name,currency_id,is_cash',
                    'currency:id,symbol',
                    'creator:id,name',
                    'products:id,purchase_id,product_id,quantity,price',
                    'products.product:id,name,image,unit_id',
                    'products.product.unit:id,name,short_name',
                    'receipts:id,purchase_id,warehouse_id,supplier_id,amount,status,date,creator_id',
                    'transactions:id,source_type,source_id,type,is_debt,category_id,cash_id,currency_id,orig_amount,amount,def_amount,date,note,client_id,creator_id,is_deleted',
                ]);

            $query = $this->addCompanyFilterThroughRelation($query, 'supplier');

            if ($supplierId !== null) {
                $query->where('supplier_id', $supplierId);
            }

            $statusEnum = $status ? WhPurchaseStatus::tryFrom($status) : null;
            if ($statusEnum !== null) {
                $query->where('status', $statusEnum->value);
            }

            return $query->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', $page);
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
                    'currency:id,symbol',
                    'creator:id,name',
                    'products:id,purchase_id,product_id,quantity,price',
                    'products.product:id,name,image,unit_id',
                    'products.product.unit:id,name,short_name',
                    'receipts:id,purchase_id,warehouse_id,supplier_id,amount,status,date,creator_id',
                    'transactions:id,source_type,source_id,type,is_debt,category_id,cash_id,currency_id,orig_amount,amount,def_amount,date,note,client_id,creator_id,is_deleted',
                ]);
            $query = $this->addCompanyFilterThroughRelation($query, 'supplier');

            return $query->find($id);
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
            $products = $data['products'] ?? [];
            $defaultCurrency = $this->getDefaultCurrency();
            $purchaseCurrencyId = $this->resolvePurchaseCurrencyId($data, $defaultCurrency->id);
            $purchaseCashId = $this->resolvePurchaseCashId($data);
            $amountOrig = 0.0;

            foreach ($products as $idx => $product) {
                $products[$idx]['quantity'] = $rounding->roundQuantityForCompany($companyId, (float) $product['quantity']);
                $amountOrig += (float) $products[$idx]['quantity'] * (float) $product['price'];
            }
            $amountOrig = $rounding->roundForCompany($companyId, $amountOrig);
            $amountDefault = $purchaseCurrencyId === (int) $defaultCurrency->id
                ? $amountOrig
                : $this->convertAndRoundCurrency($amountOrig, $purchaseCurrencyId, (int) $defaultCurrency->id);

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
            $purchase->save();

            foreach ($products as $product) {
                WhPurchaseProduct::query()->create([
                    'purchase_id' => $purchase->id,
                    'product_id' => (int) $product['product_id'],
                    'quantity' => (float) $product['quantity'],
                    'price' => (float) $product['price'],
                ]);
            }

            $this->createTransactionForSource([
                'type' => 0,
                'creator_id' => (int) auth('api')->id(),
                'amount' => $amountDefault,
                'orig_amount' => $amountOrig,
                'currency_id' => $purchaseCurrencyId,
                'cash_id' => $purchaseCashId,
                'category_id' => 6,
                'client_id' => $purchase->supplier_id,
                'client_balance_id' => $purchase->client_balance_id,
                'date' => $purchase->date,
                'note' => $purchase->note,
                'is_debt' => true,
            ], \App\Models\WhPurchase::class, (int) $purchase->id, true);

            $this->invalidateCaches();

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
            if ($purchase->status !== WhPurchaseStatus::Draft) {
                throw new \RuntimeException((string) __('warehouse_purchase.edit_only_draft'));
            }

            $purchase->supplier_id = (int) ($data['supplier_id'] ?? $purchase->supplier_id);
            $purchase->warehouse_id = isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : $purchase->warehouse_id;
            $purchase->client_balance_id = $data['client_balance_id'] ?? $purchase->client_balance_id;
            $purchase->cash_id = isset($data['cash_id']) ? (int) $data['cash_id'] : $purchase->cash_id;
            if (! $purchase->cash_id) {
                throw new \RuntimeException('Не выбрана касса для закупки');
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
            $amountOrig = 0.0;
            if (isset($data['products']) && is_array($data['products'])) {
                WhPurchaseProduct::query()->where('purchase_id', $purchase->id)->delete();
                foreach ($data['products'] as $product) {
                    $quantity = $rounding->roundQuantityForCompany($companyId, (float) $product['quantity']);
                    $amountOrig += $quantity * (float) $product['price'];
                    WhPurchaseProduct::query()->create([
                        'purchase_id' => $purchase->id,
                        'product_id' => (int) $product['product_id'],
                        'quantity' => $quantity,
                        'price' => (float) $product['price'],
                    ]);
                }
            } else {
                $amountOrig = (float) WhPurchaseProduct::query()
                    ->where('purchase_id', $purchase->id)
                    ->selectRaw('COALESCE(SUM(quantity * price), 0) as amount_orig')
                    ->value('amount_orig');
            }
            $amountOrig = $rounding->roundForCompany($companyId, $amountOrig);
            $defaultCurrency = $this->getDefaultCurrency();
            $purchaseCurrencyId = (int) ($purchase->currency_id ?? $defaultCurrency->id);
            $purchase->amount = $purchaseCurrencyId === (int) $defaultCurrency->id
                ? $amountOrig
                : $this->convertAndRoundCurrency($amountOrig, $purchaseCurrencyId, (int) $defaultCurrency->id);

            $purchase->save();
            $this->invalidateCaches();

            return true;
        });
    }

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

            $purchase->delete();
            $this->invalidateCaches();

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
            $paidTotalDefault = (float) Transaction::query()
                ->where('source_type', \App\Models\WhPurchase::class)
                ->where('source_id', $purchase->id)
                ->where('is_debt', false)
                ->sum('def_amount');
            $incomingAmountDefault = $paymentCurrencyId === (int) $defaultCurrency->id
                ? $amount
                : $this->convertAndRoundCurrency($amount, $paymentCurrencyId, (int) $defaultCurrency->id);
            $debtTotalDefault = max(0.0, (float) $purchase->amount - $paidTotalDefault);
            if ($incomingAmountDefault > $debtTotalDefault + 1e-9) {
                throw new \RuntimeException('Сумма оплаты не может превышать долг по закупке');
            }

            $txId = $this->createTransactionForSource([
                'type' => 0,
                'creator_id' => (int) auth('api')->id(),
                'amount' => $amount,
                'orig_amount' => $amount,
                'currency_id' => $paymentCurrencyId,
                'cash_id' => (int) $data['cash_id'],
                'category_id' => 6,
                'client_id' => $purchase->supplier_id,
                'client_balance_id' => $purchase->client_balance_id,
                'date' => $data['date'] ?? now(),
                'note' => $data['note'] ?? null,
                'is_debt' => false,
            ], \App\Models\WhPurchase::class, (int) $purchase->id, true);

            $this->invalidateCaches();

            return (int) $txId;
        });
    }

    private function invalidateCaches(): void
    {
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
            throw new \RuntimeException('Не выбрана касса для закупки');
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
}
