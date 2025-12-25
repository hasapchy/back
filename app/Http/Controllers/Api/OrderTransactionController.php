<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Models\Order;
use App\Models\Transaction;
use App\Repositories\OrdersRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы со связями заказов и транзакций
 */
class OrderTransactionController extends BaseController
{
    protected $ordersRepository;

    /**
     * Конструктор контроллера
     *
     * @param OrdersRepository $ordersRepository
     */
    public function __construct(OrdersRepository $ordersRepository)
    {
        $this->ordersRepository = $ordersRepository;
    }

    /**
     * Связать транзакцию с заказом
     *
     * @param Request $request
     * @param int $orderId ID заказа
     * @return \Illuminate\Http\JsonResponse
     */
    public function linkTransaction(Request $request, $orderId)
    {
        $request->validate([
            'transaction_id' => 'required|integer|exists:transactions,id',
        ]);

        $order = Order::findOrFail($orderId);

        $userId = $this->getAuthenticatedUserIdOrFail();
        
        $cashAccessCheck = $this->checkCashRegisterAccess($order->cash_id);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
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

    /**
     * Отвязать транзакцию от заказа
     *
     * @param int $orderId ID заказа
     * @param int $transactionId ID транзакции
     * @return \Illuminate\Http\JsonResponse
     */
    public function unlinkTransaction($orderId, $transactionId)
    {
        $order = Order::findOrFail($orderId);

        $this->getAuthenticatedUserIdOrFail();
        
        $cashAccessCheck = $this->checkCashRegisterAccess($order->cash_id);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        $trx = Transaction::findOrFail($transactionId);
        if ($trx->source_type === Order::class && (int)$trx->source_id === (int)$orderId) {
            $trx->source_type = null;
            $trx->source_id = null;
            $trx->save();
        }

        return response()->json(['message' => 'Транзакция успешно отвязана от заказа']);
    }

    /**
     * Получить транзакции заказа
     *
     * @param int $orderId ID заказа
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOrderTransactions($orderId)
    {
        $order = Order::findOrFail($orderId);

        $this->getAuthenticatedUserIdOrFail();
        
        $cashAccessCheck = $this->checkCashRegisterAccess($order->cash_id);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        $transactions = Transaction::where('source_type', Order::class)
            ->where('source_id', $orderId)
            ->get();

        return response()->json(['transactions' => $transactions]);
    }
}
