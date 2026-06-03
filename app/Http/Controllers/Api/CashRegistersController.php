<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreCashRegisterRequest;
use App\Http\Requests\UpdateCashRegisterRequest;
use App\Http\Resources\CashRegisterReferenceResource;
use App\Http\Resources\CashRegisterResource;
use App\Repositories\CashRegistersRepository;
use App\Models\CashRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с кассами
 *
 * @group Финансы
 * @subgroup Кассы
 */
class CashRegistersController extends BaseController
{
    protected $itemsRepository;

    /**
     * @param CashRegistersRepository $itemsRepository Репозиторий касс
     */
    public function __construct(CashRegistersRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Список касс
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CashRegister::class);

        $this->getAuthenticatedUserIdOrFail();

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);
        $items = $this->itemsRepository->getItemsWithPagination($perPage, $page);
        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse([
            'items' => $this->wave1IndexCollection(
                $items->items(),
                CashRegisterReferenceResource::class,
                CashRegisterResource::class,
                $companyId
            ),
            'meta' => [
                'current_page' => $items->currentPage(),
                'next_page' => $items->nextPageUrl(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * Получить все кассы пользователя
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function all(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CashRegister::class);

        $this->getAuthenticatedUserIdOrFail();
        $items = $this->itemsRepository->getAllItems();
        $useReference = $this->useReferenceContractsForWave1All($this->getCurrentCompanyId());
        $collection = $useReference
            ? CashRegisterReferenceResource::collection($items)
            : CashRegisterResource::collection($items);

        return $this->successResponse($collection->resolve());
    }

    /**
     * Получить баланс касс
     *
     * Если параметр `cash_register_ids` не передан, вернётся баланс всех доступных касс.
     * Если передан один ID, вернётся баланс одной кассы.
     * Если передано несколько ID через запятую, вернётся баланс указанных касс.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getCashBalance(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();

        if (! $user->can('settings_cash_balance_view')) {
            return $this->errorResponse(__('Нет доступа к просмотру баланса кассы'), 403);
        }

        $cashRegisterIds = $request->query('cash_register_ids', '');
        $all = empty($cashRegisterIds);

        if (!empty($cashRegisterIds)) {
            $ids = array_map('intval', explode(',', $cashRegisterIds));
            $cashRegisters = \App\Models\CashRegister::whereIn('id', $ids)->get();
            foreach ($cashRegisters as $cashRegister) {
                if (! $user->can('view', $cashRegister)) {
                    return $this->errorResponse(__('У вас нет прав на просмотр одной или нескольких касс'), 403);
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
            return $this->errorResponse(__('Неверный формат даты'), 422);
        }

        $balances = $this->itemsRepository->getCashBalance(
            $cashRegisterIds,
            $all,
            $start,
            $end,
            $transactionType,
            $source
        );

        return $this->successResponse(CashRegisterResource::collection($balances)->resolve());
    }

    /**
     * Создать кассу
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(StoreCashRegisterRequest $request): JsonResponse
    {
        $this->authorize('create', CashRegister::class);

        $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $cashRegisterCreated = $this->itemsRepository->createItem([
            'name' => $validatedData['name'] ?? null,
            'balance' => $validatedData['balance'],
            'currency_id' => $validatedData['currency_id'] ?? null,
            'users' => $validatedData['users'],
            'is_cash' => $validatedData['is_cash'] ?? true,
            'is_working_minus' => $validatedData['is_working_minus'] ?? false,
            'sort_order' => $validatedData['sort_order'],
            'icon' => $validatedData['icon'] ?? null,
            'icon_size' => $validatedData['icon_size'],
            'color' => $validatedData['color'] ?? null,
        ]);

        if (! $cashRegisterCreated) {
            return $this->errorResponse(__('Ошибка создания кассы'), 400);
        }

        return $this->successResponse(null, __('Касса создана'));
    }

    /**
     * Обновить кассу
     *
     * @param Request $request
     * @param int $id ID кассы
     * @return JsonResponse
     */
    public function update(UpdateCashRegisterRequest $request, $id): JsonResponse
    {
        $this->getAuthenticatedUserIdOrFail();

        $cashRegister = \App\Models\CashRegister::findOrFail($id);

        $this->authorize('update', $cashRegister);

        $validatedData = $request->validated();

        $payload = [
            'name' => $validatedData['name'] ?? null,
            'users' => $validatedData['users'],
        ];

        if (array_key_exists('is_cash', $validatedData)) {
            $payload['is_cash'] = $validatedData['is_cash'];
        }

        if (array_key_exists('is_working_minus', $validatedData)) {
            $payload['is_working_minus'] = $validatedData['is_working_minus'];
        }

        if (array_key_exists('icon', $validatedData)) {
            $payload['icon'] = $validatedData['icon'];
        }

        if (array_key_exists('icon_size', $validatedData)) {
            $payload['icon_size'] = $validatedData['icon_size'];
        }

        if (array_key_exists('sort_order', $validatedData)) {
            $payload['sort_order'] = $validatedData['sort_order'];
        }

        if (array_key_exists('color', $validatedData)) {
            $payload['color'] = $validatedData['color'];
        }

        $cashRegisterUpdated = $this->itemsRepository->updateItem($id, $payload);

        if (! $cashRegisterUpdated) {
            return $this->errorResponse(__('Ошибка обновления кассы'), 400);
        }

        return $this->successResponse(null, __('Касса обновлена'));
    }

    /**
     * Удалить кассу
     *
     * @param int $id ID кассы
     * @return JsonResponse
     */
    public function destroy($id): JsonResponse
    {
        try {
            $cashRegister = CashRegister::findOrFail($id);

            $this->authorize('delete', $cashRegister);

            $cashRegisterDeleted = $this->itemsRepository->deleteItem($id);

            if (! $cashRegisterDeleted) {
                return $this->errorResponse(__('Ошибка удаления кассы'), 400);
            }

            return $this->successResponse(null, __('Касса удалена'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(__('Касса не найдена'), 404);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
