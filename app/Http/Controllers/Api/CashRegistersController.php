<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\StoreCashRegisterRequest;
use App\Http\Requests\UpdateCashRegisterRequest;
use App\Repositories\CahRegistersRepository;
use Illuminate\Http\Request;
use App\Services\CacheService;

/**
 * Контроллер для работы с кассами
 */
class CashRegistersController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     *
     * @param CahRegistersRepository $itemsRepository Репозиторий касс
     */
    public function __construct(CahRegistersRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список касс с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);
        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $page);

        return $this->paginatedResponse($items);
    }

    /**
     * Получить все кассы пользователя
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $items = $this->itemsRepository->getAllItems($userUuid);
        return response()->json($items);
    }

    /**
     * Получить баланс касс
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCashBalance(Request $request)
    {
        $user = $this->requireAuthenticatedUser();
        $userUuid = $user->id;

        if (!$this->hasPermission('settings_cash_balance_view', $user)) {
            return $this->forbiddenResponse('Нет доступа к просмотру баланса кассы');
        }

        $cashRegisterIds = $request->query('cash_register_ids', '');
        $all = empty($cashRegisterIds);

        if (!empty($cashRegisterIds)) {
            $ids = array_map('intval', explode(',', $cashRegisterIds));
            $cashRegisters = \App\Models\CashRegister::whereIn('id', $ids)->get();
            foreach ($cashRegisters as $cashRegister) {
                if (!$this->canPerformAction('cash_registers', 'view', $cashRegister)) {
                    return $this->forbiddenResponse('У вас нет прав на просмотр одной или нескольких касс');
                }
            }
            $cashRegisterIds = $ids;
        } else {
            $cashRegisterIds = [];
        }

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

    /**
     * Создать новую кассу
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreCashRegisterRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $item_created = $this->itemsRepository->createItem([
            'name' => $validatedData['name'],
            'balance' => $validatedData['balance'],
            'currency_id' => $validatedData['currency_id'] ?? null,
            'users' => $validatedData['users']
        ]);

        if (!$item_created) {
            return $this->errorResponse('Ошибка создания кассы', 400);
        }

        return response()->json(['message' => 'Касса создана']);
    }

    /**
     * Обновить кассу
     *
     * @param Request $request
     * @param int $id ID кассы
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateCashRegisterRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $cashRegister = \App\Models\CashRegister::find($id);
        if (!$cashRegister) {
            return $this->notFoundResponse('Касса не найдена');
        }

        if (!$this->canPerformAction('cash_registers', 'update', $cashRegister)) {
            return $this->forbiddenResponse('У вас нет прав на редактирование этой кассы');
        }

        $validatedData = $request->validated();

        $category_updated = $this->itemsRepository->updateItem($id, [
            'name' => $validatedData['name'],
            'users' => $validatedData['users']
        ]);

        if (!$category_updated) {
            return $this->errorResponse('Ошибка обновления кассы', 400);
        }

        return response()->json(['message' => 'Касса обновлена']);
    }

    /**
     * Удалить кассу
     *
     * @param int $id ID кассы
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $cashRegister = \App\Models\CashRegister::find($id);
            if (!$cashRegister) {
                return $this->notFoundResponse('Касса не найдена');
            }

            if (!$this->canPerformAction('cash_registers', 'delete', $cashRegister)) {
                return $this->forbiddenResponse('У вас нет прав на удаление этой кассы');
            }

            $category_deleted = $this->itemsRepository->deleteItem($id);

            if (!$category_deleted) {
                return $this->errorResponse('Ошибка удаления кассы', 400);
            }

            return response()->json(['message' => 'Касса удалена']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Касса не найдена', 404);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
