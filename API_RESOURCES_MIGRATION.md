# План миграции на API Resources в Laravel

## Содержание
1. [Обзор](#обзор)
2. [Методы Laravel Resources](#методы-laravel-resources)
3. [Структура миграции](#структура-миграции)
4. [Примеры кода](#примеры-кода)
5. [Чеклист задач](#чеклист-задач)

---

## Обзор

### Цель
Миграция всех API endpoints на использование Laravel API Resources для:
- Единообразной структуры ответов
- Контроля над данными в ответах
- Упрощения поддержки и рефакторинга
- Условных полей на основе прав доступа

### Текущее состояние
- 31 контроллер возвращает данные через `response()->json()` и `paginatedResponse()`
- Данные возвращаются напрямую из моделей
- Форматирование данных происходит на фронтенде через DTO

### Целевое состояние
- Все ответы через API Resources
- Единообразная структура ответов
- Форматирование данных на бэкенде
- Условные поля на основе прав доступа

---

## Методы Laravel Resources

### Основные методы JsonResource

#### `toArray($request)`
Основной метод для определения структуры ответа:
```php
public function toArray($request)
{
    return [
        'id' => $this->id,
        'name' => $this->name,
    ];
}
```

#### `with($request)`
Добавление метаданных в ответ:
```php
public function with($request)
{
    return [
        'meta' => [
            'version' => '1.0',
        ],
    ];
}
```

#### `additional(array $data)`
Добавление дополнительных данных:
```php
return (new UserResource($user))->additional([
    'permissions' => $permissions,
]);
```

### Условные методы

#### `when($condition, $value, $default = null)`
Условное включение поля:
```php
'purchase_price' => $this->when(
    $request->user()->hasPermission('products_view_purchase_price'),
    $this->purchase_price
),
```

#### `whenLoaded($relationship)`
Включение связи только если она загружена:
```php
'category' => new CategoryResource($this->whenLoaded('category')),
'prices' => ProductPriceResource::collection($this->whenLoaded('prices')),
```

#### `mergeWhen($condition, $values)`
Условное слияние массива полей:
```php
$this->mergeWhen($request->user()->isAdmin(), [
    'internal_notes' => $this->internal_notes,
    'created_by' => $this->user_id,
]),
```

### Работа с коллекциями

#### `Resource::collection($collection)`
Для простых коллекций:
```php
return ProductResource::collection($products);
```

#### `ResourceCollection`
Для кастомных коллекций с дополнительной логикой:
```php
class ProductCollection extends ResourceCollection
{
    public function toArray($request)
    {
        return [
            'data' => $this->collection,
            'meta' => [
                'total' => $this->collection->count(),
            ],
        ];
    }
}
```

### Пагинация

#### С ResourceCollection
```php
$products = Product::paginate(20);
return new ProductCollection($products);
```

#### С дополнительными метаданными
```php
return ProductResource::collection($products)->additional([
    'meta' => [
        'current_page' => $products->currentPage(),
        'last_page' => $products->lastPage(),
        'total' => $products->total(),
    ],
]);
```

---

## Структура миграции

### Этап 1: Подготовка (Задачи 1-4)

#### 1.1. Создать структуру папок
```
app/Http/Resources/
├── BaseResource.php
├── BaseResourceCollection.php
├── CompanyResource.php
├── ProductResource.php
├── ClientResource.php
└── ...
```

#### 1.2. Создать BaseResource
Базовый класс с общими методами форматирования:
- `formatDate()`
- `formatDateTime()`
- `assetUrl()`
- `formatCurrency()`

#### 1.3. Создать BaseResourceCollection
Базовый класс для пагинации с единообразной структурой.

#### 1.4. Обновить Controller::paginatedResponse()
Метод должен поддерживать как старый формат, так и Resources.

### Этап 2: Создание Resources (Задачи 5-29)

Создать Resources для всех основных моделей:
- CompanyResource
- ProductResource
- ClientResource
- OrderResource
- InvoiceResource
- ProjectResource
- TransactionResource
- WarehouseResource
- CashRegisterResource
- CategoryResource
- UserResource
- RoleResource
- OrderStatusResource
- ProjectStatusResource
- TransactionCategoryResource
- SaleResource
- TransferResource
- WarehouseReceiptResource
- WarehouseWriteoffResource
- WarehouseMovementResource
- WarehouseStockResource
- ProjectContractResource
- CommentResource
- CurrencyHistoryResource
- CompanyRoundingRuleResource

### Этап 3: Обновление контроллеров (Задачи 30-57)

Обновить все 31 контроллер для использования Resources:
- CompaniesController
- ProductController
- ClientController
- OrderController
- InvoiceController
- ProjectsController
- TransactionsController
- WarehouseController
- CashRegistersController
- CategoriesController
- UsersController
- RolesController
- OrderStatusController
- ProjectStatusController
- TransactionCategoryController
- SaleController
- TransfersController
- WarehouseReceiptController
- WarehouseWriteoffController
- WarehouseMovementController
- WarehouseStockController
- ProjectContractsController
- CommentController
- CurrencyHistoryController
- CompanyRoundingRulesController
- AuthController
- AppController
- UserCompanyController
- OrderTransactionController
- OrderStatusCategoryController
- CacheController

### Этап 4: Оптимизация (Задачи 58-61)

#### 4.1. Вложенные Resources
Создать Resources для связанных моделей:
- OrderProductResource
- InvoiceProductResource
- SalesProductResource
- WhReceiptProductResource
- WhWriteoffProductResource
- WhMovementProductResource

#### 4.2. Условные поля
Добавить условные поля на основе прав доступа:
```php
'purchase_price' => $this->when(
    $request->user()->hasPermission('products_view_purchase_price'),
    $this->purchase_price
),
```

#### 4.3. Eager Loading
Обновить репозитории для загрузки необходимых связей:
```php
$products = Product::with(['categories', 'unit', 'prices'])->get();
```

### Этап 5: Тестирование и финализация (Задачи 62-65)

#### 5.1. Тестирование
- Протестировать все API endpoints
- Проверить структуру ответов
- Проверить условные поля
- Проверить пагинацию

#### 5.2. Обновление фронтенда
- Обновить DTO если структура изменилась
- Проверить совместимость

#### 5.3. Документация
- Обновить Swagger/OpenAPI документацию

#### 5.4. Рефакторинг
- Удалить дублирующуюся логику форматирования из контроллеров

---

## Примеры кода

### BaseResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

abstract class BaseResource extends JsonResource
{
    /**
     * Форматировать дату
     *
     * @param string|null $date
     * @return string|null
     */
    protected function formatDate($date)
    {
        if (!$date) {
            return null;
        }
        
        return is_string($date) 
            ? date('Y-m-d', strtotime($date))
            : $date->format('Y-m-d');
    }

    /**
     * Форматировать дату и время
     *
     * @param string|null $datetime
     * @return string|null
     */
    protected function formatDateTime($datetime)
    {
        if (!$datetime) {
            return null;
        }
        
        return is_string($datetime)
            ? date('Y-m-d H:i:s', strtotime($datetime))
            : $datetime->format('Y-m-d H:i:s');
    }

    /**
     * Получить URL для asset
     *
     * @param string|null $path
     * @return string|null
     */
    protected function assetUrl($path)
    {
        if (!$path) {
            return null;
        }
        
        return asset("storage/{$path}");
    }

    /**
     * Форматировать валюту
     *
     * @param float|null $amount
     * @param int $decimals
     * @return float|null
     */
    protected function formatCurrency($amount, $decimals = 2)
    {
        if ($amount === null) {
            return null;
        }
        
        return round($amount, $decimals);
    }
}
```

### BaseResourceCollection.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class BaseResourceCollection extends ResourceCollection
{
    /**
     * Преобразовать коллекцию ресурса в массив
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'items' => $this->collection,
            'current_page' => $this->resource->currentPage(),
            'next_page' => $this->resource->nextPageUrl(),
            'last_page' => $this->resource->lastPage(),
            'total' => $this->resource->total(),
        ];
    }
}
```

### CompanyResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class CompanyResource extends BaseResource
{
    /**
     * Преобразовать ресурс в массив
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'logo' => $this->logo,
            'logo_url' => $this->assetUrl($this->logo),
            'show_deleted_transactions' => (bool) $this->show_deleted_transactions,
            'rounding_decimals' => $this->rounding_decimals,
            'rounding_enabled' => (bool) $this->rounding_enabled,
            'rounding_direction' => $this->rounding_direction,
            'rounding_custom_threshold' => $this->rounding_custom_threshold,
            'rounding_quantity_decimals' => $this->rounding_quantity_decimals,
            'rounding_quantity_enabled' => (bool) $this->rounding_quantity_enabled,
            'rounding_quantity_direction' => $this->rounding_quantity_direction,
            'rounding_quantity_custom_threshold' => $this->rounding_quantity_custom_threshold,
            'skip_project_order_balance' => (bool) $this->skip_project_order_balance,
            'created_at' => $this->formatDateTime($this->created_at),
            'updated_at' => $this->formatDateTime($this->updated_at),
        ];
    }
}
```

### ProductResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ProductResource extends BaseResource
{
    /**
     * Преобразовать ресурс в массив
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'type' => $this->type,
            'is_serialized' => (bool) $this->is_serialized,
            'image' => $this->image,
            'image_url' => $this->assetUrl($this->image),
            'category_name' => $this->category_name ?? $this->whenLoaded('categories', function () {
                return $this->categories->first()?->name;
            }),
            'unit_id' => $this->unit_id,
            'unit_name' => $this->unit_name ?? $this->whenLoaded('unit', function () {
                return $this->unit?->name;
            }),
            'unit_short_name' => $this->unit_short_name ?? $this->whenLoaded('unit', function () {
                return $this->unit?->short_name;
            }),
            'retail_price' => $this->formatCurrency($this->retail_price),
            'wholesale_price' => $this->formatCurrency($this->wholesale_price),
            'purchase_price' => $this->when(
                $request->user() && $request->user()->hasPermission('products_view_purchase_price'),
                $this->formatCurrency($this->purchase_price)
            ),
            'stock_quantity' => $this->stock_quantity ?? 0,
            'date' => $this->formatDate($this->date),
            'created_at' => $this->formatDateTime($this->created_at),
            'updated_at' => $this->formatDateTime($this->updated_at),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'unit' => new UnitResource($this->whenLoaded('unit')),
        ];
    }
}
```

### Обновление Controller::paginatedResponse()

```php
protected function paginatedResponse($items)
{
    // Если это уже ResourceCollection, вернуть как есть
    if ($items instanceof ResourceCollection) {
        return $items->response();
    }
    
    // Если это пагинированная коллекция с Resources
    if (method_exists($items, 'getCollection') && 
        $items->getCollection()->first() instanceof JsonResource) {
        return response()->json([
            'items' => $items->items(),
            'current_page' => $items->currentPage(),
            'next_page' => $items->nextPageUrl(),
            'last_page' => $items->lastPage(),
            'total' => $items->total(),
        ]);
    }
    
    // Старый формат для обратной совместимости
    return response()->json([
        'items' => $items->items(),
        'current_page' => $items->currentPage(),
        'next_page' => $items->nextPageUrl(),
        'last_page' => $items->lastPage(),
        'total' => $items->total(),
    ]);
}
```

### Обновление CompaniesController

```php
use App\Http\Resources\CompanyResource;

public function index(Request $request)
{
    $perPage = $request->get('per_page', 10);

    $companies = Company::select([...])
        ->orderBy('name')
        ->paginate($perPage);

    return CompanyResource::collection($companies)->response();
}

public function store(Request $request)
{
    // ... валидация и создание ...
    
    $company = Company::create($data);
    
    return new CompanyResource($company);
}

public function update(Request $request, $id)
{
    // ... валидация и обновление ...
    
    $company = $company->fresh();
    
    return new CompanyResource($company);
}
```

---

## Чеклист задач

### Этап 1: Подготовка
- [ ] **Задача 1**: Создать структуру папок `app/Http/Resources`
- [ ] **Задача 2**: Создать `BaseResource` с общими методами (formatDate, formatDateTime, assetUrl, formatCurrency)
- [ ] **Задача 3**: Создать `BaseResourceCollection` для единообразной пагинации
- [ ] **Задача 4**: Обновить метод `paginatedResponse()` в `Controller` для поддержки Resources

### Этап 2: Создание Resources
- [ ] **Задача 5**: Создать `CompanyResource`
- [ ] **Задача 6**: Создать `ProductResource`
- [ ] **Задача 7**: Создать `ClientResource`
- [ ] **Задача 8**: Создать `OrderResource`
- [ ] **Задача 9**: Создать `InvoiceResource`
- [ ] **Задача 10**: Создать `ProjectResource`
- [ ] **Задача 11**: Создать `TransactionResource`
- [ ] **Задача 12**: Создать `WarehouseResource`
- [ ] **Задача 13**: Создать `CashRegisterResource`
- [ ] **Задача 14**: Создать `CategoryResource`
- [ ] **Задача 15**: Создать `UserResource`
- [ ] **Задача 16**: Создать `RoleResource`
- [ ] **Задача 17**: Создать `OrderStatusResource`
- [ ] **Задача 18**: Создать `ProjectStatusResource`
- [ ] **Задача 19**: Создать `TransactionCategoryResource`
- [ ] **Задача 20**: Создать `SaleResource`
- [ ] **Задача 21**: Создать `TransferResource`
- [ ] **Задача 22**: Создать `WarehouseReceiptResource`
- [ ] **Задача 23**: Создать `WarehouseWriteoffResource`
- [ ] **Задача 24**: Создать `WarehouseMovementResource`
- [ ] **Задача 25**: Создать `WarehouseStockResource`
- [ ] **Задача 26**: Создать `ProjectContractResource`
- [ ] **Задача 27**: Создать `CommentResource`
- [ ] **Задача 28**: Создать `CurrencyHistoryResource`
- [ ] **Задача 29**: Создать `CompanyRoundingRuleResource`

### Этап 3: Обновление контроллеров
- [ ] **Задача 30**: Обновить `CompaniesController` для использования `CompanyResource`
- [ ] **Задача 31**: Обновить `ProductController` для использования `ProductResource`
- [ ] **Задача 32**: Обновить `ClientController` для использования `ClientResource`
- [ ] **Задача 33**: Обновить `OrderController` для использования `OrderResource`
- [ ] **Задача 34**: Обновить `InvoiceController` для использования `InvoiceResource`
- [ ] **Задача 35**: Обновить `ProjectsController` для использования `ProjectResource`
- [ ] **Задача 36**: Обновить `TransactionsController` для использования `TransactionResource`
- [ ] **Задача 37**: Обновить `WarehouseController` для использования `WarehouseResource`
- [ ] **Задача 38**: Обновить `CashRegistersController` для использования `CashRegisterResource`
- [ ] **Задача 39**: Обновить `CategoriesController` для использования `CategoryResource`
- [ ] **Задача 40**: Обновить `UsersController` для использования `UserResource`
- [ ] **Задача 41**: Обновить `RolesController` для использования `RoleResource`
- [ ] **Задача 42**: Обновить `OrderStatusController` для использования `OrderStatusResource`
- [ ] **Задача 43**: Обновить `ProjectStatusController` для использования `ProjectStatusResource`
- [ ] **Задача 44**: Обновить `TransactionCategoryController` для использования `TransactionCategoryResource`
- [ ] **Задача 45**: Обновить `SaleController` для использования `SaleResource`
- [ ] **Задача 46**: Обновить `TransfersController` для использования `TransferResource`
- [ ] **Задача 47**: Обновить `WarehouseReceiptController` для использования `WarehouseReceiptResource`
- [ ] **Задача 48**: Обновить `WarehouseWriteoffController` для использования `WarehouseWriteoffResource`
- [ ] **Задача 49**: Обновить `WarehouseMovementController` для использования `WarehouseMovementResource`
- [ ] **Задача 50**: Обновить `WarehouseStockController` для использования `WarehouseStockResource`
- [ ] **Задача 51**: Обновить `ProjectContractsController` для использования `ProjectContractResource`
- [ ] **Задача 52**: Обновить `CommentController` для использования `CommentResource`
- [ ] **Задача 53**: Обновить `CurrencyHistoryController` для использования `CurrencyHistoryResource`
- [ ] **Задача 54**: Обновить `CompanyRoundingRulesController` для использования `CompanyRoundingRuleResource`
- [ ] **Задача 55**: Обновить `AuthController` для использования `UserResource`
- [ ] **Задача 56**: Обновить `AppController` для использования `CurrencyResource` и `UnitResource` (если нужны)
- [ ] **Задача 57**: Обновить `UserCompanyController` для использования `CompanyResource`

### Этап 4: Оптимизация
- [ ] **Задача 58**: Создать вложенные Resources для связанных моделей (OrderProductResource, InvoiceProductResource и т.д.)
- [ ] **Задача 59**: Добавить условные поля в Resources на основе прав доступа (when, mergeWhen)
- [ ] **Задача 60**: Добавить поддержку `whenLoaded()` для eager loading связей
- [ ] **Задача 61**: Обновить репозитории для загрузки необходимых связей (`with()`) перед возвратом

### Этап 5: Тестирование и финализация
- [ ] **Задача 62**: Протестировать все API endpoints после миграции
- [ ] **Задача 63**: Обновить фронтенд DTO (если структура ответов изменилась)
- [ ] **Задача 64**: Обновить документацию API (Swagger/OpenAPI) если используется
- [ ] **Задача 65**: Провести рефакторинг: удалить дублирующуюся логику форматирования из контроллеров

---

## Полезные ссылки

- [Laravel API Resources Documentation](https://laravel.com/docs/10.x/eloquent-resources)
- [Laravel Resource Collections](https://laravel.com/docs/10.x/eloquent-resources#resource-collections)
- [Conditional Attributes](https://laravel.com/docs/10.x/eloquent-resources#conditional-attributes)

---

## Примечания

1. **Обратная совместимость**: Метод `paginatedResponse()` должен поддерживать как старый формат, так и Resources
2. **Тестирование**: После каждого этапа необходимо тестировать изменения
3. **Постепенная миграция**: Можно мигрировать контроллеры постепенно, не все сразу
4. **Фронтенд**: После миграции проверить совместимость с фронтендом, возможно потребуется обновление DTO

