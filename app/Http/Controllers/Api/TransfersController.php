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

        $transactions_repository = new TransactionsRepository();

        if (!$transactions_repository->userHasPermissionToCashRegister($userUuid, $request->cash_id_from) ||
            !$transactions_repository->userHasPermissionToCashRegister($userUuid, $request->cash_id_to)) {
            return $this->forbiddenResponse('У вас нет прав на одну или несколько касс');
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

        $transactions_repository = new TransactionsRepository();

        if (
            !$transactions_repository->userHasPermissionToCashRegister($userUuid, $request->cash_id_from) ||
            !$transactions_repository->userHasPermissionToCashRegister($userUuid, $request->cash_id_to)
        ) {
            return $this->forbiddenResponse('Нет прав на кассы');
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
