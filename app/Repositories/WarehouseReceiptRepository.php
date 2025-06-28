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

class WarehouseReceiptRepository
{
    // Получение стоков с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        $items = WhReceipt::leftJoin('warehouses', 'wh_receipts.warehouse_id', '=', 'warehouses.id')
            // ->leftJoin('currencies', 'wh_receipts.currency_id', '=', 'currencies.id')
            ->whereJsonContains('warehouses.users', (string) $userUuid)
            ->select(
                'wh_receipts.id as id',
                'wh_receipts.warehouse_id as warehouse_id',
                'warehouses.name as warehouse_name',
                'wh_receipts.supplier_id as supplier_id',
                'wh_receipts.amount as amount',
                'wh_receipts.currency_id as currency_id',
                // 'currencies.code as currency_code',
                // 'currencies.name as currency_name',
                // 'currencies.symbol as currency_symbol',
                'wh_receipts.note as note',
                'wh_receipts.date as date',
                'wh_receipts.created_at as created_at',
                'wh_receipts.updated_at as updated_at'
            )
            ->orderBy('wh_receipts.created_at', 'desc')->paginate($perPage);

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

    public function createReceipt(array $data)
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
            // 1) Определяем валюту: из кассы если cash, иначе дефолтная
            $defaultCurrency = Currency::firstWhere('is_default', true);
            $currency = $defaultCurrency;
            if ($type === 'cash' && $cash_id) {
                $cash = CashRegister::find($cash_id);
                if ($cash) {
                    $currency = Currency::find($cash->currency_id) ?? $defaultCurrency;
                }
            }

            // 2) Подсчитываем сумму по товарам (без конвертации, предполагаем что цена корректна)
            $total_amount = 0;
            foreach ($products as $product) {
                $total_amount += $product['price'] * $product['quantity'];
            }

            // 3) Создаем receipt с суммой и валютой
            $receipt = new WhReceipt();
            $receipt->supplier_id  = $client_id;
            $receipt->warehouse_id = $warehouse_id;
            $receipt->currency_id  = $currency->id;
            $receipt->date         = $date;
            $receipt->note         = $note;
            $receipt->amount       = $total_amount;
            $receipt->save();

            // 4) Создаем продукты для receipt и обновляем склад
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

            // 5) Обновляем баланс клиента если тип balance
            if ($type === 'balance') {
                ClientBalance::updateOrCreate(
                    ['client_id' => $client_id],
                    ['balance' => DB::raw("COALESCE(balance, 0) - {$total_amount}")]
                );
            } else {
                // 6) Если тип cash, создаём расходную транзакцию (не трогаем баланс клиента)
                $txData = [
                    'type'        => 0,
                    'user_id'     => auth('api')->id(),
                    'orig_amount' => $total_amount,
                    'currency_id' => $currency->id,
                    'cash_id'     => $cash_id,
                    'category_id' => 7,
                    'project_id'  => null,
                    'client_id'   => $client_id,
                    'note'        => $note,
                    'date'        => $date,
                ];
                $txRepo = new TransactionsRepository();
                $transaction_id = $txRepo->createItem($txData, true, true);
            }

            // 7) Обновляем receipt с id транзакции, если она есть
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
        $client_id = $data['client_id'];
        $warehouse_id = $data['warehouse_id'];
        $currency_id = $data['currency_id'];
        $date = $data['date'];
        $note = $data['note'];
        $products = $data['products'];

        DB::beginTransaction();

        try {
            $receipt = WhReceipt::find($receipt_id);
            if (!$receipt) {
                throw new \Exception('Receipt not found');
            }

            $old_total_amount = $receipt->amount;

            $receipt->supplier_id = $client_id;
            $receipt->warehouse_id = $warehouse_id;
            // $receipt->currency_id = $currency_id;
            $receipt->currency_id = Currency::firstWhere('is_default', true)->id;
            $receipt->date = $date;
            $receipt->note = $note;
            $receipt->amount = 0;
            $receipt->save();

            $total_amount = 0;

            $defaultCurrency = Currency::firstWhere('is_default', true);
            // $fromCurrency = Currency::find($currency_id);
            $fromCurrency = $defaultCurrency;
            $existingProducts = WhReceiptProduct::where('receipt_id', $receipt_id)->get();
            $existingProductIds = $existingProducts->pluck('product_id')->toArray();

            foreach ($products as $product) {
                $product_id = $product['product_id'];
                $quantity = $product['quantity'];
                $price = $product['price'];

                // $converted_price = CurrencyConverter::convert($price, $fromCurrency, $defaultCurrency);
                $converted_price = $price;

                $receiptProduct = WhReceiptProduct::updateOrCreate(
                    ['receipt_id' => $receipt->id, 'product_id' => $product_id],
                    ['quantity' => $quantity, 'price' => $converted_price]
                );

                $existingProduct = $existingProducts->firstWhere('product_id', $product_id);
                $quantityDifference = $quantity - ($existingProduct ? $existingProduct->quantity : 0);
                $stock_updated = $this->updateStock($warehouse_id, $product_id, $quantityDifference);
                if (!$stock_updated) {
                    throw new \Exception('Ошибка обновления стоков');
                }
                $product_purchase_updated = $this->updateProductPurchasePrice($product_id, $converted_price);
                if (!$product_purchase_updated) {
                    throw new \Exception('Ошибка обновления цены покупки продукта');
                }
                $total_amount += $converted_price * $quantity;
            }

            $receipt->amount = $total_amount;
            $receipt->save();

            $client_balance_updated = $this->updateClientBalance($client_id, $total_amount - $old_total_amount);
            if (!$client_balance_updated) {
                throw new \Exception('Ошибка обновления баланса клиента');
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

    public function deleteReceipt($receipt_id)
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


    // // Создание склада с именем и массивом пользователей
    // public function createWarehouse($name, array $users)
    // {
    //     $warehouse = new Warehouse();
    //     $warehouse->name = $name;
    //     $warehouse->users = array_map('strval', $users);

    //     $warehouse->save();

    //     return true;
    // }

    // //  Обновление склада
    // public function updateWarehouse($id, $name, array $users)
    // {
    //     $warehouse = Warehouse::find($id);
    //     $warehouse->name = $name;
    //     $warehouse->users = $users;

    //     $warehouse->save();

    //     return true;
    // }

    // // Удаление склада
    // public function deleteWarehouse($id)
    // {
    //     $warehouse = Warehouse::find($id);
    //     $warehouse->delete();

    //     return true;
    // }
}
