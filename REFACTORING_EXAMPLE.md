# Пример рефакторинга контроллера

## Пример: OrderController

### ❌ ДО (текущий код)

```php
public function store(Request $request)
{
    $userUuid = $this->requireAuthenticatedUserId();
    if ($userUuid instanceof \Illuminate\Http\JsonResponse) {
        return $userUuid;
    }

    $request->validate([/* ... */]);

    // Проверка прав на кассу
    if ($request->cash_id && $request->cash_id !== null) {
        $userHasPermissionToCashRegister = $this->itemRepository->userHasPermissionToCashRegister($userUuid, $request->cash_id);
        if (!$userHasPermissionToCashRegister) {
            return response()->json(['message' => 'У вас нет прав на эту кассу'], 403);
        }
    }

    try {
        $created = $this->itemRepository->createItem($data);

        if (!$created) {
            return response()->json(['message' => 'Ошибка создания заказа'], 400);
        }

        // Инвалидация кэша
        CacheService::invalidateOrdersCache();
        CacheService::invalidateWarehouseStocksCache();
        CacheService::invalidateProductsCache();
        CacheService::invalidateClientsCache();
        if ($request->project_id) {
            CacheService::invalidateProjectsCache();
        }

        return response()->json(['message' => 'Заказ успешно создан']);
    } catch (\Throwable $th) {
        return response()->json(['message' => 'Ошибка заказа: ' . $th->getMessage()], 400);
    }
}
```

### ✅ ПОСЛЕ (улучшенный код)

```php
public function store(StoreOrderRequest $request): JsonResponse
{
    $userUuid = $this->getAuthenticatedUserIdOrFail();

    // Проверка прав на кассу (если указана)
    if ($request->filled('cash_id')) {
        $this->requireCashRegisterAccess($request->cash_id);
    }

    $data = $this->prepareOrderData($request, $userUuid);

    try {
        $order = $this->transaction(function () use ($data) {
            return $this->itemRepository->createItem($data);
        });

        // Инвалидация кэша через события (автоматически)
        // или вынести в метод репозитория

        return $this->successResponse($order, 'Заказ успешно создан', 201);
    } catch (\Throwable $e) {
        \Log::error('Order creation failed', [
            'user_id' => $userUuid,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return $this->errorResponse('Ошибка создания заказа: ' . $e->getMessage(), 400);
    }
}

/**
 * Подготовить данные заказа для создания
 */
private function prepareOrderData(Request $request, int $userId): array
{
    // Хардкод для basement пользователей
    $categoryId = $request->category_id;
    if (in_array($userId, [6, 7, 8]) && !$categoryId) {
        $basementCategoryMap = [6 => 2, 7 => 3, 8 => 14];
        $categoryId = $basementCategoryMap[$userId] ?? null;
    }

    return [
        'user_id' => $userId,
        'client_id' => $request->client_id,
        'project_id' => $request->project_id,
        'cash_id' => $request->cash_id,
        'warehouse_id' => $request->warehouse_id,
        'currency_id' => $request->currency_id,
        'category_id' => $categoryId,
        'discount' => $request->discount ?? 0,
        'discount_type' => $request->discount_type ?? 'percent',
        'description' => $request->description,
        'date' => $request->date ?? now(),
        'note' => $request->note ?? '',
        'status_id' => Order::STATUS_NEW, // Использовать константу
        'products' => $this->prepareProducts($request->products ?? []),
        'temp_products' => $this->prepareTempProducts($request->temp_products ?? []),
        'additional_fields' => $request->additional_fields ?? [],
    ];
}

/**
 * Подготовить данные продуктов
 */
private function prepareProducts(array $products): array
{
    return array_map(fn($p) => [
        'product_id' => $p['product_id'],
        'quantity' => $p['quantity'],
        'price' => $p['price'],
        'width' => $p['width'] ?? null,
        'height' => $p['height'] ?? null,
    ], $products);
}

/**
 * Подготовить данные временных продуктов
 */
private function prepareTempProducts(array $tempProducts): array
{
    return array_map(fn($p) => [
        'name' => $p['name'],
        'description' => $p['description'] ?? null,
        'quantity' => $p['quantity'],
        'price' => $p['price'],
        'unit_id' => $p['unit_id'] ?? null,
        'width' => $p['width'] ?? null,
        'height' => $p['height'] ?? null,
    ], $tempProducts);
}
```

## Создание FormRequest

### app/Http/Requests/StoreOrderRequest.php

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Или проверка прав доступа
    }

    public function rules(): array
    {
        return [
            'client_id' => 'required|integer|exists:clients,id',
            'project_id' => 'nullable|sometimes|integer|exists:projects,id',
            'cash_id' => 'nullable|integer|exists:cash_registers,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'currency_id' => 'nullable|integer|exists:currencies,id',
            'category_id' => 'required|integer|exists:categories,id',
            'discount' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:fixed,percent|required_with:discount',
            'description' => 'nullable|string',
            'date' => 'nullable|date',
            'note' => 'nullable|string',
            'status_id' => 'nullable|integer|exists:order_statuses,id',
            'products' => 'sometimes|array',
            'products.*.product_id' => 'required_with:products|integer|exists:products,id',
            'products.*.quantity' => 'required_with:products|numeric|min:0',
            'products.*.price' => 'required_with:products|numeric|min:0',
            'products.*.width' => 'nullable|numeric|min:0',
            'products.*.height' => 'nullable|numeric|min:0',
            'temp_products' => 'sometimes|array',
            'temp_products.*.name' => 'required_with:temp_products|string|max:255',
            'temp_products.*.description' => 'nullable|string',
            'temp_products.*.quantity' => 'required_with:temp_products|numeric|min:0',
            'temp_products.*.price' => 'required_with:temp_products|numeric|min:0',
            'temp_products.*.unit_id' => 'nullable|exists:units,id',
            'temp_products.*.width' => 'nullable|numeric|min:0',
            'temp_products.*.height' => 'nullable|numeric|min:0',
            'additional_fields' => 'sometimes|array',
            'additional_fields.*.field_id' => 'required_with:additional_fields|integer|exists:order_af,id',
            'additional_fields.*.value' => 'required_with:additional_fields|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'client_id.required' => 'Необходимо выбрать клиента',
            'warehouse_id.required' => 'Необходимо выбрать склад',
            'category_id.required' => 'Необходимо выбрать категорию',
        ];
    }
}
```

## Использование событий для инвалидации кэша

### app/Models/Order.php

```php
protected static function booted()
{
    static::created(function ($order) {
        event(new OrderCreated($order));
    });

    static::updated(function ($order) {
        event(new OrderUpdated($order));
    });

    static::deleted(function ($order) {
        event(new OrderDeleted($order));
    });
}
```

### app/Listeners/InvalidateOrderCache.php

```php
<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Events\OrderUpdated;
use App\Events\OrderDeleted;
use App\Services\CacheService;

class InvalidateOrderCache
{
    public function handle($event)
    {
        CacheService::invalidateOrdersCache();
        CacheService::invalidateWarehouseStocksCache();
        CacheService::invalidateProductsCache();
        CacheService::invalidateClientsCache();
        
        if ($event->order->project_id) {
            CacheService::invalidateProjectsCache();
        }
    }
}
```

## Создание констант в моделях

### app/Models/Order.php

```php
class Order extends Model
{
    // Статусы заказов
    public const STATUS_NEW = 1;
    public const STATUS_IN_PROGRESS = 2;
    public const STATUS_COMPLETED = 3;
    public const STATUS_CANCELLED = 4;
    public const STATUS_PAID = 5;
    public const STATUS_DELIVERED = 6;

    // Типы скидок
    public const DISCOUNT_TYPE_FIXED = 'fixed';
    public const DISCOUNT_TYPE_PERCENT = 'percent';
}
```

## Итоговые улучшения

### До:
- ❌ 20+ строк кода в методе store
- ❌ Дублирование проверки аутентификации
- ❌ Прямое использование response()->json()
- ❌ Ручная инвалидация кэша
- ❌ Отсутствие транзакций БД
- ❌ Магические числа (status_id => 1)

### После:
- ✅ 10-15 строк кода в методе store
- ✅ Одна строка для проверки аутентификации
- ✅ Использование методов базового контроллера
- ✅ Автоматическая инвалидация кэша через события
- ✅ Транзакции БД для целостности данных
- ✅ Константы вместо магических чисел
- ✅ Валидация вынесена в FormRequest
- ✅ Логирование ошибок
- ✅ Типизация методов


