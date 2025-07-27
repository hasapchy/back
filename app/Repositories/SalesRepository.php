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
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        $paginator = Sale::select('sales.id as id')
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
        $query->leftJoin('cash_registers', 'sales.cash_id', '=', 'cash_registers.id');
        $query->leftJoin('currencies as cash_currency', 'cash_registers.currency_id', '=', 'cash_currency.id');
        $query->leftJoin('warehouses', 'sales.warehouse_id', '=', 'warehouses.id');
        $query->leftJoin('users', 'sales.user_id', '=', 'users.id');
        $query->leftJoin('projects', 'sales.project_id', '=', 'projects.id');
        $query->whereIn('sales.id', $ids);
        $query->select(
            'sales.id as id',
            'sales.price as price',
            'sales.discount as discount',
            'sales.total_price as total_price',
            'cash_registers.id as cash_id',
            'cash_registers.name as cash_name',
            'cash_currency.id as currency_id',
            'cash_currency.name as currency_name',
            'cash_currency.code as currency_code',
            'cash_currency.symbol as currency_symbol',
            'sales.cash_id as cash_id',
            'cash_registers.name as cash_name',
            'sales.warehouse_id as warehouse_id',
            'warehouses.name as warehouse_name',
            'sales.user_id as user_id',
            'users.name as user_name',
            'sales.project_id as project_id',
            'projects.name as project_name',
            'sales.client_id as client_id',
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

            $defaultCurrency = Currency::firstWhere('is_default', true);
            $fromCurrency = $defaultCurrency;
            if ($cashId) {
                $cash = CashRegister::find($cashId);
                if ($cash) {
                    $fromCurrency = Currency::find($cash->currency_id) ?? $defaultCurrency;
                }
            } elseif (! empty($data['currency_id'])) {
                $fromCurrency = Currency::find($data['currency_id']) ?? $defaultCurrency;
            }

            $price = 0;
            foreach ($products as $prod) {
                $orig = $prod['price'] * $prod['quantity'];
                $price += CurrencyConverter::convert($orig, $fromCurrency, $defaultCurrency);
                $p = Product::findOrFail($prod['product_id']);
                if ($p->type == 1) {
                    WarehouseStock::where('product_id', $p->id)
                        ->where('warehouse_id', $warehouseId)
                        ->decrement('quantity', $prod['quantity']);
                }
            }

            $discountCalc = $discountType === 'percent'
                ? $price * $discount / 100
                : CurrencyConverter::convert($discount, $fromCurrency, $defaultCurrency);
            $totalPrice = $price - $discountCalc;

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

    public function getItemById($id)
    {
        $items = $this->getItems([$id]);
        return $items->first();
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

            if ($sale->transaction_id) {
                $txRepo = new TransactionsRepository();
                $txRepo->deleteItem($sale->transaction_id, true);
            }

            if ($sale->client_id && $sale->transaction_id === null) {
                ClientBalance::updateOrCreate(
                    ['client_id' => $sale->client_id],
                    ['balance' => DB::raw("COALESCE(balance, 0) - {$sale->total_price}")]
                );
            }

            $sale->delete();

            return true;
        });
    }
}
