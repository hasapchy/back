<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreCashRegisterRequest;
use App\Http\Requests\UpdateCashRegisterRequest;
use App\Http\Resources\CashRegisterResource;
use App\Repositories\CashRegistersRepository;
use App\Models\CashRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с кассами
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
     * Получить список касс с пагинацией
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CashRegister::class);

        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);
        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $page);

        return $this->successResponse([
            'items' => CashRegisterResource::collection($items->items())->resolve(),
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

        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $items = $this->itemsRepository->getAllItems($userUuid);
        return $this->successResponse(CashRegisterResource::collection($items)->resolve());
    }

    /**
     * Получить баланс касс
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getCashBalance(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();

        if (! $user->can('settings_cash_balance_view')) {
            return $this->errorResponse('Нет доступа к просмотру баланса кассы', 403);
        }

        $userUuid = $user->id;

        $cashRegisterIds = $request->query('cash_register_ids', '');
        $all = empty($cashRegisterIds);

        if (!empty($cashRegisterIds)) {
            $ids = array_map('intval', explode(',', $cashRegisterIds));
            $cashRegisters = \App\Models\CashRegister::whereIn('id', $ids)->get();
            foreach ($cashRegisters as $cashRegister) {
                if (! $user->can('view', $cashRegister)) {
                    return $this->errorResponse('У вас нет прав на просмотр одной или нескольких касс', 403);
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

        return $this->successResponse(CashRegisterResource::collection($balances)->resolve());
    }

    /**
     * Создать новую кассу
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(StoreCashRegisterRequest $request): JsonResponse
    {
        $this->authorize('create', CashRegister::class);

        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $item_created = $this->itemsRepository->createItem([
            'name' => $validatedData['name'],
            'balance' => $validatedData['balance'],
            'currency_id' => $validatedData['currency_id'] ?? null,
            'users' => $validatedData['users'],
            'is_cash' => $validatedData['is_cash'] ?? true,
            'is_working_minus' => $validatedData['is_working_minus'] ?? false,
            'icon' => $validatedData['icon'] ?? null,
        ]);

        if (!$item_created) {
            return $this->errorResponse('Ошибка создания кассы', 400);
        }

        return $this->successResponse(null, 'Касса создана');
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
            'name' => $validatedData['name'],
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

        $category_updated = $this->itemsRepository->updateItem($id, $payload);

        if (!$category_updated) {
            return $this->errorResponse('Ошибка обновления кассы', 400);
        }

        return $this->successResponse(null, 'Касса обновлена');
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

            $category_deleted = $this->itemsRepository->deleteItem($id);

            if (!$category_deleted) {
                return $this->errorResponse('Ошибка удаления кассы', 400);
            }

            return $this->successResponse(null, 'Касса удалена');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Касса не найдена', 404);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
