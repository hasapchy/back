<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Transaction;
use App\Repositories\OrdersRepository;
use Illuminate\Http\Request;

class OrderTransactionController extends Controller
{
    protected $ordersRepository;

    public function __construct(OrdersRepository $ordersRepository)
    {
        $this->ordersRepository = $ordersRepository;
    }
    public function linkTransaction(Request $request, $orderId)
    {
        $request->validate([
            'transaction_id' => 'required|integer|exists:transactions,id',
        ]);

        $order = Order::findOrFail($orderId);

        $userId = $this->getAuthenticatedUserIdOrFail();
        if ($order->cash_id) {
            $cashRegister = \App\Models\CashRegister::find($order->cash_id);
            if ($cashRegister && !$this->canPerformAction('cash_registers', 'view', $cashRegister)) {
                return $this->forbiddenResponse('У вас нет прав на эту кассу');
            }
        }

        $transaction = Transaction::findOrFail($request->transaction_id);

        if ($transaction->user_id != $userId) {
            return $this->forbiddenResponse('Нет доступа к транзакции');
        }

        $transaction->source_type = Order::class;
        $transaction->source_id = $orderId;
        $transaction->save();

        return response()->json(['message' => 'Транзакция успешно связана с заказом']);
    }

    public function unlinkTransaction($orderId, $transactionId)
    {
        $order = Order::findOrFail($orderId);

        $userId = $this->getAuthenticatedUserIdOrFail();
        if ($order->cash_id) {
            $cashRegister = \App\Models\CashRegister::find($order->cash_id);
            if ($cashRegister && !$this->canPerformAction('cash_registers', 'view', $cashRegister)) {
                return $this->forbiddenResponse('У вас нет прав на эту кассу');
            }
        }

        $trx = Transaction::findOrFail($transactionId);
        if ($trx->source_type === Order::class && (int)$trx->source_id === (int)$orderId) {
            $trx->source_type = null;
            $trx->source_id = null;
            $trx->save();
        }

        return response()->json(['message' => 'Транзакция успешно отвязана от заказа']);
    }

    public function getOrderTransactions($orderId)
    {
        $order = Order::findOrFail($orderId);

        $userId = $this->getAuthenticatedUserIdOrFail();
        if ($order->cash_id) {
            $cashRegister = \App\Models\CashRegister::find($order->cash_id);
            if ($cashRegister && !$this->canPerformAction('cash_registers', 'view', $cashRegister)) {
                return $this->forbiddenResponse('У вас нет прав на эту кассу');
            }
        }

        $transactions = Transaction::where('source_type', Order::class)
            ->where('source_id', $orderId)
            ->get();

        return response()->json(['transactions' => $transactions]);
    }
}
