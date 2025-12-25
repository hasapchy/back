<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\StoreTransferRequest;
use App\Http\Requests\UpdateTransferRequest;
use App\Repositories\TransactionsRepository;
use App\Repositories\TransfersRepository;
use App\Services\CacheService;
use App\Models\CashRegister;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с перемещениями между кассами
 */
class TransfersController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     *
     * @param TransfersRepository $itemsRepository
     */
    public function __construct(TransfersRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список перемещений с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage);

        return $this->paginatedResponse($items);
    }

    /**
     * Создать перемещение между кассами
     *
     * @param StoreTransferRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreTransferRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $accessCheck = $this->checkCashRegistersAccess($validatedData['cash_id_from'], $validatedData['cash_id_to']);
        if ($accessCheck) {
            return $accessCheck;
        }

        $item_created = $this->itemsRepository->createItem([
            'cash_id_from' => $validatedData['cash_id_from'],
            'cash_id_to' => $validatedData['cash_id_to'],
            'amount' => $validatedData['amount'],
            'user_id' => $userUuid,
            'note' => $validatedData['note'] ?? null,
            'exchange_rate' => $validatedData['exchange_rate'] ?? null
        ]);

        if (!$item_created) {
            return $this->errorResponse('Ошибка создания трансфера', 400);
        }

        return response()->json(['message' => 'Трансфер создан']);
    }

    /**
     * Обновить перемещение между кассами
     *
     * @param UpdateTransferRequest $request
     * @param int $id ID перемещения
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateTransferRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $transfer = \App\Models\CashTransfer::findOrFail($id);

        $cashFromAccessCheck = $this->checkCashRegisterAccess($validatedData['cash_id_from']);
        if ($cashFromAccessCheck) {
            return $cashFromAccessCheck;
        }

        $cashToAccessCheck = $this->checkCashRegisterAccess($validatedData['cash_id_to']);
        if ($cashToAccessCheck) {
            return $cashToAccessCheck;
        }

        $updated = $this->itemsRepository->updateItem($id, [
            'cash_id_from' => $validatedData['cash_id_from'],
            'cash_id_to' => $validatedData['cash_id_to'],
            'amount' => $validatedData['amount'],
            'note' => $validatedData['note'] ?? null,
            'user_id' => $userUuid,
            'exchange_rate' => $validatedData['exchange_rate'] ?? null
        ]);

        if (!$updated) {
            return $this->errorResponse('Ошибка обновления', 400);
        }

        return response()->json(['message' => 'Трансфер обновлён']);
    }

    /**
     * Удалить перемещение между кассами
     *
     * @param int $id ID перемещения
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $deleted = $this->itemsRepository->deleteItem($id);

        if (!$deleted) {
            return $this->errorResponse('Ошибка удаления', 400);
        }

        return response()->json(['message' => 'Трансфер удалён']);
    }

    /**
     * Проверить доступ к двум кассам
     *
     * @param int|null $cashIdFrom ID кассы откуда
     * @param int|null $cashIdTo ID кассы куда
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function checkCashRegistersAccess(?int $cashIdFrom, ?int $cashIdTo)
    {
        $cashFromAccessCheck = $this->checkCashRegisterAccess($cashIdFrom);
        if ($cashFromAccessCheck) {
            return $cashFromAccessCheck;
        }

        return $this->checkCashRegisterAccess($cashIdTo);
    }
}
