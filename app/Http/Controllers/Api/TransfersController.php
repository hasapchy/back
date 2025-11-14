<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\TransactionsRepository;
use App\Repositories\TransfersRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

class TransfersController extends Controller
{
    protected $itemsRepository;

    public function __construct(TransfersRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, 20);

        return $this->paginatedResponse($items);
    }

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

        $cashRegisterFrom = \App\Models\CashRegister::find($request->cash_id_from);
        $cashRegisterTo = \App\Models\CashRegister::find($request->cash_id_to);

        if (!$cashRegisterFrom) {
            return $this->notFoundResponse('Касса-отправитель не найдена');
        }
        if (!$cashRegisterTo) {
            return $this->notFoundResponse('Касса-получатель не найдена');
        }

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

        CacheService::invalidateTransfersCache();
        CacheService::invalidateCashRegistersCache();

        return response()->json(['message' => 'Трансфер создан']);
    }

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

        $transfer = \App\Models\CashTransfer::find($id);
        if (!$transfer) {
            return $this->notFoundResponse('Трансфер не найден');
        }

        $cashRegisterFrom = \App\Models\CashRegister::find($request->cash_id_from);
        $cashRegisterTo = \App\Models\CashRegister::find($request->cash_id_to);

        if (!$cashRegisterFrom) {
            return $this->notFoundResponse('Касса-отправитель не найдена');
        }
        if (!$cashRegisterTo) {
            return $this->notFoundResponse('Касса-получатель не найдена');
        }

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

        CacheService::invalidateTransfersCache();
        CacheService::invalidateCashRegistersCache();

        return response()->json(['message' => 'Трансфер обновлён']);
    }


    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $deleted = $this->itemsRepository->deleteItem($id);

        if (!$deleted) {
                return $this->errorResponse('Ошибка удаления', 400);
        }

        CacheService::invalidateTransfersCache();
        CacheService::invalidateCashRegistersCache();

        return response()->json(['message' => 'Трансфер удалён']);
    }
}
