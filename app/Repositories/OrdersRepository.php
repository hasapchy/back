<?php

namespace App\Repositories;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\WarehouseStock;
use App\Models\ClientBalance;
use App\Models\Currency;
use App\Models\Transaction;
use App\Services\CurrencyConverter;
use Illuminate\Support\Facades\DB;

class OrdersRepository
{
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        $paginator = Order::select('orders.id as id')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $order_ids = $paginator->pluck('id')->toArray();
        $items = $this->getItems($order_ids);

        $ordered = $items->sortBy(function ($item) use ($order_ids) {
            return array_search($item->id, $order_ids);
        })->values();

        $paginator->setCollection($ordered);
        return $paginator;
    }

    private function getItems(array $order_ids = [])
    {
        $query = Order::query();

        $query->leftJoin('warehouses', 'orders.warehouse_id', '=', 'warehouses.id');
        $query->leftJoin('cash_registers', 'orders.cash_id', '=', 'cash_registers.id');
        $query->leftJoin('projects', 'orders.project_id', '=', 'projects.id');
        $query->leftJoin('users', 'orders.user_id', '=', 'users.id');
        $query->leftJoin('currencies as cash_currency', 'cash_registers.currency_id', '=', 'cash_currency.id');
        $query->leftJoin('order_statuses', 'orders.status_id', '=', 'order_statuses.id');
        $query->leftJoin('order_categories', 'orders.category_id', '=', 'order_categories.id');
        $query->leftJoin('order_status_categories', 'order_statuses.category_id', '=', 'order_status_categories.id');


        $query->whereIn('orders.id', $order_ids);

        $query->select(
            'orders.id',
            'orders.note',
            'orders.description',
            'orders.status_id',
            'order_statuses.name as status_name',
            'order_statuses.category_id as status_category_id',
            'order_status_categories.name as status_category_name',
            'order_status_categories.color as status_category_color',
            'orders.category_id',
            'order_categories.name as category_name',
            'orders.client_id',
            'orders.user_id',
            'orders.cash_id',
            'orders.warehouse_id',
            'orders.project_id',
            'orders.transaction_ids',
            'orders.price',
            'orders.discount',
            'orders.total_price',
            'orders.date',
            'orders.created_at',
            'orders.updated_at',
            'warehouses.name as warehouse_name',
            'cash_registers.name as cash_name',
            'cash_currency.id as currency_id',
            'cash_currency.name as currency_name',
            'cash_currency.code as currency_code',
            'cash_currency.symbol as currency_symbol',
            'projects.name as project_name',
            'users.name as user_name'
        );

        $items = $query->get();

        $products = $this->getProducts($order_ids);
        $client_ids = $items->pluck('client_id')->toArray();
        $client_repository = new ClientsRepository();
        $clients = $client_repository->getItemsByIds($client_ids);

        foreach ($items as $item) {
            $item->products = $products->get($item->id, collect());
            $item->client = $clients->firstWhere('id', $item->client_id);
            $item->status = [
                'id' => $item->status_id,
                'name' => $item->status_name,
                'category_id' => $item->status_category_id,
                'category' => $item->status_category_id ? [
                    'id' => $item->status_category_id,
                    'name' => $item->status_category_name,
                    'color' => $item->status_category_color,
                ] : null,
            ];
        }

        return $items;
    }

    private function getProducts(array $order_ids)
    {
        return OrderProduct::whereIn('order_id', $order_ids)
            ->leftJoin('products', 'order_products.product_id', '=', 'products.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->select(
                'order_products.id',
                'order_products.order_id',
                'order_products.product_id',
                'products.name as product_name',
                'products.image as product_image',
                'products.unit_id',
                'units.name as unit_name',
                'units.short_name as unit_short_name',
                'order_products.quantity',
                'order_products.price'
            )
            ->get()
            ->groupBy('order_id');
    }


    public function createItem($data)
    {
        $userUuid = $data['user_id'];
        $client_id = $data['client_id'];
        $warehouse_id = $data['warehouse_id'];
        $cash_id = $data['cash_id'];
        $project_id = $data['project_id'];
        $status_id = $data['status_id'] ?? 1;
        $category_id = $data['category_id'];
        $products = $data['products'];
        $currency_id = $data['currency_id'];
        $discount = $data['discount'] ?? 0;
        $discount_type = $data['discount_type'] ?? 'fixed';
        $date = $data['date'] ?? now();
        $note = $data['note'] ?? '';
        $description = $data['description'] ?? '';
        // $transaction_ids = $data['transaction_ids'] ?? [];

        $defaultCurrency = Currency::firstWhere('is_default', true);
        $fromCurrency = Currency::find($currency_id);

        $price = 0;
        $discount_calculated = 0;
        $total_price = 0;

        DB::beginTransaction();
        try {
            // Рассчитываем цену заказа
            foreach ($products as $product) {
                $p_id = $product['product_id'];
                $q = $product['quantity'];
                $p = $product['price'];

                $product_object = Product::find($p_id);
                if (!$product_object) {
                    throw new \Exception("Товар ID {$p_id} не найден");
                }

                if ($product_object->type == 1) {
                    $warehouse_product = WarehouseStock::where('product_id', $p_id)
                        ->where('warehouse_id', $warehouse_id)
                        ->first();

                    if (!$warehouse_product || $warehouse_product->quantity < $q) {
                        throw new \Exception("На складе {$warehouse_id} недостаточно товара ID {$p_id}");
                    }

                    WarehouseStock::where('product_id', $p_id)
                        ->where('warehouse_id', $warehouse_id)
                        ->update(['quantity' => DB::raw('quantity - ' . $q)]);
                }

                $origPrice = $q * $p;
                // $convPrice = CurrencyConverter::convert($origPrice, $fromCurrency, $defaultCurrency);
                // $price += $convPrice;
                $price += $origPrice; // Без конвертации
            }

            // Рассчитываем скидку
            // $discount_calculated = $discount_type == 'percent' ?
            //     $price * $discount / 100 :
            //     CurrencyConverter::convert($discount, $fromCurrency, $defaultCurrency);
            if ($discount_type == 'percent') {
                $discount_calculated = $price * $discount / 100;
            } else {
                $discount_calculated = $discount; // Без конвертации
            }
            $total_price = $price - $discount_calculated;

            $order = new Order();
            $order->client_id = $client_id;
            $order->project_id = $project_id;
            $order->warehouse_id = $warehouse_id;
            $order->cash_id = $cash_id;
            $order->status_id = $status_id;
            $order->category_id = $category_id;
            $order->price = $price;
            $order->discount = $discount_calculated;
            $order->total_price = $total_price;
            $order->date = $date;
            $order->note = $note;
            $order->description = $description;
            $order->user_id = $userUuid;
            $order->save();

            // Добавляем товары
            foreach ($products as $product) {
                $p_id = $product['product_id'];
                $q = $product['quantity'];
                $p = $product['price'];

                // $unitPrice = CurrencyConverter::convert($p, $fromCurrency, $defaultCurrency);
                $unitPrice = $p; // Без конвертации

                $order_product = new OrderProduct();
                $order_product->order_id = $order->id;
                $order_product->product_id = $p_id;
                $order_product->quantity = $q;
                $order_product->price = $unitPrice;
                $order_product->save();
            }

            // Обновляем баланс клиента
            ClientBalance::updateOrCreate(
                ['client_id' => $client_id],
                ['balance' => DB::raw("COALESCE(balance, 0) + {$total_price}")]
            );

            DB::commit();
            return $order;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception("Ошибка создания заказа: " . $e->getMessage());
        }
    }

    public function updateItem($id, $data)
    {
        DB::beginTransaction();
        try {
            $order = Order::findOrFail($id);
            $oldProducts = OrderProduct::where('order_id', $id)->get();

            // 1. Возвращаем старые товары на склад
            foreach ($oldProducts as $product) {
                $productObj = Product::find($product->product_id);
                if ($productObj && $productObj->type == 1) {
                    WarehouseStock::where('product_id', $product->product_id)
                        ->where('warehouse_id', $order->warehouse_id)
                        ->update(['quantity' => DB::raw('quantity + ' . $product->quantity)]);
                }
            }

            // 3. Уменьшаем баланс клиента
            if ($order->client_id) {
                ClientBalance::where('client_id', $order->client_id)
                    ->update(['balance' => DB::raw("COALESCE(balance, 0) - {$order->total_price}")]);
            }

            $user_id = $data['user_id'];
            $client_id = $data['client_id'];
            $warehouse_id = $data['warehouse_id'];
            $cash_id = $data['cash_id'];
            $project_id = $data['project_id'];
            $status_id = $data['status_id'] ?? $order->status_id;
            $category_id = $data['category_id'] ?? null; // Добавляем обработку category_id
            $products = $data['products'];
            $currency_id = $data['currency_id'] ?? $order->currency_id; // Используем текущий currency_id, если не передан
            $discount = $data['discount'] ?? 0;
            $discount_type = $data['discount_type'] ?? 'fixed';
            $note = $data['note'] ?? '';
            $description = $data['description'] ?? '';
            $date = $data['date'] ?? now();

            $defaultCurrency = Currency::firstWhere('is_default', true);
            $fromCurrency = Currency::find($currency_id);

            $price = 0;
            $discount_calculated = 0;
            $total_price = 0;

            // 6. Списание товаров
            foreach ($products as $product) {
                $p_id = $product['product_id'];
                $q = $product['quantity'];
                $p = $product['price'];

                $product_object = Product::find($p_id);
                if (!$product_object) {
                    throw new \Exception("Товар ID {$p_id} не найден");
                }

                if ($product_object->type == 1) {
                    $stock = WarehouseStock::where('product_id', $p_id)
                        ->where('warehouse_id', $warehouse_id)
                        ->first();

                    if (!$stock || $stock->quantity < $q) {
                        throw new \Exception("Недостаточно товара ID {$p_id}");
                    }

                    WarehouseStock::where('product_id', $p_id)
                        ->where('warehouse_id', $warehouse_id)
                        ->update(['quantity' => DB::raw('quantity - ' . $q)]);
                }

                $origPrice = $q * $p;
                // $convPrice = CurrencyConverter::convert($origPrice, $fromCurrency, $defaultCurrency);
                // $price += $convPrice;
                $price += $origPrice; // Без конвертации
            }

            // Рассчитываем скидку
            // $discount_calculated = $discount_type == 'percent' ?
            //     $price * $discount / 100 :
            //     CurrencyConverter::convert($discount, $fromCurrency, $defaultCurrency);
            if ($discount_type == 'percent') {
                $discount_calculated = $price * $discount / 100;
            } else {
                $discount_calculated = $discount; // Без конвертации
            }
            $total_price = $price - $discount_calculated;

            // 7. Обновляем заказ
            $order->update([
                'client_id' => $client_id,
                'project_id' => $project_id,
                'warehouse_id' => $warehouse_id,
                'cash_id' => $cash_id,
                'status_id' => $status_id,
                'category_id' => $category_id,
                'price' => $price,
                'discount' => $discount_calculated,
                'total_price' => $total_price,
                'date' => $date,
                'note' => $note,
                'description' => $description,
                'user_id' => $user_id
            ]);

            // 8. Заменяем товары
            OrderProduct::where('order_id', $id)->delete();
            foreach ($products as $product) {
                // $unitPrice = CurrencyConverter::convert($product['price'], $fromCurrency, $defaultCurrency);
                $unitPrice = $product['price']; // Без конвертации
                OrderProduct::create([
                    'order_id' => $id,
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                    'price' => $unitPrice
                ]);
            }

            // 9. Обновляем баланс клиента
            ClientBalance::updateOrCreate(
                ['client_id' => $client_id],
                ['balance' => DB::raw("COALESCE(balance, 0) + {$total_price}")]
            );

            DB::commit();
            return $order;
        } catch (\Throwable $th) {
            DB::rollBack();
            throw new \Exception("Ошибка обновления заказа: " . $th->getMessage());
        }
    }

    public function deleteItem($id)
    {
        return DB::transaction(function () use ($id) {
            $order = Order::findOrFail($id);
            $products = OrderProduct::where('order_id', $id)->get();

            // Возвращаем товары на склад
            foreach ($products as $product) {
                $productObject = Product::find($product->product_id);
                if ($productObject && $productObject->type == 1) {
                    WarehouseStock::where('product_id', $product->product_id)
                        ->where('warehouse_id', $order->warehouse_id)
                        ->update(['quantity' => DB::raw('quantity + ' . $product->quantity)]);
                }
            }

            // Корректируем баланс клиента
            if ($order->client_id) {
                ClientBalance::where('client_id', $order->client_id)
                    ->update(['balance' => DB::raw("COALESCE(balance, 0) - {$order->total_price}")]);
            }

            // Удаляем товары
            OrderProduct::where('order_id', $id)->delete();

            // Удаляем заказ
            $order->delete();

            return [
                'id' => $order->id,
                'client_id' => $order->client_id,
                'warehouse_id' => $order->warehouse_id,
                'total_price' => $order->total_price,
                'products' => $products->map(function ($product) {
                    return [
                        'product_id' => $product->product_id,
                        'quantity' => $product->quantity,
                        'price' => $product->price
                    ];
                })->toArray()
            ];
        });
    }
    public function updateStatusByIds(array $ids, int $statusId, string $userId): int
    {
        return Order::whereIn('id', $ids)  
            ->where('user_id', $userId)    
            ->update(['status_id' => $statusId]);
    }
}
