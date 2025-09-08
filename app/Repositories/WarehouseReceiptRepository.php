<?php

namespace App\Repositories;

use App\Models\ClientBalance;
use App\Models\Currency;
use App\Models\ProductPrice;
use App\Models\WarehouseStock;
use App\Models\WhReceipt;
use App\Models\WhReceiptProduct;
use App\Models\CashRegister;
use App\Services\CurrencyConverter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WarehouseReceiptRepository
{
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        $warehouseIds = DB::table('wh_users')
            ->where('user_id', $userUuid)
            ->pluck('warehouse_id')
            ->toArray();

        $items = WhReceipt::select([
                'wh_receipts.id as id',
                'wh_receipts.warehouse_id as warehouse_id',
                'wh_receipts.supplier_id as supplier_id',
                'wh_receipts.amount as amount',
                'wh_receipts.cash_id as cash_id',
                'wh_receipts.note as note',
                'wh_receipts.user_id as user_id',
                'wh_receipts.date as date',
                'wh_receipts.created_at as created_at',
                'wh_receipts.updated_at as updated_at',
                DB::raw('(SELECT name FROM warehouses WHERE warehouses.id = wh_receipts.warehouse_id) as warehouse_name'),
                DB::raw('(SELECT name FROM users WHERE users.id = wh_receipts.user_id) as user_name'),
                DB::raw('(SELECT name FROM cash_registers WHERE cash_registers.id = wh_receipts.cash_id) as cash_name'),
                DB::raw('(SELECT id FROM currencies WHERE currencies.id = (SELECT currency_id FROM cash_registers WHERE cash_registers.id = wh_receipts.cash_id)) as currency_id'),
                DB::raw('(SELECT name FROM currencies WHERE currencies.id = (SELECT currency_id FROM cash_registers WHERE cash_registers.id = wh_receipts.cash_id)) as currency_name'),
                DB::raw('(SELECT code FROM currencies WHERE currencies.id = (SELECT currency_id FROM cash_registers WHERE cash_registers.id = wh_receipts.cash_id)) as currency_code'),
                DB::raw('(SELECT symbol FROM currencies WHERE currencies.id = (SELECT currency_id FROM cash_registers WHERE cash_registers.id = wh_receipts.cash_id)) as currency_symbol')
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
            ->paginate($perPage);

        $client_ids = $items->pluck('supplier_id')->toArray();
        $client_repository = new ClientsRepository();
        $clients = $client_repository->getItemsByIds($client_ids);

        $wh_receipt_ids = $items->pluck('id')->toArray();
        $products = $this->getProducts($wh_receipt_ids);

        foreach ($items as $item) {
            $item->client = $clients->firstWhere('id', $item->supplier_id);
            $item->products = $products->get($item->id, collect());
        }

        return $items;
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

            $transaction_id = null;

            if ($type === 'balance') {
                ClientBalance::updateOrCreate(
                    ['client_id' => $client_id],
                    ['balance' => DB::raw("COALESCE(balance, 0) - {$total_amount}")]
                );
            } else {
                $txData = [
                    'type'        => 0,
                    'user_id'     => auth('api')->id(),
                    'orig_amount' => $total_amount,
                    'currency_id' => $currency->id,
                    'cash_id'     => $cash_id,
                    'category_id' => 6,
                    'project_id'  => null,
                    'client_id'   => $client_id,
                    'note'        => $note,
                    'date'        => $date,
                ];
                $txRepo = new TransactionsRepository();
                $transaction_id = $txRepo->createItem($txData, true, true);
            }

            if ($transaction_id) {
                $receipt->transaction_id = $transaction_id;
                $receipt->save();
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
                ClientBalance::updateOrCreate(
                    ['client_id' => $receipt->supplier_id],
                    ['balance' => DB::raw("COALESCE(balance,0) + {$receipt->amount}")]
                );
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
        ClientBalance::updateOrCreate(
            ['client_id' => $client_id],
            ['balance'   => DB::raw('balance - ' . $amount)]
        );
        return true;
    }

    private function getProducts($wh_receipt_ids)
    {
        return WhReceiptProduct::whereIn('receipt_id', $wh_receipt_ids)
            ->leftJoin('products', 'wh_receipt_products.product_id', '=', 'products.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->select(
                'wh_receipt_products.id as id',
                'wh_receipt_products.receipt_id as receipt_id',
                'wh_receipt_products.product_id as product_id',
                'products.name as product_name',
                'products.image as product_image',
                'products.unit_id as unit_id',
                'units.name as unit_name',
                'units.short_name as unit_short_name',
                'wh_receipt_products.quantity as quantity',
                'wh_receipt_products.price as price',
                'wh_receipt_products.sn_id as sn_id'
            )
            ->get()
            ->groupBy('receipt_id');
    }

}
