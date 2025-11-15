<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\TransactionsRepository;
use App\Repositories\TransfersRepository;
use App\Services\CacheService;
use App\Models\CashRegister;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с перемещениями между кассами
 */
class TransfersController extends Controller
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

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, 20);

        return $this->paginatedResponse($items);
    }

    /**
     * Создать перемещение между кассами
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'cash_id_from' => 'required|exists:cash_registers,id',
            'cash_id_to' => 'required|exists:cash_registers,id',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|sometimes|string',
            'exchange_rate' => 'nullable|sometimes|numeric|min:0.000001'
        ]);

        $cashRegisterFrom = CashRegister::findOrFail($request->cash_id_from);
        $cashRegisterTo = CashRegister::findOrFail($request->cash_id_to);

        $cashFromAccessCheck = $this->checkCashRegisterAccess($request->cash_id_from);
        if ($cashFromAccessCheck) {
            return $cashFromAccessCheck;
        }

        $cashToAccessCheck = $this->checkCashRegisterAccess($request->cash_id_to);
        if ($cashToAccessCheck) {
            return $cashToAccessCheck;
        }

        $item_created = $this->itemsRepository->createItem([
            'cash_id_from' => $request->cash_id_from,
            'cash_id_to' => $request->cash_id_to,
            'amount' => $request->amount,
            'user_id' => $userUuid,
            'note' => $request->note,
            'exchange_rate' => $request->exchange_rate
        ]);

        if (!$item_created) {
            return $this->errorResponse('Ошибка создания трансфера', 400);
        }

        return response()->json(['message' => 'Трансфер создан']);
    }

    /**
     * Обновить перемещение между кассами
     *
     * @param Request $request
     * @param int $id ID перемещения
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'cash_id_from' => 'required|exists:cash_registers,id',
            'cash_id_to' => 'required|exists:cash_registers,id',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string',
            'exchange_rate' => 'nullable|sometimes|numeric|min:0.000001'
        ]);

        $transfer = \App\Models\CashTransfer::findOrFail($id);
        $cashRegisterFrom = \App\Models\CashRegister::findOrFail($request->cash_id_from);
        $cashRegisterTo = \App\Models\CashRegister::findOrFail($request->cash_id_to);

        $cashFromAccessCheck = $this->checkCashRegisterAccess($request->cash_id_from);
        if ($cashFromAccessCheck) {
            return $cashFromAccessCheck;
        }

        $cashToAccessCheck = $this->checkCashRegisterAccess($request->cash_id_to);
        if ($cashToAccessCheck) {
            return $cashToAccessCheck;
        }

        $updated = $this->itemsRepository->updateItem($id, [
            'cash_id_from' => $request->cash_id_from,
            'cash_id_to' => $request->cash_id_to,
            'amount' => $request->amount,
            'note' => $request->note,
            'user_id' => $userUuid,
            'exchange_rate' => $request->exchange_rate
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
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $deleted = $this->itemsRepository->deleteItem($id);

        if (!$deleted) {
            return $this->errorResponse('Ошибка удаления', 400);
        }

        return response()->json(['message' => 'Трансфер удалён']);
    }
}
