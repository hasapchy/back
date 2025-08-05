<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderTransaction;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderTransactionController extends Controller
{
    /**
     * Связать транзакцию с заказом
     */
    public function linkTransaction(Request $request, $orderId)
    {
        $request->validate([
            'transaction_id' => 'required|integer|exists:transactions,id',
        ]);

        $order = Order::findOrFail($orderId);

        // Проверяем права доступа
        if ($order->user_id != Auth::id()) {
            return response()->json(['message' => 'Нет доступа к заказу'], 403);
        }

        $transaction = Transaction::findOrFail($request->transaction_id);

        // Проверяем права доступа к транзакции
        if ($transaction->user_id != Auth::id()) {
            return response()->json(['message' => 'Нет доступа к транзакции'], 403);
        }

        // Создаем связь
        OrderTransaction::create([
            'order_id' => $orderId,
            'transaction_id' => $request->transaction_id,
        ]);

        return response()->json(['message' => 'Транзакция успешно связана с заказом']);
    }

    /**
     * Отвязать транзакцию от заказа
     */
    public function unlinkTransaction($orderId, $transactionId)
    {
        $order = Order::findOrFail($orderId);

        // Проверяем права доступа
        if ($order->user_id != Auth::id()) {
            return response()->json(['message' => 'Нет доступа к заказу'], 403);
        }

        // Удаляем связь
        OrderTransaction::where('order_id', $orderId)
            ->where('transaction_id', $transactionId)
            ->delete();

        return response()->json(['message' => 'Транзакция успешно отвязана от заказа']);
    }

    /**
     * Получить все транзакции заказа
     */
    public function getOrderTransactions($orderId)
    {
        $order = Order::findOrFail($orderId);

        // Проверяем права доступа
        if ($order->user_id != Auth::id()) {
            return response()->json(['message' => 'Нет доступа к заказу'], 403);
        }

        $transactions = $order->transactions()->get();

        return response()->json(['transactions' => $transactions]);
    }
}
