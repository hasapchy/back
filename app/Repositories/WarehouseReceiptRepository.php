<?php

namespace App\Repositories;

use App\Models\WarehouseStock;
use App\Models\WhReceipt;
use App\Models\WhReceiptProduct;

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
