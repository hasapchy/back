<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\CahRegistersRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CashRegistersController extends Controller
{
    protected $itemsRepository;

    public function __construct(CahRegistersRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }
    // Метод для получения касс с пагинацией
    public function index(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        $page = $request->input('page', 1);

        // Получаем склад с пагинацией
        $items = $this->itemsRepository->getItemsWithPagination($userUuid, 20, $page);

        return response()->json([
            'items' => $items->items(),  // Список
            'current_page' => $items->currentPage(),  // Текущая страница
            'next_page' => $items->nextPageUrl(),  // Следующая страница
            'last_page' => $items->lastPage(),  // Общее количество страниц
            'total' => $items->total()  // Общее количество
        ]);
    }

    // Метод для получения всех касс
    public function all(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Получаем кассы
        $items = $this->itemsRepository->getAllItems($userUuid);

        return response()->json($items);
    }

    // Получение баланса касс
    public function getCashBalance(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $cashRegisterIds = $request->query('cash_register_ids', '');
        $all = empty($cashRegisterIds);
        $cashRegisterIds = array_map('intval', explode(',', $cashRegisterIds));

        $startRaw = $request->query('start_date');
        $endRaw   = $request->query('end_date');
        $transactionType = $request->query('transaction_type');
        $source = $request->query('source');

        // Преобразуем source из строки в массив
        if ($source && is_string($source)) {
            $source = explode(',', $source);
        }

        // Логируем параметры для отладки
        Log::info('Cash balance filter params', [
            'transaction_type' => $transactionType,
            'source' => $source,
            'start_date' => $startRaw,
            'end_date' => $endRaw
        ]);

        try {
            $start = $startRaw
                ? \Carbon\Carbon::createFromFormat('d.m.Y', $startRaw)->startOfDay()
                : null;
            $end   = $endRaw
                ? \Carbon\Carbon::createFromFormat('d.m.Y', $endRaw)->endOfDay()
                : null;
        } catch (\Exception $e) {
            return response()->json(['message' => 'Неверный формат даты'], 422);
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

    // Метод для создания кассы
    public function store(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Валидация данных
        $request->validate([
            'name' => 'required|string',
            'balance' => 'required|numeric',
            'is_rounding' => 'boolean',
            'currency_id' => 'nullable|exists:currencies,id',
            'users' => 'required|array',
            'users.*' => 'exists:users,id'
        ]);

        // Создаем категорию
        $item_created = $this->itemsRepository->createItem([
            'name' => $request->name,
            'balance' => $request->balance,
            'is_rounding' => $request->boolean('is_rounding', false),
            'currency_id' => $request->currency_id,
            'users' => $request->users
        ]);

        if (!$item_created) {
            return response()->json([
                'message' => 'Ошибка создания кассы'
            ], 400);
        }

        // Инвалидируем кэш касс
        \App\Services\CacheService::invalidateCashRegistersCache();

        return response()->json([
            'message' => 'Касса создана'
        ]);
    }

    // Метод для обновления кассы
    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Валидация данных
        $request->validate([
            'name' => 'required|string',
            'is_rounding' => 'boolean',
            // 'balance' => 'required|numeric',
            // 'currency_id' => 'nullable|exists:currencies,id',
            'users' => 'required|array',
            'users.*' => 'exists:users,id'
        ]);

        // Обновляем категорию
        $category_updated = $this->itemsRepository->updateItem($id, [
            'name' => $request->name,
            'is_rounding' => $request->boolean('is_rounding', false),
            // 'balance' => $request->balance,
            // 'currency_id' => $request->currency_id,
            'users' => $request->users
        ]);

        if (!$category_updated) {
            return response()->json([
                'message' => 'Ошибка обновления кассы'
            ], 400);
        }

        // Инвалидируем кэш касс
        \App\Services\CacheService::invalidateCashRegistersCache();

        return response()->json([
            'message' => 'Касса обновлена'
        ]);
    }

    // Метод для удаления кассы
    public function destroy($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Удаляем кассу
        $category_deleted = $this->itemsRepository->deleteItem($id);

        if (!$category_deleted) {
            return response()->json([
                'message' => 'Ошибка удаления кассы'
            ], 400);
        }

        // Инвалидируем кэш касс
        \App\Services\CacheService::invalidateCashRegistersCache();

        return response()->json([
            'message' => 'Касса удалена'
        ]);
    }
}
