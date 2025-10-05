<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Transaction;
use App\Repositories\OrdersRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderTransactionController extends Controller
{
    protected $ordersRepository;

    public function __construct(OrdersRepository $ordersRepository)
    {
        $this->ordersRepository = $ordersRepository;
    }
    /**
     * Связать транзакцию с заказом
     */
    public function linkTransaction(Request $request, $orderId)
    {
        $request->validate([
            'transaction_id' => 'required|integer|exists:transactions,id',
        ]);

        $order = Order::findOrFail($orderId);

        // Проверяем права доступа к кассе заказа
        $userHasPermissionToCashRegister = $this->ordersRepository->userHasPermissionToCashRegister(Auth::id(), $order->cash_id);
        if (!$userHasPermissionToCashRegister) {
            return response()->json(['message' => 'У вас нет прав на эту кассу'], 403);
        }

        $transaction = Transaction::findOrFail($request->transaction_id);

        // Проверяем права доступа к транзакции
        if ($transaction->user_id != Auth::id()) {
            return response()->json(['message' => 'Нет доступа к транзакции'], 403);
        }

        // Устанавливаем morphable связь
        $transaction->source_type = \App\Models\Order::class;
        $transaction->source_id = $orderId;
        $transaction->save();

        return response()->json(['message' => 'Транзакция успешно связана с заказом']);
    }

    /**
     * Отвязать транзакцию от заказа
     */
    public function unlinkTransaction($orderId, $transactionId)
    {
        $order = Order::findOrFail($orderId);

        // Проверяем права доступа к кассе заказа
        $userHasPermissionToCashRegister = $this->ordersRepository->userHasPermissionToCashRegister(Auth::id(), $order->cash_id);
        if (!$userHasPermissionToCashRegister) {
            return response()->json(['message' => 'У вас нет прав на эту кассу'], 403);
        }

        // Снимаем morphable связь у транзакции
        $trx = Transaction::findOrFail($transactionId);
        if ($trx->source_type === \App\Models\Order::class && (int)$trx->source_id === (int)$orderId) {
            $trx->source_type = null;
            $trx->source_id = null;
            $trx->save();
        }

        return response()->json(['message' => 'Транзакция успешно отвязана от заказа']);
    }

    /**
     * Получить все транзакции заказа
     */
    public function getOrderTransactions($orderId)
    {
        $order = Order::findOrFail($orderId);

        // Проверяем права доступа к кассе заказа
        $userHasPermissionToCashRegister = $this->ordersRepository->userHasPermissionToCashRegister(Auth::id(), $order->cash_id);
        if (!$userHasPermissionToCashRegister) {
            return response()->json(['message' => 'У вас нет прав на эту кассу'], 403);
        }

        // Получаем транзакции через morphable связь
        $transactions = Transaction::where('source_type', \App\Models\Order::class)
            ->where('source_id', $orderId)
            ->get();

        return response()->json(['transactions' => $transactions]);
    }
}
