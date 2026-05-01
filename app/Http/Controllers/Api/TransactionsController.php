<?php

namespace App\Http\Controllers\Api;

use App\Batch\BatchEntityActions;
use App\Exports\GenericExport;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Order;
use App\Models\ProjectContract;
use App\Models\Transaction;
use App\Repositories\TransactionsRepository;
use App\Services\CacheService;
use App\Services\InAppNotifications\InAppNotificationDispatcher;
use App\Services\TransactionDeleteConstraints;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Контроллер для работы с транзакциями
 */
class TransactionsController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     *
     * @param  TransactionsRepository  $itemsRepository  Репозиторий транзакций
     */
    public function __construct(
        TransactionsRepository $itemsRepository,
        private readonly InAppNotificationDispatcher $inAppNotificationDispatcher,
        private readonly TransactionDeleteConstraints $transactionDeleteConstraints,
        private readonly BatchEntityActions $batchEntityActions,
    ) {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список транзакций с пагинацией
     *
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        $params = $this->getTransactionListParams($request);

        $items = $this->itemsRepository->getItemsWithPagination(
            $params['user_uuid'],
            $params['per_page'],
            $params['page'],
            $params['cash_id'],
            $params['date_filter_type'],
            $params['order_id'],
            $params['search'],
            $params['transaction_type'],
            $params['source'],
            $params['project_id'],
            $params['start_date'],
            $params['end_date'],
            $params['is_debt'],
            $params['category_ids'],
            $params['contract_id'],
            $params['warehouse_receipt_id']
        );

        $response = [
            'items' => TransactionResource::collection($items->items())->resolve(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'next_page' => $items->nextPageUrl(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
            'total_debt_positive' => $items->total_debt_positive ?? 0,
            'total_debt_negative' => $items->total_debt_negative ?? 0,
            'total_debt_balance' => $items->total_debt_balance ?? 0,
        ];

        return $this->successResponse($response);
    }

    /**
     * Экспорт транзакций в Excel (по фильтру или по выбранным id).
     */
    public function export(Request $request): BinaryFileResponse
    {
        $params = $this->getTransactionListParams($request);
        $ids = $request->input('ids', []);
        if (! is_array($ids)) {
            $ids = $ids ? [$ids] : [];
        }
        $ids = array_filter(array_map('intval', $ids));

        $items = $this->itemsRepository->getItemsForExport(
            $params['user_uuid'],
            $params['cash_id'],
            $params['date_filter_type'],
            $params['order_id'],
            $params['search'],
            $params['transaction_type'],
            $params['source'],
            $params['project_id'],
            $params['start_date'],
            $params['end_date'],
            $params['is_debt'],
            $params['category_ids'],
            $params['contract_id'],
            $params['warehouse_receipt_id'],
            $ids ?: null,
            10000
        );
        $headings = ['№', 'Дата', 'Тип', 'Сумма', 'Клиент', 'Категория', 'Касса', 'Примечание'];
        $rows = array_map(function ($t) {
            $clientName = $t->client
                ? trim(($t->client['first_name'] ?? '').' '.($t->client['last_name'] ?? ''))
                : '';

            return [
                $t->id,
                $t->date ? (is_string($t->date) ? $t->date : $t->date->format('Y-m-d H:i')) : '',
                $t->type === 1 ? 'Приход' : 'Расход',
                (float) ($t->cash_amount ?? 0),
                $clientName,
                $t->category_name ?? '',
                $t->cash_name ?? '',
                $t->note ?? '',
            ];
        }, $items);
        $filename = 'transactions_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new GenericExport($rows, $headings), $filename, \Maatwebsite\Excel\Excel::XLSX);
    }

    /**
     * Параметры списка транзакций из Request
     */
    protected function getTransactionListParams(Request $request): array
    {
        $category_ids = $request->query('category_ids');
        if ($category_ids) {
            if (is_string($category_ids)) {
                $category_ids = explode(',', $category_ids);
            }
            $category_ids = array_filter(array_map('intval', (array) $category_ids));
            $category_ids = ! empty($category_ids) ? $category_ids : null;
        } else {
            $category_ids = null;
        }

        return [
            'user_uuid' => $this->getAuthenticatedUserIdOrFail(),
            'page' => (int) $request->input('page', 1),
            'per_page' => (int) $request->input('per_page', 20),
            'cash_id' => $request->query('cash_id'),
            'date_filter_type' => $request->query('date_filter_type'),
            'order_id' => $request->query('order_id'),
            'contract_id' => $request->query('contract_id'),
            'warehouse_receipt_id' => $request->query('warehouse_receipt_id'),
            'search' => $request->query('search'),
            'transaction_type' => $request->query('transaction_type'),
            'source' => $request->query('source'),
            'project_id' => $request->query('project_id'),
            'start_date' => $request->query('start_date'),
            'end_date' => $request->query('end_date'),
            'is_debt' => $request->query('is_debt'),
            'category_ids' => $category_ids,
        ];
    }

    /**
     * Создать новую транзакцию
     *
     * @return JsonResponse
     */
    public function store(StoreTransactionRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $validatedData = $request->validated();

        if (isset($validatedData['is_adjustment']) && $validatedData['is_adjustment']) {
            if (! $this->requireAuthenticatedUser()->can('settings_client_balance_adjustment')) {
                return $this->errorResponse('У вас нет прав на корректировку баланса', 403);
            }
        }

        $cashAccessCheck = $this->checkCashRegisterAccess($validatedData['cash_id']);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        $sourceType = null;
        $sourceId = null;

        if (isset($validatedData['source_type']) || isset($validatedData['source_id'])) {
            $sourceType = $validatedData['source_type'] ?? null;
            $sourceId = $validatedData['source_id'] ?? null;
        } elseif (isset($validatedData['order_id']) && $validatedData['order_id']) {
            $sourceType = Order::class;
            $sourceId = $validatedData['order_id'];
        }

        [$clientId, $categoryId] = $this->resolveClientAndCategoryFromSource(
            $sourceType,
            $sourceId,
            $validatedData['client_id'] ?? null,
            $validatedData['category_id']
        );

        try {
            $transactionId = $this->itemsRepository->createItem([
                'type' => $validatedData['type'],
                'creator_id' => $userUuid,
                'orig_amount' => $validatedData['orig_amount'],
                'currency_id' => $validatedData['currency_id'],
                'cash_id' => $validatedData['cash_id'],
                'category_id' => $categoryId,
                'project_id' => $validatedData['project_id'] ?? null,
                'client_id' => $clientId,
                'client_balance_id' => $validatedData['client_balance_id'] ?? null,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'note' => $validatedData['note'] ?? null,
                'date' => $validatedData['date'] ?? now(),
                'is_debt' => $validatedData['is_debt'] ?? false,
                'exchange_rate' => $validatedData['exchange_rate'] ?? null,
            ], true);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        if (! $transactionId) {
            return $this->errorResponse('Ошибка создания транзакции', 400);
        }

        CacheService::invalidateTransactionsCache();
        if ($clientId) {
            CacheService::invalidateClientsCache();
        }
        if (isset($validatedData['project_id']) && $validatedData['project_id']) {
            CacheService::invalidateProjectsCache();
        }
        CacheService::invalidateCashRegistersCache();

        if (empty($validatedData['is_adjustment'])) {
            $companyId = (int) $this->getCurrentCompanyId();
            if ($companyId >= 1) {
                $this->inAppNotificationDispatcher->dispatch(
                    $companyId,
                    'transactions_new',
                    $this->getAuthenticatedUserIdOrFail(),
                    'Новая транзакция #'.$transactionId,
                    null,
                    ['route' => '/transactions/'.$transactionId, 'transaction_id' => $transactionId]
                );
            }
        }

        return $this->successResponse(null, 'Транзакция создана');
    }

    /**
     * Обновить транзакцию
     *
     * @param  int  $id  ID транзакции
     * @return JsonResponse
     */
    public function update(UpdateTransactionRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $validatedData = $request->validated();

        $transaction_exist = Transaction::findOrFail($id);

        $isAdjustmentCategory = in_array($transaction_exist->category_id, [21, 22]) ||
            (isset($validatedData['category_id']) && in_array($validatedData['category_id'], [21, 22]));

        if ($isAdjustmentCategory) {
            if (! $this->requireAuthenticatedUser()->can('settings_client_balance_adjustment')) {
                return $this->errorResponse('У вас нет прав на корректировку баланса', 403);
            }
        }

        $this->authorize('update', $transaction_exist);

        $restrictionMessage = $this->transactionDeleteConstraints->editRestrictionMessage(
            $this->getAuthenticatedUser(),
            $transaction_exist,
        );
        if ($restrictionMessage !== null) {
            return $this->errorResponse($restrictionMessage, 403);
        }

        $cashAccessCheck = $this->checkCashRegisterAccess($transaction_exist->cash_id);
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }
        $updateSourceType = null;
        $updateSourceId = null;

        if (isset($validatedData['source_type']) || isset($validatedData['source_id'])) {
            $updateSourceType = $validatedData['source_type'] ?? null;
            $updateSourceId = $validatedData['source_id'] ?? null;
        } elseif ($request->has('order_id')) {
            if ($request->order_id) {
                $updateSourceType = Order::class;
                $updateSourceId = $request->order_id;
            } else {
                $updateSourceType = null;
                $updateSourceId = null;
            }
        }

        [$updateClientId, $updateCategoryId] = $this->resolveClientAndCategoryFromSource(
            $updateSourceType,
            $updateSourceId,
            $validatedData['client_id'] ?? null,
            $validatedData['category_id']
        );

        $updateData = [
            'category_id' => $updateCategoryId,
            'project_id' => $validatedData['project_id'] ?? null,
            'client_id' => $updateClientId,
            'note' => $validatedData['note'] ?? null,
            'date' => $validatedData['date'] ?? now(),
            'is_debt' => $validatedData['is_debt'] ?? false,
        ];

        if (isset($validatedData['orig_amount'])) {
            $updateData['orig_amount'] = $validatedData['orig_amount'];
        } elseif ($request->has('amount')) {
            $updateData['orig_amount'] = $request->amount;
        }
        if (isset($validatedData['currency_id'])) {
            $updateData['currency_id'] = $validatedData['currency_id'];
        }

        if (isset($validatedData['exchange_rate'])) {
            $updateData['exchange_rate'] = $validatedData['exchange_rate'];
        }

        if (isset($validatedData['source_type']) || isset($validatedData['source_id']) || $request->has('order_id')) {
            $updateData['source_type'] = $updateSourceType;
            $updateData['source_id'] = $updateSourceId;
        }

        try {
            $category_updated = $this->itemsRepository->updateItem($id, $updateData);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        if (! $category_updated) {
            return $this->errorResponse('Ошибка обновления транзакции', 400);
        }

        CacheService::invalidateTransactionsCache();
        if ($updateClientId || $transaction_exist->client_id) {
            CacheService::invalidateClientsCache();
        }
        if ((isset($validatedData['project_id']) && $validatedData['project_id']) || $transaction_exist->project_id) {
            CacheService::invalidateProjectsCache();
        }
        CacheService::invalidateCashRegistersCache();

        return $this->successResponse(null, 'Транзакция обновлена');
    }

    /**
     * Удалить транзакцию
     *
     * @param  int  $id  ID транзакции
     * @return JsonResponse
     */
    public function destroy($id)
    {
        $this->getAuthenticatedUserIdOrFail();

        try {
            $this->batchEntityActions->deleteTransaction($this->requireAuthenticatedUser(), (int) $id);
        } catch (NotFoundHttpException $e) {
            return $this->errorResponse($e->getMessage(), 404);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Not found', 404);
        } catch (AccessDeniedHttpException $e) {
            return $this->errorResponse($e->getMessage(), 403);
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage() ?: 'Ошибка удаления транзакции', 400);
        }

        return $this->successResponse(null, 'Транзакция удалена');
    }

    /**
     * Получить сумму транзакций по заказу
     *
     * @return JsonResponse
     */
    public function getTotalByOrderId(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $orderId = $request->query('order_id');
        if (! $orderId) {
            return $this->errorResponse('order_id is required', 400);
        }

        $total = $this->itemsRepository->getTotalByOrderId($userUuid, $orderId);

        return $this->successResponse([
            'total' => (float) $total,
        ]);
    }

    /**
     * Получить транзакцию по ID
     *
     * @param  int  $id  ID транзакции
     * @return JsonResponse
     */
    public function show($id)
    {
        $this->getAuthenticatedUserIdOrFail();

        $transaction = Transaction::findOrFail($id);

        $this->authorize('view', $transaction);

        $item = $this->itemsRepository->getItemById($id);
        if (! $item) {
            return $this->errorResponse('Not found', 404);
        }

        return $this->successResponse(new TransactionResource($item));
    }

    /**
     * @return array{0: int|null, 1: int}
     */
    private function resolveClientAndCategoryFromSource(?string $sourceType, $sourceId, ?int $clientId, int $categoryId): array
    {
        if ($sourceType !== ProjectContract::class || ! $sourceId) {
            return [$clientId, $categoryId];
        }
        $contract = ProjectContract::with('project')->find($sourceId);

        return [$contract?->project?->client_id ?? $clientId, 30];
    }
}
