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
use Illuminate\Support\Facades\Log;

class WarehouseReceiptRepository
{
    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        $companyId = request()->header('X-Company-ID');
        $cacheKey = "warehouse_receipts_paginated_{$userUuid}_{$perPage}_{$companyId}";

        return CacheService::getPaginatedData($cacheKey, function() use ($userUuid, $perPage, $page) {
            $warehouseIds = DB::table('wh_users')
                ->where('user_id', $userUuid)
                ->pluck('warehouse_id')
                ->toArray();

            $items = WhReceipt::select([
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
                    'supplier:id,first_name,last_name,contact_person,status', // Клиент-поставщик
                    'supplier.phones:id,client_id,phone',
                    'supplier.emails:id,client_id,email',
                    'supplier.balance', // Баланс клиента из колонки clients.balance
                    'products:id,receipt_id,product_id,quantity,price', // Товары приходования
                    'products.product:id,name,image,unit_id', // Данные товара
                    'products.product.unit:id,name,short_name' // Единица измерения
                ])
                ->whereIn('wh_receipts.warehouse_id', $warehouseIds)
                // Фильтрация по доступу к проектам
                ->where(function($q) use ($userUuid) {
                    $q->whereNull('wh_receipts.project_id') // Оприходования без проекта
                      ->orWhereHas('project.projectUsers', function($subQuery) use ($userUuid) {
                          $subQuery->where('user_id', $userUuid);
                      });
                })
                ->orderBy('wh_receipts.created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int)$page);

            return $items;
        }, (int)$page);
    }


    public function createItem(array $data)
    {
        $client_id    = $data['client_id'];
        $warehouse_id = $data['warehouse_id'];
        $type         = $data['type'];
        $cash_id      = $data['cash_id'] ?? null;
        $date         = $data['date'] ?? now();
        $note         = $data['note'] ?? '';
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
            foreach ($products as $product) {
                $total_amount += $product['price'] * $product['quantity'];
            }

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

            // Создаем транзакцию для всех типов оприходований (новая архитектура)
            $txRepo = new TransactionsRepository();

            if ($type === 'balance') {
                // Оприходование в долг - обновляем баланс клиента и создаем долговую транзакцию
                DB::table('clients')->where('id', $client_id)->update([
                    'balance' => DB::raw("balance - {$total_amount}")
                ]);

                $transaction_id = $txRepo->createItem([
                    'type'        => 0, // Расход (мы должны поставщику)
                    'user_id'     => auth('api')->id(),
                    'orig_amount' => $total_amount,
                    'currency_id' => $currency->id,
                    'cash_id'     => $cash_id, // Касса указана, но не меняется
                    'category_id' => 6,
                    'project_id'  => $data['project_id'] ?? null,
                    'client_id'   => $client_id,
                    'note'        => $note,
                    'date'        => $date,
                    'is_debt'     => true, // Долговая операция
                    'source_type' => \App\Models\WhReceipt::class,
                    'source_id'   => $receipt->id,
                ], false, true); // Не обновляем баланс кассы, обновляем баланс клиента
            } else {
                // Оприходование за наличные - обычная транзакция
                $transaction_id = $txRepo->createItem([
                    'type'        => 0, // Расход (мы платим поставщику)
                    'user_id'     => auth('api')->id(),
                    'orig_amount' => $total_amount,
                    'currency_id' => $currency->id,
                    'cash_id'     => $cash_id,
                    'category_id' => 6,
                    'project_id'  => $data['project_id'] ?? null,
                    'client_id'   => $client_id,
                    'note'        => $note,
                    'date'        => $date,
                    'is_debt'     => false, // Обычная операция
                    'source_type' => \App\Models\WhReceipt::class,
                    'source_id'   => $receipt->id,
                ], true, true); // Обновляем баланс кассы и клиента
            }

            DB::commit();
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
        $note         = $data['note'];
        $products     = $data['products'];
        $project_id   = $data['project_id'] ?? null;

        DB::beginTransaction();

        try {
            $receipt = WhReceipt::find($receipt_id);
            if (!$receipt) {
                throw new \Exception('Receipt not found');
            }

            // Получаем валюту из кассы (если есть), иначе дефолт
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
                $quantity = $product['quantity'];
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

            $receipt->amount = $total_amount;
            $receipt->save();

            // Обновляем баланс клиента, если это тип "balance"
            if ($receipt->transaction_id) {
                // ничего не делаем — был расход через транзакцию
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
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }

        return true;
    }


    public function deleteItem($receipt_id)
    {
        DB::beginTransaction();
        try {
            $receipt = WhReceipt::findOrFail($receipt_id);

            // 1) Откатываем стоки
            foreach (WhReceiptProduct::where('receipt_id', $receipt_id)->get() as $p) {
                $this->updateStock($receipt->warehouse_id, $p->product_id, -$p->quantity);
                $p->delete();
            }

            // 2) Удаляем транзакцию — пропускаем client-balance корректировку
            if ($receipt->transaction_id) {
                $txRepo = new TransactionsRepository();
                $txRepo->deleteItem($receipt->transaction_id, true);
            }

            // 3) Если это было зачисление на баланс, откатываем баланс клиента
            if (! $receipt->transaction_id) {
                DB::table('clients')->where('id', $receipt->supplier_id)->update([
                    'balance' => DB::raw("balance + {$receipt->amount}")
                ]);
            }

            $receipt->delete();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }


    // Обновление стоков
    private function updateStock($warehouse_id, $product_id, $add_quantity)
    {
        WarehouseStock::updateOrCreate(
            [
                'warehouse_id' => $warehouse_id,
                'product_id'   => $product_id,
            ],
            [
                'quantity' => DB::raw('quantity + ' . $add_quantity)
            ]
        );
        return true;
    }

    // Обновление цены покупки продукта
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
