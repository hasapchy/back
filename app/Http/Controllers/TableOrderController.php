<?php
namespace App\Http\Controllers;


use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use App\Models\TableOrder;


class TableOrderController extends Controller
{
    public function saveOrder(Request $request)
    {
        $validated = $request->validate([
            'table_name' => 'required|string', // Имя таблицы
            'order' => 'required|string',     // Порядок ячеек
        ]);

        TableOrder::updateOrCreate(
            [
                'user_id' => auth()->id(),             // Привязка к пользователю
                'table_name' => $validated['table_name'], // Имя таблицы
            ],
            [
                'order' => $validated['order'],        // Сохранение порядка
            ]
        );

        return response()->json(['message' => 'Order saved successfully']);
    }



    public function getOrder(Request $request)
    {
        $validated = $request->validate([
            'table_name' => 'required|string',
        ]);

        $order = TableOrder::where('user_id', auth()->id())
            ->where('table_name', $validated['table_name'])
            ->first();

        return response()->json(['order' => $order->order ?? null]);
    }


}
