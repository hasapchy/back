# План рефакторинга: Внедрение сервисов и политик по Best Practices Laravel

## Анализ текущего состояния

### Текущие проблемы:
1. **Бизнес-логика в контроллерах** - много методов, которые должны быть в сервисах
2. **Дублирование кода авторизации** - проверки прав разбросаны по контроллерам
3. **Отсутствие стандартных Policies** - используется кастомная система через `canPerformAction()`
4. **Работа с файлами в контроллерах** - логика загрузки/удаления файлов должна быть в сервисах
5. **Сложная логика валидации** - проверки ограничений транзакций, категорий и т.д. в контроллерах

---

## ПЛАН ВНЕДРЕНИЯ СЕРВИСОВ

### 1. File Management Services (Приоритет: ВЫСОКИЙ)

#### 1.1. ProjectFileService
**Файл:** `app/Services/ProjectFileService.php`

**Что вынести:**
- `ProjectsController::uploadFiles()` - загрузка файлов проекта
- `ProjectsController::deleteFile()` - удаление файлов проекта
- Логика работы с массивом файлов в JSON поле `files`

**Методы:**
```php
- uploadFiles(Project $project, array $files): array
- deleteFile(Project $project, string $filePath): bool
- getFiles(Project $project): array
```

**Использование:**
- `ProjectsController::uploadFiles()` → `ProjectFileService::uploadFiles()`
- `ProjectsController::deleteFile()` → `ProjectFileService::deleteFile()`

---

#### 1.2. UserPhotoService
**Файл:** `app/Services/UserPhotoService.php`

**Что вынести:**
- `UsersController::handlePhotoUpload()` - загрузка фото пользователя
- Логика удаления старого фото
- Генерация имени файла

**Методы:**
```php
- uploadPhoto(User $user, UploadedFile $file): string
- deletePhoto(User $user): bool
- updatePhoto(User $user, ?UploadedFile $file): ?string
```

**Использование:**
- `UsersController::store()` → `UserPhotoService::uploadPhoto()`
- `UsersController::update()` → `UserPhotoService::updatePhoto()`
- `UsersController::updateProfile()` → `UserPhotoService::updatePhoto()`

---

#### 1.3. ProductImageService
**Файл:** `app/Services/ProductImageService.php`

**Что вынести:**
- `ProductController::store()` - загрузка изображения товара
- `ProductController::update()` - обновление изображения товара
- Удаление старого изображения

**Методы:**
```php
- uploadImage(Product $product, UploadedFile $file): string
- updateImage(Product $product, ?UploadedFile $file): ?string
- deleteImage(Product $product): bool
```

**Использование:**
- `ProductController::store()` → `ProductImageService::uploadImage()`
- `ProductController::update()` → `ProductImageService::updateImage()`

---

#### 1.4. CompanyLogoService
**Файл:** `app/Services/CompanyLogoService.php`

**Что вынести:**
- `CompaniesController::store()` - загрузка логотипа компании
- `CompaniesController::update()` - обновление логотипа компании

**Методы:**
```php
- uploadLogo(Company $company, UploadedFile $file): string
- updateLogo(Company $company, ?UploadedFile $file): ?string
- deleteLogo(Company $company): bool
```

---

### 2. Business Logic Services (Приоритет: ВЫСОКИЙ)

#### 2.1. OrderService
**Файл:** `app/Services/OrderService.php`

**Что вынести:**
- `OrderController::store()` - логика создания заказа
- Проверка категорий для basement worker
- Подготовка данных продуктов
- Валидация доступа к кассе и складу

**Методы:**
```php
- createOrder(array $data, User $user): Order
- validateCategoryAccess(int $categoryId, User $user, ?int $companyId): bool
- prepareOrderData(array $requestData, User $user): array
```

**Использование:**
- `OrderController::store()` → `OrderService::createOrder()`

---

#### 2.2. TransactionService
**Файл:** `app/Services/TransactionService.php`

**Что вынести:**
- `TransactionsController::isRestrictedTransaction()` - проверка ограничений
- `TransactionsController::getRestrictedTransactionMessage()` - сообщения об ограничениях
- Логика определения source_type и source_id
- Валидация транзакций

**Методы:**
```php
- createTransaction(array $data, User $user): Transaction
- canEditTransaction(Transaction $transaction): bool
- canDeleteTransaction(Transaction $transaction): bool
- getRestrictionMessage(Transaction $transaction): ?string
- determineSourceType(array $data): array
```

**Использование:**
- `TransactionsController::store()` → `TransactionService::createTransaction()`
- `TransactionsController::update()` → `TransactionService::canEditTransaction()`
- `TransactionsController::destroy()` → `TransactionService::canDeleteTransaction()`

---

#### 2.3. InvoiceService
**Файл:** `app/Services/InvoiceService.php`

**Что вынести:**
- `InvoiceController::store()` - создание счета из заказов
- `InvoiceController::update()` - обновление счета
- Валидация заказов (один клиент)
- Подготовка продуктов из заказов

**Методы:**
```php
- createFromOrders(array $orderIds, array $data, User $user): Invoice
- validateOrdersForInvoice(array $orderIds): bool
- prepareProductsFromOrders(Collection $orders): array
- updateInvoice(Invoice $invoice, array $data): Invoice
```

**Использование:**
- `InvoiceController::store()` → `InvoiceService::createFromOrders()`
- `InvoiceController::update()` → `InvoiceService::updateInvoice()`

---

#### 2.4. ProjectService
**Файл:** `app/Services/ProjectService.php`

**Что вынести:**
- `ProjectsController::prepareProjectData()` - подготовка данных проекта
- Логика создания/обновления проекта
- Валидация данных проекта

**Методы:**
```php
- createProject(array $data, User $user): Project
- updateProject(Project $project, array $data, User $user): Project
- prepareProjectData(array $requestData, User $user): array
```

**Использование:**
- `ProjectsController::store()` → `ProjectService::createProject()`
- `ProjectsController::update()` → `ProjectService::updateProject()`

---

#### 2.5. TimelineService
**Файл:** `app/Services/TimelineService.php`

**Что вынести:**
- `CommentController::buildTimeline()` - построение таймлайна
- `CommentController::getOptimizedComments()` - получение комментариев
- `CommentController::getOptimizedActivities()` - получение активностей
- `CommentController::processActivityLog()` - обработка логов
- `CommentController::formatAmountForCompany()` - форматирование сумм

**Методы:**
```php
- buildTimeline(string $modelClass, int $id): Collection
- getCommentsForModel(Model $model): Collection
- getActivitiesForModel(Model $model, string $modelClass): Collection
- processActivityLog(Activity $log, string $modelClass): array
- formatAmount(?int $companyId, float $amount): string
```

**Использование:**
- `CommentController::timeline()` → `TimelineService::buildTimeline()`

---

#### 2.6. UserService
**Файл:** `app/Services/UserService.php`

**Что вынести:**
- `UsersController::store()` - создание пользователя с ролями и компаниями
- `UsersController::update()` - обновление пользователя
- Обработка данных ролей и компаний
- Валидация данных пользователя

**Методы:**
```php
- createUser(array $data): User
- updateUser(User $user, array $data): User
- prepareUserData(array $requestData): array
- syncRolesAndCompanies(User $user, array $data): void
```

**Использование:**
- `UsersController::store()` → `UserService::createUser()`
- `UsersController::update()` → `UserService::updateUser()`

---

### 3. Validation & Authorization Services (Приоритет: СРЕДНИЙ)

#### 3.1. CategoryAccessService
**Файл:** `app/Services/CategoryAccessService.php`

**Что вынести:**
- Логика проверки доступа к категориям для basement worker
- `OrderController::store()` - проверка категорий
- `ProductController::normalizeCategoryIdForBasementWorker()`

**Методы:**
```php
- canAccessCategory(User $user, int $categoryId, ?int $companyId): bool
- getUserCategories(User $user, ?int $companyId): array
- normalizeCategoryIdForWorker(User $user, ?int $categoryId, ?int $companyId): ?int
```

---

#### 3.2. AccessControlService
**Файл:** `app/Services/AccessControlService.php`

**Что вынести:**
- `Controller::checkCashRegisterAccess()` - проверка доступа к кассе
- `Controller::checkWarehouseAccess()` - проверка доступа к складу
- Централизованная проверка доступа к ресурсам

**Методы:**
```php
- canAccessCashRegister(User $user, int $cashRegisterId): bool
- canAccessWarehouse(User $user, int $warehouseId): bool
- checkResourceAccess(User $user, string $resource, int $resourceId): bool
```

---

## ПЛАН ВНЕДРЕНИЯ POLICIES

### 1. Создание Policies (Приоритет: ВЫСОКИЙ)

#### 1.1. ProjectPolicy
**Файл:** `app/Policies/ProjectPolicy.php`

**Методы:**
```php
- viewAny(User $user): bool
- view(User $user, Project $project): bool
- create(User $user): bool
- update(User $user, Project $project): bool
- delete(User $user, Project $project): bool
```

**Замена:**
- `canPerformAction('projects', 'view', $project)` → `$user->can('view', $project)`
- `canPerformAction('projects', 'update', $project)` → `$user->can('update', $project)`
- `canPerformAction('projects', 'delete', $project)` → `$user->can('delete', $project)`
- `hasPermission('projects_create')` → `$user->can('create', Project::class)`

**Использование:**
- `ProjectsController` - все методы
- `ProjectContractsController` - проверки доступа к проектам

---

#### 1.2. OrderPolicy
**Файл:** `app/Policies/OrderPolicy.php`

**Методы:**
```php
- viewAny(User $user): bool
- view(User $user, Order $order): bool
- create(User $user): bool
- update(User $user, Order $order): bool
- delete(User $user, Order $order): bool
```

**Замена:**
- `canPerformAction('orders', 'view', $order)` → `$user->can('view', $order)`
- `canPerformAction('orders', 'update', $order)` → `$user->can('update', $order)`
- `canPerformAction('orders', 'delete', $order)` → `$user->can('delete', $order)`

**Использование:**
- `OrderController` - все методы

---

#### 1.3. TransactionPolicy
**Файл:** `app/Policies/TransactionPolicy.php`

**Методы:**
```php
- viewAny(User $user): bool
- view(User $user, Transaction $transaction): bool
- create(User $user): bool
- update(User $user, Transaction $transaction): bool
- delete(User $user, Transaction $transaction): bool
- adjustBalance(User $user): bool
```

**Замена:**
- `canPerformAction('transactions', 'view', $transaction)` → `$user->can('view', $transaction)`
- `canPerformAction('transactions', 'update', $transaction)` → `$user->can('update', $transaction)`
- `canPerformAction('transactions', 'delete', $transaction)` → `$user->can('delete', $transaction)`
- `hasPermission('settings_client_balance_adjustment')` → `$user->can('adjustBalance', Transaction::class)`

**Использование:**
- `TransactionsController` - все методы

---

#### 1.4. ClientPolicy
**Файл:** `app/Policies/ClientPolicy.php`

**Методы:**
```php
- viewAny(User $user): bool
- view(User $user, Client $client): bool
- create(User $user): bool
- update(User $user, Client $client): bool
- delete(User $user, Client $client): bool
- viewBalance(User $user, Client $client): bool
```

**Замена:**
- `canPerformAction('clients', 'view', $client)` → `$user->can('view', $client)`
- `canPerformAction('clients', 'update', $client)` → `$user->can('update', $client)`
- `canPerformAction('clients', 'delete', $client)` → `$user->can('delete', $client)`
- `hasPermission('settings_client_balance_view')` → `$user->can('viewBalance', $client)`

**Использование:**
- `ClientController` - все методы

---

#### 1.5. ProductPolicy
**Файл:** `app/Policies/ProductPolicy.php`

**Методы:**
```php
- viewAny(User $user): bool
- view(User $user, Product $product): bool
- create(User $user): bool
- update(User $user, Product $product): bool
- delete(User $user, Product $product): bool
- createTemp(User $user): bool
```

**Замена:**
- `canPerformAction('products', 'view', $product)` → `$user->can('view', $product)`
- `canPerformAction('products', 'update', $product)` → `$user->can('update', $product)`
- `canPerformAction('products', 'delete', $product)` → `$user->can('delete', $product)`
- `hasPermission('products_create_temp')` → `$user->can('createTemp', Product::class)`

**Использование:**
- `ProductController` - все методы
- `OrderController` - проверка создания временных продуктов

---

#### 1.6. UserPolicy
**Файл:** `app/Policies/UserPolicy.php`

**Методы:**
```php
- viewAny(User $user): bool
- view(User $user, User $targetUser): bool
- create(User $user): bool
- update(User $user, User $targetUser): bool
- delete(User $user, User $targetUser): bool
- viewBalance(User $user, User $targetUser): bool
```

**Замена:**
- `canPerformAction('users', 'view', $targetUser)` → `$user->can('view', $targetUser)`
- `canPerformAction('users', 'update', $targetUser)` → `$user->can('update', $targetUser)`
- `canPerformAction('users', 'delete', $targetUser)` → `$user->can('delete', $targetUser)`

**Использование:**
- `UsersController` - все методы

---

#### 1.7. SalePolicy
**Файл:** `app/Policies/SalePolicy.php`

**Методы:**
```php
- viewAny(User $user): bool
- view(User $user, Sale $sale): bool
- create(User $user): bool
- delete(User $user, Sale $sale): bool
```

**Замена:**
- `canPerformAction('sales', 'view', $sale)` → `$user->can('view', $sale)`
- `canPerformAction('sales', 'delete', $sale)` → `$user->can('delete', $sale)`

**Использование:**
- `SaleController` - все методы

---

#### 1.8. CashRegisterPolicy
**Файл:** `app/Policies/CashRegisterPolicy.php`

**Методы:**
```php
- viewAny(User $user): bool
- view(User $user, CashRegister $cashRegister): bool
- create(User $user): bool
- update(User $user, CashRegister $cashRegister): bool
- delete(User $user, CashRegister $cashRegister): bool
- viewBalance(User $user, CashRegister $cashRegister): bool
```

**Замена:**
- `canPerformAction('cash_registers', 'view', $cashRegister)` → `$user->can('view', $cashRegister)`
- `canPerformAction('cash_registers', 'update', $cashRegister)` → `$user->can('update', $cashRegister)`
- `canPerformAction('cash_registers', 'delete', $cashRegister)` → `$user->can('delete', $cashRegister)`
- `hasPermission('settings_cash_balance_view')` → `$user->can('viewBalance', $cashRegister)`

**Использование:**
- `CashRegistersController` - все методы
- `OrderTransactionController` - проверки доступа к кассе

---

#### 1.9. WarehousePolicy
**Файл:** `app/Policies/WarehousePolicy.php`

**Методы:**
```php
- viewAny(User $user): bool
- view(User $user, Warehouse $warehouse): bool
- create(User $user): bool
- update(User $user, Warehouse $warehouse): bool
- delete(User $user, Warehouse $warehouse): bool
```

**Замена:**
- `canPerformAction('warehouses', 'view', $warehouse)` → `$user->can('view', $warehouse)`
- `canPerformAction('warehouses', 'update', $warehouse)` → `$user->can('update', $warehouse)`
- `canPerformAction('warehouses', 'delete', $warehouse)` → `$user->can('delete', $warehouse)`

**Использование:**
- `WarehouseController` - все методы
- `WarehouseReceiptController` - проверки доступа
- `WarehouseWriteoffController` - проверки доступа
- `WarehouseMovementController` - проверки доступа

---

#### 1.10. InvoicePolicy
**Файл:** `app/Policies/InvoicePolicy.php`

**Методы:**
```php
- viewAny(User $user): bool
- view(User $user, Invoice $invoice): bool
- create(User $user): bool
- update(User $user, Invoice $invoice): bool
- delete(User $user, Invoice $invoice): bool
```

**Использование:**
- `InvoiceController` - все методы

---

### 2. Регистрация Policies

**Файл:** `app/Providers/AuthServiceProvider.php`

```php
protected $policies = [
    Project::class => ProjectPolicy::class,
    Order::class => OrderPolicy::class,
    Transaction::class => TransactionPolicy::class,
    Client::class => ClientPolicy::class,
    Product::class => ProductPolicy::class,
    User::class => UserPolicy::class,
    Sale::class => SalePolicy::class,
    CashRegister::class => CashRegisterPolicy::class,
    Warehouse::class => WarehousePolicy::class,
    Invoice::class => InvoicePolicy::class,
];
```

---

### 3. Использование Policies в контроллерах

**Замена в контроллерах:**

**Было:**
```php
if (!$this->canPerformAction('projects', 'view', $project)) {
    return $this->forbiddenResponse('У вас нет прав на просмотр этого проекта');
}
```

**Стало:**
```php
$this->authorize('view', $project);
```

**Или с кастомным сообщением:**
```php
if (!$this->authorize('view', $project)) {
    return $this->forbiddenResponse('У вас нет прав на просмотр этого проекта');
}
```

---

## ПРИОРИТЕТЫ ВНЕДРЕНИЯ

### Фаза 1 (Высокий приоритет - 1-2 недели)
1. ✅ **File Management Services**
   - ProjectFileService
   - UserPhotoService
   - ProductImageService
   - CompanyLogoService

2. ✅ **Основные Policies**
   - ProjectPolicy
   - OrderPolicy
   - TransactionPolicy
   - ClientPolicy

### Фаза 2 (Средний приоритет - 2-3 недели)
3. ✅ **Business Logic Services**
   - OrderService
   - TransactionService
   - InvoiceService
   - ProjectService

4. ✅ **Остальные Policies**
   - ProductPolicy
   - UserPolicy
   - SalePolicy
   - CashRegisterPolicy
   - WarehousePolicy
   - InvoicePolicy

### Фаза 3 (Низкий приоритет - 1-2 недели)
5. ✅ **Дополнительные Services**
   - TimelineService
   - UserService
   - CategoryAccessService
   - AccessControlService

---

## ПРЕИМУЩЕСТВА РЕФАКТОРИНГА

### После внедрения сервисов:
- ✅ Разделение ответственности (SRP)
- ✅ Переиспользование кода
- ✅ Упрощение тестирования
- ✅ Уменьшение размера контроллеров
- ✅ Централизация бизнес-логики

### После внедрения Policies:
- ✅ Стандартизация авторизации
- ✅ Использование встроенных возможностей Laravel
- ✅ Упрощение проверок прав
- ✅ Возможность использования в Blade/API Resources
- ✅ Автоматическая генерация документации

---

## МИГРАЦИОННАЯ СТРАТЕГИЯ

1. **Постепенное внедрение** - не переписывать всё сразу
2. **Сохранение обратной совместимости** - оставить старые методы на время миграции
3. **Тестирование** - покрыть тестами новые сервисы и политики
4. **Документация** - обновить документацию по использованию

---

## ПРИМЕРЫ РЕАЛИЗАЦИИ

### Пример 1: ProjectFileService

```php
namespace App\Services;

use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProjectFileService
{
    public function uploadFiles(Project $project, array $files): array
    {
        $storedFiles = $project->files ?? [];

        foreach ($files as $file) {
            $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('projects/' . $project->id, $filename, 'public');

            $storedFiles[] = [
                'name' => $file->getClientOriginalName(),
                'path' => $path,
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'uploaded_at' => now()->toDateTimeString(),
            ];
        }

        $project->update(['files' => $storedFiles]);

        return $storedFiles;
    }

    public function deleteFile(Project $project, string $filePath): bool
    {
        $files = $project->files ?? [];
        $updatedFiles = [];

        foreach ($files as $file) {
            if ($file['path'] === $filePath) {
                if (Storage::disk('public')->exists($filePath)) {
                    Storage::disk('public')->delete($filePath);
                }
                continue;
            }
            $updatedFiles[] = $file;
        }

        $project->update(['files' => $updatedFiles]);

        return true;
    }
}
```

### Пример 2: ProjectPolicy

```php
namespace App\Policies;

use App\Models\User;
use App\Models\Project;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('projects_view_all') 
            || $user->hasPermissionTo('projects_view_own');
    }

    public function view(User $user, Project $project): bool
    {
        if ($user->hasPermissionTo('projects_view_all')) {
            return true;
        }

        if ($user->hasPermissionTo('projects_view_own')) {
            return $project->user_id === $user->id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('projects_create');
    }

    public function update(User $user, Project $project): bool
    {
        if ($user->hasPermissionTo('projects_update_all')) {
            return true;
        }

        if ($user->hasPermissionTo('projects_update_own')) {
            return $project->user_id === $user->id;
        }

        return false;
    }

    public function delete(User $user, Project $project): bool
    {
        if ($user->hasPermissionTo('projects_delete_all')) {
            return true;
        }

        if ($user->hasPermissionTo('projects_delete_own')) {
            return $project->user_id === $user->id;
        }

        return false;
    }
}
```

---

## МЕТРИКИ УСПЕХА

- ✅ Уменьшение размера контроллеров на 40-60%
- ✅ Покрытие тестами новых сервисов > 80%
- ✅ Все проверки прав через Policies
- ✅ Отсутствие дублирования кода работы с файлами
- ✅ Централизация бизнес-логики в сервисах

---

## ЗАМЕТКИ

- При создании сервисов использовать dependency injection
- Сервисы должны быть stateless (кроме кэширования)
- Policies должны использовать существующую систему разрешений через `hasPermissionTo()`
- Сохранить обратную совместимость с текущей системой `canPerformAction()` на время миграции

