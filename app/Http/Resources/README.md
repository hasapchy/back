# API Resources - Руководство по использованию

## Структура

### Базовые классы

- **BaseResource** - базовый класс для всех Resources с общими методами форматирования
- **BaseResourceCollection** - базовый класс для пагинации с единообразной структурой

### Созданные Resources

- `CompanyResource` - для компаний
- `CashRegisterResource` - для касс
- `CurrencyResource` - для валют

## Использование

### Для одного объекта

```php
use App\Http\Resources\CompanyResource;

public function show($id)
{
    $company = Company::findOrFail($id);
    return new CompanyResource($company);
}
```

### Для коллекций с пагинацией

```php
use App\Http\Resources\CompanyResource;

public function index(Request $request)
{
    $companies = Company::paginate(20);
    return CompanyResource::collection($companies)->response();
}
```

### Для коллекций без пагинации

```php
use App\Http\Resources\CashRegisterResource;

public function all(Request $request)
{
    $items = $this->repository->getAllItems();
    return CashRegisterResource::collection($items)->response();
}
```

### С дополнительными данными

```php
return (new CompanyResource($company))->additional([
    'message' => 'Компания создана'
])->response();
```

## Методы BaseResource

### Форматирование дат

```php
'date' => $this->formatDate($this->date), // Y-m-d
'created_at' => $this->formatDateTime($this->created_at), // Y-m-d H:i:s
```

### Форматирование чисел и валют

```php
'balance' => $this->formatCurrency($this->balance), // 2 знака после запятой
'amount' => $this->formatNumber($this->amount, 4), // 4 знака после запятой
```

### URL для файлов

```php
'logo_url' => $this->assetUrl($this->logo), // asset("storage/{$path}")
```

### Boolean значения

```php
'is_active' => $this->toBoolean($this->is_active),
```

## Условные поля

### when() - условное поле

```php
'purchase_price' => $this->when(
    $request->user()->hasPermission('products_view_purchase_price'),
    $this->purchase_price
),
```

### whenLoaded() - загруженные связи

```php
'currency' => new CurrencyResource($this->whenLoaded('currency')),
'categories' => CategoryResource::collection($this->whenLoaded('categories')),
```

### mergeWhen() - условное слияние

```php
$this->mergeWhen($request->user()->isAdmin(), [
    'internal_notes' => $this->internal_notes,
    'created_by' => $this->user_id,
]),
```

## Единообразная структура ответов

### Пагинация

Все пагинированные ответы имеют единообразную структуру:

```json
{
  "items": [...],
  "current_page": 1,
  "next_page": "http://...",
  "last_page": 10,
  "total": 200
}
```

### Одиночные объекты

```json
{
  "id": 1,
  "name": "...",
  "created_at": "2024-01-01 12:00:00",
  ...
}
```

### С сообщениями

```json
{
  "id": 1,
  "name": "...",
  "message": "Компания создана"
}
```

## Примеры

### CompanyResource

```php
class CompanyResource extends BaseResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'logo_url' => $this->assetUrl($this->logo),
            'rounding_enabled' => $this->toBoolean($this->rounding_enabled),
            'created_at' => $this->formatDateTime($this->created_at),
        ];
    }
}
```

### CashRegisterResource с вложенными Resources

```php
class CashRegisterResource extends BaseResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'balance' => $this->formatCurrency($this->balance),
            'currency' => new CurrencyResource($this->whenLoaded('currency')),
        ];
    }
}
```

## Обновление контроллеров

### До

```php
public function index(Request $request)
{
    $items = $this->repository->getItemsWithPagination(...);
    return $this->paginatedResponse($items);
}

public function store(Request $request)
{
    $item = $this->repository->createItem(...);
    return response()->json(['item' => $item]);
}
```

### После

```php
use App\Http\Resources\ItemResource;

public function index(Request $request)
{
    $items = $this->repository->getItemsWithPagination(...);
    return ItemResource::collection($items)->response();
}

public function store(Request $request)
{
    $item = $this->repository->createItem(...);
    return (new ItemResource($item))->additional([
        'message' => 'Элемент создан'
    ])->response();
}
```

## Важные замечания

1. **Всегда используйте `->response()`** для возврата JSON ответа
2. **Используйте `whenLoaded()`** для вложенных Resources, чтобы избежать N+1 проблем
3. **Форматирование данных** происходит в Resources, а не в контроллерах
4. **Единообразная структура** обеспечивается через BaseResource и BaseResourceCollection

