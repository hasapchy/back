<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCashRegisterRequest;
use App\Http\Requests\UpdateCashRegisterRequest;
use App\Http\Resources\CashRegisterResource;
use App\Repositories\CahRegistersRepository;
use Illuminate\Http\Request;
use App\Models\CashRegister;

/**
 * Контроллер для работы с кассами
 */
class CashRegistersController extends Controller
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
        $items = $this->itemsRepository->getItemsWithPagination($userUuid, 20, $page);

        return CashRegisterResource::collection($items)->response();
    }

    /**
     * Получить все кассы пользователя
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
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
        return CashRegisterResource::collection($items)->response();
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

        return $this->dataResponse($balances);
    }

    /**
     * Создать новую кассу
     *
     * @param StoreCashRegisterRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreCashRegisterRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        try {
            $this->itemsRepository->createItem([
                'name' => $request->name,
                'balance' => $request->balance,
                'currency_id' => $request->currency_id,
                'users' => $request->users
            ]);

            $cashRegister = CashRegister::with('currency')
                ->where('name', $request->name)
                ->where('company_id', $this->getCurrentCompanyId())
                ->latest()
                ->firstOrFail();

            return $this->dataResponse(new CashRegisterResource($cashRegister), 'Касса создана');
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка создания кассы: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Обновить кассу
     *
     * @param UpdateCashRegisterRequest $request
     * @param int $id ID кассы
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateCashRegisterRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $cashRegister = CashRegister::find($id);
        if (!$cashRegister) {
            return $this->notFoundResponse('Касса не найдена');
        }

        if (!$this->canPerformAction('cash_registers', 'update', $cashRegister)) {
            return $this->forbiddenResponse('У вас нет прав на редактирование этой кассы');
        }

        $category_updated = $this->itemsRepository->updateItem($id, [
            'name' => $request->name,
            'users' => $request->users
        ]);

        if (!$category_updated) {
            return $this->errorResponse('Ошибка обновления кассы', 400);
        }

        $cashRegister = CashRegister::with('currency')->findOrFail($id);
        return $this->dataResponse(new CashRegisterResource($cashRegister), 'Касса обновлена');
    }

    /**
     * Удалить кассу
     *
     * @param int $id ID кассы
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $cashRegister = CashRegister::find($id);
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
    }
}
