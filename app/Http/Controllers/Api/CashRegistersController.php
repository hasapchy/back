<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\CahRegistersRepository;
use Illuminate\Http\Request;
use App\Services\CacheService;

class CashRegistersController extends Controller
{
    protected $itemsRepository;

    public function __construct(CahRegistersRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = $request->input('page', 1);
        $items = $this->itemsRepository->getItemsWithPagination($userUuid, 20, $page);

        return $this->paginatedResponse($items);
    }

    public function all(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $items = $this->itemsRepository->getAllItems($userUuid);
        return response()->json($items);
    }

    public function getCashBalance(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $cashRegisterIds = $request->query('cash_register_ids', '');
        $all = empty($cashRegisterIds);
        $cashRegisterIds = array_map('intval', explode(',', $cashRegisterIds));

        $startRaw = $request->query('start_date');
        $endRaw   = $request->query('end_date');
        $transactionType = $request->query('transaction_type');
        $source = $request->query('source');

        if ($source && is_string($source)) {
            $source = explode(',', $source);
        }


        try {
            $start = $startRaw
                ? \Carbon\Carbon::createFromFormat('d.m.Y', $startRaw)->startOfDay()
                : null;
            $end   = $endRaw
                ? \Carbon\Carbon::createFromFormat('d.m.Y', $endRaw)->endOfDay()
                : null;
        } catch (\Exception $e) {
            return $this->errorResponse('Неверный формат даты', 422);
        }

        $balances = $this->itemsRepository->getCashBalance(
            $userUuid,
            $cashRegisterIds,
            $all,
            $start,
            $end,
            $transactionType,
            $source
        );

        return response()->json($balances);
    }

    public function store(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string',
            'balance' => 'required|numeric',
            'currency_id' => 'nullable|exists:currencies,id',
            'users' => 'required|array',
            'users.*' => 'exists:users,id'
        ]);

        $item_created = $this->itemsRepository->createItem([
            'name' => $request->name,
            'balance' => $request->balance,
            'currency_id' => $request->currency_id,
            'users' => $request->users
        ]);

        if (!$item_created) {
            return $this->errorResponse('Ошибка создания кассы', 400);
        }

        CacheService::invalidateCashRegistersCache();
        return response()->json(['message' => 'Касса создана']);
    }

    public function update(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string',
            'users' => 'required|array',
            'users.*' => 'exists:users,id'
        ]);

        $category_updated = $this->itemsRepository->updateItem($id, [
            'name' => $request->name,
            'users' => $request->users
        ]);

        if (!$category_updated) {
            return $this->errorResponse('Ошибка обновления кассы', 400);
        }

        CacheService::invalidateCashRegistersCache();
        return response()->json(['message' => 'Касса обновлена']);
    }

    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $category_deleted = $this->itemsRepository->deleteItem($id);

        if (!$category_deleted) {
            return $this->errorResponse('Ошибка удаления кассы', 400);
        }

        CacheService::invalidateCashRegistersCache();
        return response()->json(['message' => 'Касса удалена']);
    }
}
