<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\ProductStatus;
use App\Models\TransactionCategory;
use App\Models\Unit;
use App\Models\OrderCategory;
use App\Models\OrderStatus;
use Illuminate\Http\Request;

class AppController extends Controller
{
    // Получение списка валют
    public function getCurrencyList()
    {
        $items = Currency::where('status', 1)->get();

        return response()->json($items);
    }

    // получение единиц измерения
    public function getUnitsList()
    {
        $items = Unit::all();

        return response()->json($items);
    }

    // получение статусов товаров 
    public function getProductStatuses()
    {
        $items = ProductStatus::all();

        return response()->json($items);
    }

    // получение категорий транзакций 
    public function getTransactionCategories()
    {
        $items = TransactionCategory::select('id', 'name', 'type')->get();
        return response()->json($items);
    }


    // public function getOrderCategories()
    // {
    //     $items = OrderCategory::select('id', 'name')->orderBy('name')->get();
    //     return response()->json($items);
    // }

    // public function getOrderStatuses()
    // {

    //     $items = OrderStatus::with(['category' => function ($q) {
    //         $q->select('id', 'name', 'user_id', 'color');
    //     }])
    //         ->get(['id', 'name', 'category_id']);

    //     return response()->json($items);
    // }
}
