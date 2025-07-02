<?php

namespace App\Repositories;

use App\Models\ClientBalance;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesProduct;
use App\Models\WarehouseStock;
use App\Models\CashRegister;
use App\Services\CurrencyConverter;
use Illuminate\Support\Facades\DB;

class SalesRepository
{
    // Получение с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        $paginator = Sale::select('sales.id as id')
            // ->where('user_id', $userUuid)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
        $items_ids = $paginator->pluck('id')->toArray();
        $items = $this->getItems($items_ids);
        $ordered_items = $items->sortBy(function ($item) use ($items_ids) {
            return array_search($item->id, $items_ids);
        })->values();
        $paginator->setCollection($ordered_items);
        return $paginator;
    }

    private function getItems(array $ids = [])
    {
        $query = Sale::query();
        // Присоединяем таблицу касс
        $query->leftJoin('cash_registers', 'sales.cash_id', '=', 'cash_registers.id');
        $query->leftJoin('currencies as cash_currency', 'cash_registers.currency_id', '=', 'cash_currency.id');
        // Присоединяем таблицу складов
        $query->leftJoin('warehouses', 'sales.warehouse_id', '=', 'warehouses.id');
        // Присоединяем таблицу пользователей
        $query->leftJoin('users', 'sales.user_id', '=', 'users.id');
        // Присоединяем таблицу проектов
        $query->leftJoin('projects', 'sales.project_id', '=', 'projects.id');
        // Берем нужные по массиву ид
        $query->whereIn('sales.id', $ids);
        // Выбираем поля
        $query->select(
            // Поля из таблицы sales
            'sales.id as id',
            'sales.price as price',
            'sales.discount as discount',
            'sales.total_price as total_price',

            // здесь берём валюту не из sale, а из кассы
            'cash_registers.id as cash_id',
            'cash_registers.name as cash_name',
            'cash_currency.id as currency_id',
            'cash_currency.name as currency_name',
            'cash_currency.code as currency_code',
            'cash_currency.symbol as currency_symbol',
            // Поля из таблицы касс
            'sales.cash_id as cash_id',
            'cash_registers.name as cash_name',
            // Поля из таблицы складов
            'sales.warehouse_id as warehouse_id',
            'warehouses.name as warehouse_name',
            // Поля из таблицы пользователей
            'sales.user_id as user_id',
            'users.name as user_name',
            // Поля из таблицы проектов
            'sales.project_id as project_id',
            'projects.name as project_name',
            // Поля из таблицы клиентов
            'sales.client_id as client_id',
            // Остальные поля
            'sales.note as note',
            'sales.date as date',
            'sales.created_at as created_at',
            'sales.updated_at as updated_at',

        );
        $items = $query->get();

        $items_ids = $items->pluck('id')->toArray();
        $products = $this->getProducts($items_ids);


        $client_ids = $items->pluck('client_id')->toArray();
        $client_repository = new ClientsRepository();
        $clients = $client_repository->getItemsByIds($client_ids);

        foreach ($items as $item) {
            $item->products = $products->get($item->id, collect());
            $item->client = $clients->firstWhere('id', $item->client_id);
        }
        return $items;
    }

    // public function createItem($data)
    // {
    //     // Берем все данные из формы
    //     $userUuid = $data['user_id'];

    //     $client_id = $data['client_id'];
    //     $project_id = $data['project_id'];
    //     $cash_id = $data['cash_id'];

    //     $warehouse_id = $data['warehouse_id'];
    //     $products = $data['products'];
    //     $currency_id = $data['currency_id'];
    //     $discount = $data['discount'];
    //     $discount_type = $data['discount_type'];

    //     $date = $data['date'];
    //     $note = $data['note'];

    //     // Находим выбранную валюту и валюту по умолчанию
    //     $defaultCurrency = Currency::firstWhere('is_default', true);
    //     $fromCurrency = Currency::find($currency_id);

    //     // Считаем общую стоимость и скидку
    //     $price = 0;
    //     $dicount_calculated = 0;
    //     $total_price = 0;

    //     DB::beginTransaction();
    //     try {
    //         foreach ($products as $product) {
    //             $p_id = $product['product_id'];
    //             $q = $product['quantity'];
    //             $p = $product['price'];

    //             $product_object = Product::find($p_id);
    //             if (!$product_object) {
    //                 throw new \Exception("Товар ID {$p_id} не найден");
    //             }

    //             if ($product_object->type == 1) {
    //                 $warehouse_product = WarehouseStock::where('product_id', $p_id)
    //                     ->where('warehouse_id', $warehouse_id)
    //                     ->first();

    //                 if (!$warehouse_product || $warehouse_product->quantity < $q) {
    //                     throw new \Exception("На складе {$warehouse_id} недостаточно товара ID {$p_id}");
    //                 }

    //                 WarehouseStock::where('product_id', $p_id)
    //                     ->where('warehouse_id', $warehouse_id)
    //                     ->update(['quantity' => DB::raw('quantity - ' . $q)]);
    //             }

    //             $origPrice = $q * $p;                   // исходная валюта (USD)
    //             $convPrice = CurrencyConverter::convert($origPrice, $fromCurrency, $defaultCurrency);
    //             $price += $convPrice;
    //         }
    //         $dicount_calculated = $discount_type == 'percent' ? $price * $discount / 100 : CurrencyConverter::convert($discount, $fromCurrency, $defaultCurrency);
    //         $total_price = $price - $dicount_calculated;

    //         $transaction_id = null;

    //         ClientBalance::updateOrCreate(
    //             ['client_id' => $client_id],
    //             ['balance'   => DB::raw('balance + ' . $total_price)]
    //         );

    //         // Если указана касса, создаем транзакцию
    //         if ($cash_id) {
    //             $transactionData = [
    //                 'type' => 1,
    //                 'user_id' => $userUuid,
    //                 'orig_amount' => $total_price,
    //                 'currency_id' => $defaultCurrency->id,
    //                 'cash_id' => $cash_id,
    //                 'category_id' => 1,
    //                 'project_id' => $project_id,
    //                 'client_id' => $client_id,
    //                 'note' => $note,
    //                 'date' => $date ?? now()
    //             ];
    //             $transactionRepository = new TransactionsRepository();
    //             $transaction_id = $transactionRepository->createItem($transactionData, true);
    //         }

    //         // Создаем запись в таблице продаж
    //         $sale = new Sale();
    //         $sale->client_id = $client_id;
    //         $sale->project_id = $project_id;
    //         $sale->cash_id = $cash_id;
    //         $sale->warehouse_id = $warehouse_id;
    //         $sale->price = $price;
    //         $sale->discount = $dicount_calculated;
    //         $sale->total_price = $total_price;
    //         $sale->transaction_id = $transaction_id;
    //         $sale->date = $date;
    //         $sale->note = $note;
    //         $sale->user_id = $userUuid;
    //         $sale->save();

    //         // Добавляем товары
    //         foreach ($products as $product) {
    //             $p_id = $product['product_id'];
    //             $q    = $product['quantity'];
    //             $p    = $product['price'];

    //             // цена за единицу в валюте по-умолчанию
    //             $unitPrice = CurrencyConverter::convert($p, $fromCurrency, $defaultCurrency);

    //             $sale_product              = new SalesProduct();
    //             $sale_product->sale_id     = $sale->id;
    //             $sale_product->product_id  = $p_id;
    //             $sale_product->quantity    = $q;
    //             $sale_product->price       = $unitPrice;      // корректная цена
    //             $sale_product->save();
    //         }

    //         DB::commit();
    //         return true;
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         // return 'Ошибка продажи: ' . $e->getMessage();
    //         return false;
    //     }
    // }

    public function createItem(array $data)
    {
        DB::beginTransaction();
        try {
            $userId      = $data['user_id'];
            $clientId    = $data['client_id'];
            $projectId   = $data['project_id'] ?? null;
            $warehouseId = $data['warehouse_id'];
            $cashId      = $data['cash_id'] ?? null;
            $discount    = $data['discount'] ?? 0;
            $discountType = $data['discount_type'] ?? 'percent';
            $date        = $data['date'] ?? now();
            $note        = $data['note'] ?? '';
            $products    = $data['products'];

            // 1) Валюты
            $defaultCurrency = Currency::firstWhere('is_default', true);
            // валюта откуда конвертировать — из кассы (если cash) или из переданной currency_id
            $fromCurrency = $defaultCurrency;
            if ($cashId) {
                $cash = CashRegister::find($cashId);
                if ($cash) {
                    $fromCurrency = Currency::find($cash->currency_id) ?? $defaultCurrency;
                }
            } elseif (! empty($data['currency_id'])) {
                $fromCurrency = Currency::find($data['currency_id']) ?? $defaultCurrency;
            }

            // 2) Считаем цену по-умолчанию
            $price = 0;
            foreach ($products as $prod) {
                $orig = $prod['price'] * $prod['quantity'];
                // конвертация из fromCurrency в defaultCurrency
                $price += CurrencyConverter::convert($orig, $fromCurrency, $defaultCurrency);
                // и уменьшаем склад
                $p = Product::findOrFail($prod['product_id']);
                if ($p->type == 1) {
                    WarehouseStock::where('product_id', $p->id)
                        ->where('warehouse_id', $warehouseId)
                        ->decrement('quantity', $prod['quantity']);
                }
            }

            // 3) Считаем скидку и итог
            $discountCalc = $discountType === 'percent'
                ? $price * $discount / 100
                : CurrencyConverter::convert($discount, $fromCurrency, $defaultCurrency);
            $totalPrice = $price - $discountCalc;

            // 4) Обновляем баланс или создаём транзакцию
            $transactionId = null;
            if (!empty($cashId)) {
                $txRepo = new TransactionsRepository();
                $transactionId = $txRepo->createItem([
                    'type'        => 1,
                    'user_id'     => $userId,
                    'orig_amount' => $totalPrice,
                    'currency_id' => $defaultCurrency->id,
                    'cash_id'     => $cashId,
                    'category_id' => 1,
                    'project_id'  => $projectId,
                    'client_id'   => $clientId,
                    'note'        => $note,
                    'date'        => $date,
                ], true, true);
            } else {
                ClientBalance::updateOrCreate(
                    ['client_id' => $clientId],
                    ['balance' => DB::raw("COALESCE(balance, 0) + {$totalPrice}")]
                );
            }


            // 5) Сохраняем продажу
            $sale = Sale::create([
                'user_id'        => $userId,
                'client_id'      => $clientId,
                'project_id'     => $projectId,
                'cash_id'        => $cashId,
                'warehouse_id'   => $warehouseId,
                'price'          => $price,
                'discount'       => $discountCalc,
                'total_price'    => $totalPrice,
                'transaction_id' => $transactionId,
                'date'           => $date,
                'note'           => $note,
            ]);

            // 6) Записываем детали с ценой в defaultCurrency
            foreach ($products as $prod) {
                SalesProduct::create([
                    'sale_id'    => $sale->id,
                    'product_id' => $prod['product_id'],
                    'quantity'   => $prod['quantity'],
                    'price'      => CurrencyConverter::convert(
                        $prod['price'],
                        $fromCurrency,
                        $defaultCurrency
                    ),
                ]);
            }

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }



    private function getProducts($sale_ids)
    {
        return SalesProduct::whereIn('sale_id', $sale_ids)
            ->leftJoin('products', 'sales_products.product_id', '=', 'products.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->select(
                'sales_products.id as id',
                'sales_products.sale_id as sale_id',
                'sales_products.product_id as product_id',
                'products.name as product_name',
                'products.image as product_image',
                'products.unit_id as unit_id',
                'units.name as unit_name',
                'units.short_name as unit_short_name',
                'sales_products.quantity as quantity',
                'sales_products.price as price'
            )
            ->get()
            ->groupBy('sale_id');
    }



    public function deleteItem(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $sale     = Sale::findOrFail($id);
            $products = SalesProduct::where('sale_id', $id)->get();

            // 1) Откатываем товары на склад и удаляем записи сразу
            foreach ($products as $p) {
                $prod = Product::find($p->product_id);
                if ($prod && $prod->type == 1) {
                    WarehouseStock::updateOrCreate(
                        ['warehouse_id' => $sale->warehouse_id, 'product_id' => $p->product_id],
                        ['quantity'     => DB::raw("quantity + {$p->quantity}")]
                    );
                }
                $p->delete();
            }

            // 2) Удаляем транзакцию без ручного обновления баланса клиента
            if ($sale->transaction_id) {
                $txRepo = new TransactionsRepository();
                $txRepo->deleteItem($sale->transaction_id, true);
            }

            // 3) Если это «балансовая» продажа (нет кассы) — уменьшаем баланс клиента
            if ($sale->client_id && $sale->transaction_id === null) {
                ClientBalance::updateOrCreate(
                    ['client_id' => $sale->client_id],
                    ['balance' => DB::raw("COALESCE(balance, 0) - {$sale->total_price}")]
                );
            }

            // 4) Удаляем саму продажу
            $sale->delete();

            return true;
        });
    }
}
