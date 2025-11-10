<?php

namespace App\Repositories;

use App\Models\Client;
use App\Models\Currency;
use App\Models\ProductPrice;
use App\Models\WarehouseStock;
use App\Models\WhReceipt;
use App\Models\WhReceiptProduct;
use App\Models\CashRegister;
use App\Services\CurrencyConverter;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;
use App\Services\RoundingService;

class WarehouseReceiptRepository extends BaseRepository
{

    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        $cacheKey = $this->generateCacheKey('warehouse_receipts_paginated', [$userUuid, $perPage]);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $page) {
            return $this->buildBaseQuery($userUuid)
                ->orderBy('wh_receipts.created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
    }

    public function getItemById($id, $userUuid)
    {
        $cacheKey = $this->generateCacheKey('warehouse_receipts_item', [$id, $userUuid]);

        return CacheService::getReferenceData($cacheKey, function () use ($id, $userUuid) {
            return $this->buildBaseQuery($userUuid)
                ->where('wh_receipts.id', $id)
                ->first();
        });
    }

    protected function buildBaseQuery($userUuid)
    {
        $warehouseIds = DB::table('wh_users')
            ->where('user_id', $userUuid)
            ->pluck('warehouse_id')
            ->toArray();

        $query = WhReceipt::select([
            'wh_receipts.id',
            'wh_receipts.warehouse_id',
            'wh_receipts.supplier_id',
            'wh_receipts.amount',
            'wh_receipts.cash_id',
            'wh_receipts.project_id',
            'wh_receipts.note',
            'wh_receipts.user_id',
            'wh_receipts.date',
            'wh_receipts.created_at',
            'wh_receipts.updated_at',
            'clients.first_name as client_first_name',
            'clients.last_name as client_last_name',
            'clients.contact_person as client_contact_person'
        ])
            ->leftJoin('clients', 'wh_receipts.supplier_id', '=', 'clients.id')
            ->with([
                'warehouse:id,name',
                'cashRegister:id,name,currency_id',
                'cashRegister.currency:id,name,code,symbol',
                'user:id,name',
                'project:id,name',
                'supplier:id,first_name,last_name,contact_person,status,balance',
                'supplier.phones:id,client_id,phone',
                'supplier.emails:id,client_id,email',
                'products:id,receipt_id,product_id,quantity,price',
                'products.product:id,name,image,unit_id',
                'products.product.unit:id,name,short_name'
            ])
            ->whereIn('wh_receipts.warehouse_id', $warehouseIds)
            ->where(function ($q) use ($userUuid) {
                $q->whereNull('wh_receipts.project_id')
                    ->orWhereHas('project.projectUsers', function ($subQuery) use ($userUuid) {
                        $subQuery->where('user_id', $userUuid);
                    });
            });

        return $this->addCompanyFilterThroughRelation($query, 'warehouse');
    }


    public function createItem(array $data)
    {
        $client_id    = $data['client_id'];
        $warehouse_id = $data['warehouse_id'];
        $type         = $data['type'];
        $cash_id      = $data['cash_id'] ?? null;
        $date         = $data['date'] ?? now();
        $note         = !empty($data['note']) ? $data['note'] : null;
        $products     = $data['products'];

        DB::beginTransaction();

        try {
            $defaultCurrency = Currency::firstWhere('is_default', true);
            $currency = $defaultCurrency;

            if ($cash_id) {
                $cash = CashRegister::find($cash_id);
                if ($cash && $cash->currency_id) {
                    $currency = Currency::find($cash->currency_id) ?? $defaultCurrency;
                }
            }

            $total_amount = 0;
            $quantityRoundingService = new RoundingService();
            $companyId = $this->getCurrentCompanyId();
            foreach ($products as $idx => $product) {
                $q = $quantityRoundingService->roundQuantityForCompany($companyId, (float) ($product['quantity']));
                $products[$idx]['quantity'] = $q;
                $total_amount += $product['price'] * $q;
            }

            $roundingService = new RoundingService();
            $companyId = $this->getCurrentCompanyId();
            $total_amount = $roundingService->roundForCompany($companyId, (float) $total_amount);

            $receipt = new WhReceipt();
            $receipt->supplier_id  = $client_id;
            $receipt->warehouse_id = $warehouse_id;
            $receipt->project_id   = $data['project_id'] ?? null;
            $receipt->cash_id      = $cash_id;
            $receipt->date         = $date;
            $receipt->note         = $note;
            $receipt->amount       = $total_amount;
            $receipt->user_id      = auth('api')->id();
            $receipt->save();

            foreach ($products as $product) {
                $receiptProduct = new WhReceiptProduct();
                $receiptProduct->receipt_id = $receipt->id;
                $receiptProduct->product_id = $product['product_id'];
                $receiptProduct->quantity   = $product['quantity'];
                $receiptProduct->price      = $product['price'];
                $receiptProduct->save();

                if (!$this->updateStock($warehouse_id, $product['product_id'], $product['quantity'])) {
                    throw new \Exception('Ошибка обновления стоков');
                }
                if (!$this->updateProductPurchasePrice($product['product_id'], $product['price'])) {
                    throw new \Exception('Ошибка обновления цены покупки продукта');
                }
            }

            $transactionData = [
                'type'        => 0,
                'user_id'     => auth('api')->id(),
                'amount'      => $total_amount,
                'orig_amount' => $total_amount,
                'currency_id' => $currency->id,
                'cash_id'     => $cash_id,
                'category_id' => 6,
                'project_id'  => $data['project_id'] ?? null,
                'client_id'   => $client_id,
                'note'        => $note,
                'date'        => $date,
                'is_debt'     => true,
            ];

            if ($type === 'balance') {
                $this->createTransactionForSource($transactionData, \App\Models\WhReceipt::class, $receipt->id, true);
            } else {
                $this->createTransactionForSource($transactionData, \App\Models\WhReceipt::class, $receipt->id, true);

                $paymentTxData = $transactionData;
                $paymentTxData['is_debt'] = false;
                $this->createTransactionForSource($paymentTxData, \App\Models\WhReceipt::class, $receipt->id, true);
            }

            DB::commit();
            $this->invalidateCaches($data['project_id'] ?? null);
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }


    public function updateReceipt($receipt_id, $data)
    {
        $client_id    = $data['client_id'];
        $warehouse_id = $data['warehouse_id'];
        $cash_id      = $data['cash_id'] ?? null;
        $date         = $data['date'];
        $note         = !empty($data['note']) ? $data['note'] : null;
        $products     = $data['products'];
        $project_id   = $data['project_id'] ?? null;

        DB::beginTransaction();

        try {
            $receipt = WhReceipt::find($receipt_id);
            if (!$receipt) {
                throw new \Exception('Receipt not found');
            }

            $defaultCurrency = Currency::firstWhere('is_default', true);
            $currency = $defaultCurrency;

            if ($cash_id) {
                $cash = \App\Models\CashRegister::find($cash_id);
                if ($cash && $cash->currency_id) {
                    $currency = Currency::find($cash->currency_id) ?? $defaultCurrency;
                }
            }

            $old_total_amount = $receipt->amount;

            $receipt->supplier_id  = $client_id;
            $receipt->warehouse_id = $warehouse_id;
            $receipt->project_id   = $project_id;
            $receipt->cash_id      = $cash_id;
            $receipt->date         = $date;
            $receipt->note         = $note;
            $receipt->amount       = 0;
            $receipt->save();

            $total_amount = 0;
            $existingProducts = WhReceiptProduct::where('receipt_id', $receipt_id)->get();
            $existingProductIds = $existingProducts->pluck('product_id')->toArray();

            foreach ($products as $product) {
                $product_id = $product['product_id'];
                $quantity = (new RoundingService())->roundQuantityForCompany($this->getCurrentCompanyId(), (float) ($product['quantity']));
                $price = $product['price'];

                $receiptProduct = WhReceiptProduct::updateOrCreate(
                    ['receipt_id' => $receipt->id, 'product_id' => $product_id],
                    ['quantity' => $quantity, 'price' => $price]
                );

                $existingProduct = $existingProducts->firstWhere('product_id', $product_id);
                $quantityDifference = $quantity - ($existingProduct ? $existingProduct->quantity : 0);
                if (!$this->updateStock($warehouse_id, $product_id, $quantityDifference)) {
                    throw new \Exception('Ошибка обновления стоков');
                }
                if (!$this->updateProductPurchasePrice($product_id, $price)) {
                    throw new \Exception('Ошибка обновления цены покупки продукта');
                }
                $total_amount += $price * $quantity;
            }

            $roundingService = new RoundingService();
            $companyId = $this->getCurrentCompanyId();
            $total_amount = $roundingService->roundForCompany($companyId, (float) $total_amount);

            $receipt->amount = $total_amount;
            $receipt->save();

            if ($receipt->transaction_id) {
            } else {
                if (!$this->updateClientBalance($client_id, $total_amount - $old_total_amount)) {
                    throw new \Exception('Ошибка обновления баланса клиента');
                }
            }

            $deletedProducts = array_diff($existingProductIds, array_column($products, 'product_id'));
            foreach ($deletedProducts as $deletedProductId) {
                $deletedProduct = $existingProducts->firstWhere('product_id', $deletedProductId);
                $this->updateStock($warehouse_id, $deletedProductId, -$deletedProduct->quantity);
                $deletedProduct->delete();
            }

            DB::commit();
            $this->invalidateCaches($project_id);
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }

        return true;
    }


    public function deleteItem($receipt_id)
    {
        $projectId = null;
        $result = DB::transaction(function () use ($receipt_id, &$projectId) {
            $receipt = WhReceipt::findOrFail($receipt_id);
            $projectId = $receipt->project_id;

            foreach (WhReceiptProduct::where('receipt_id', $receipt_id)->get() as $p) {
                $this->updateStock($receipt->warehouse_id, $p->product_id, -$p->quantity);
                $p->delete();
            }

            $clientId = $receipt->supplier_id;
            $receipt->delete();

            return true;
        });
        if ($result) {
            $this->invalidateCaches($projectId);
        }
        return $result;
    }

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

    private function updateStock($warehouse_id, $product_id, $add_quantity)
    {
        $quantity = is_numeric($add_quantity) ? $add_quantity : (float)$add_quantity;

        $stock = WarehouseStock::firstOrNew([
            'warehouse_id' => $warehouse_id,
            'product_id'   => $product_id,
        ]);

        if ($stock->exists) {
            $stock->increment('quantity', $quantity);
        } else {
            $stock->quantity = $quantity;
            $stock->save();
        }

        return true;
    }

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

    private function updateClientBalance($client_id, $amount)
    {
        DB::table('clients')->where('id', $client_id)->update([
            'balance' => DB::raw('balance - ' . $amount)
        ]);
        return true;
    }
}
