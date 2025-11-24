<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Repositories\TransactionsRepository;
use App\Services\CacheService;
use App\Services\TransactionService;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с транзакциями
 */
class TransactionsController extends Controller
{
    protected $itemsRepository;

    /**
     * @var TransactionService
     */
    protected $transactionService;

    /**
     * Конструктор контроллера
     *
     * @param TransactionsRepository $itemsRepository Репозиторий транзакций
     * @param TransactionService $transactionService
     */
    public function __construct(TransactionsRepository $itemsRepository, TransactionService $transactionService)
    {
        $this->itemsRepository = $itemsRepository;
        $this->transactionService = $transactionService;
    }

    /**
     * Получить список транзакций с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = $request->input('page', 1);
        $per_page = $request->input('per_page', 10);
        $cash_register_id = $request->query('cash_id');
        $date_filter_type = $request->query('date_filter_type');
        $order_id = $request->query('order_id');
        $search = $request->query('search');
        $transaction_type = $request->query('transaction_type');
        $source = $request->query('source');
        $project_id = $request->query('project_id');
        $start_date = $request->query('start_date');
        $end_date = $request->query('end_date');
        $is_debt = $request->query('is_debt');

        $items = $this->itemsRepository->getItemsWithPagination(
            $userUuid,
            $per_page,
            $page,
            $cash_register_id,
            $date_filter_type,
            $order_id,
            $search,
            $transaction_type,
            $source,
            $project_id,
            $start_date,
            $end_date,
            $is_debt
        );

        $resourceCollection = TransactionResource::collection($items);
        $response = $resourceCollection->response()->getData(true);

        if (isset($response['data'])) {
            $response['meta']['total_debt_positive'] = $items->total_debt_positive ?? 0;
            $response['meta']['total_debt_negative'] = $items->total_debt_negative ?? 0;
            $response['meta']['total_debt_balance'] = $items->total_debt_balance ?? 0;
        } else {
            $response['total_debt_positive'] = $items->total_debt_positive ?? 0;
            $response['total_debt_negative'] = $items->total_debt_negative ?? 0;
            $response['total_debt_balance'] = $items->total_debt_balance ?? 0;
        }

        return response()->json($response);
    }

    /**
     * Создать новую транзакцию
     *
     * @param StoreTransactionRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreTransactionRequest $request)
    {
        $user = $this->requireAuthenticatedUser();

        if ($request->has('is_adjustment') && $request->is_adjustment) {
            $this->authorize('adjustBalance', Transaction::class);
        }

        $cashAccessCheck = $this->checkCashRegisterAccess($request->cash_id);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        try {
            $data = [
                'type' => $request->type,
                'orig_amount' => $request->orig_amount,
                'currency_id' => $request->currency_id,
                'cash_id' => $request->cash_id,
                'category_id' => $request->category_id,
                'project_id' => $request->project_id,
                'client_id' => $request->client_id,
                'order_id' => $request->order_id,
                'source_type' => $request->source_type ?? null,
                'source_id' => $request->source_id ?? null,
                'note' => $request->note,
                'date' => $request->date ?? now(),
                'is_debt' => $request->is_debt ?? false
            ];

            $transaction = $this->transactionService->createTransaction($data, $user);

            CacheService::invalidateTransactionsCache();
            if ($request->client_id) {
                CacheService::invalidateClientsCache();
            }
            if ($request->project_id) {
                CacheService::invalidateProjectsCache();
            }
            CacheService::invalidateCashRegistersCache();

            $transaction = Transaction::with(['cashRegister', 'category', 'client', 'currency', 'user', 'project'])->findOrFail($transaction->id);
            return $this->dataResponse(new TransactionResource($transaction), 'Транзакция создана');
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка создания транзакции: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Обновить транзакцию
     *
     * @param UpdateTransactionRequest $request
     * @param int $id ID транзакции
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateTransactionRequest $request, $id)
    {
        $user = $this->requireAuthenticatedUser();
        $userUuid = $user->id;

        $transaction_exist = Transaction::findOrFail($id);

        $isAdjustmentCategory = in_array($transaction_exist->category_id, [21, 22]) ||
                                ($request->has('category_id') && in_array($request->category_id, [21, 22]));

        if ($isAdjustmentCategory) {
            $this->authorize('adjustBalance', Transaction::class);
        }

        $this->authorize('update', $transaction_exist);

        if (!$this->transactionService->canEditTransaction($transaction_exist)) {
            $message = $this->transactionService->getRestrictionMessage($transaction_exist);
            return $this->forbiddenResponse($message);
        }

        $cashAccessCheck = $this->checkCashRegisterAccess($transaction_exist->cash_id);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }
        $sourceData = $this->transactionService->determineSourceType([
            'source_type' => $request->source_type ?? null,
            'source_id' => $request->source_id ?? null,
            'order_id' => $request->order_id ?? null,
        ]);
        $updateSourceType = $sourceData['source_type'];
        $updateSourceId = $sourceData['source_id'];

        $updateData = [
            'category_id' => $request->category_id,
            'project_id' => $request->project_id,
            'client_id' => $request->client_id,
            'note' => $request->note,
            'date' => $request->date ?? now(),
            'is_debt' => $request->is_debt ?? false
        ];

        if ($request->has('orig_amount')) {
            $updateData['orig_amount'] = $request->orig_amount;
        } elseif ($request->has('amount')) {
            $updateData['orig_amount'] = $request->amount;
        }
        if ($request->has('currency_id')) {
            $updateData['currency_id'] = $request->currency_id;
        }

        if ($request->has('source_type') || $request->has('source_id') || $request->has('order_id')) {
            $updateData['source_type'] = $updateSourceType;
            $updateData['source_id'] = $updateSourceId;
        }

        $category_updated = $this->itemsRepository->updateItem($id, $updateData);

        if (!$category_updated) {
            return $this->errorResponse('Ошибка обновления транзакции', 400);
        }

        CacheService::invalidateTransactionsCache();
        if ($request->client_id || $transaction_exist->client_id) {
            CacheService::invalidateClientsCache();
        }
        if ($request->project_id || $transaction_exist->project_id) {
            CacheService::invalidateProjectsCache();
        }
        CacheService::invalidateCashRegistersCache();

        $transaction = Transaction::with(['cashRegister', 'category', 'client', 'currency', 'user', 'project'])->findOrFail($id);
        return $this->dataResponse(new TransactionResource($transaction), 'Транзакция обновлена');
    }

    /**
     * Удалить транзакцию
     *
     * @param int $id ID транзакции
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $user = $this->requireAuthenticatedUser();
        $userUuid = $user->id;

        $transaction_exist = Transaction::findOrFail($id);

        $this->authorize('delete', $transaction_exist);

        if (!$this->transactionService->canDeleteTransaction($transaction_exist)) {
            $message = $this->transactionService->getRestrictionMessage($transaction_exist);
            return $this->forbiddenResponse($message);
        }

        $transaction_deleted = $this->itemsRepository->deleteItem($id);

        if (!$transaction_deleted) {
            return $this->errorResponse('Ошибка удаления транзакции', 400);
        }

        CacheService::invalidateTransactionsCache();
        if ($transaction_exist->client_id) {
            CacheService::invalidateClientsCache();
        }
        if ($transaction_exist->project_id) {
            CacheService::invalidateProjectsCache();
        }
        CacheService::invalidateCashRegistersCache();

        return response()->json(['message' => 'Транзакция удалена']);
    }

    /**
     * Получить сумму транзакций по заказу
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTotalByOrderId(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $orderId = $request->query('order_id');
        if (!$orderId) {
            return $this->errorResponse('order_id is required', 400);
        }

        $total = $this->itemsRepository->getTotalByOrderId($userUuid, $orderId);
        return $this->dataResponse(['total' => $total]);
    }

    /**
     * Получить транзакцию по ID
     *
     * @param int $id ID транзакции
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $transaction = Transaction::findOrFail($id);

        $this->authorize('view', $transaction);

        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $item = $this->itemsRepository->getItemById($id);
        if (!$item) {
            return $this->notFoundResponse('Not found');
        }
        return $this->dataResponse(new TransactionResource($item));
    }


}
