<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Requests\UpdateCommentRequest;
use App\Repositories\CommentsRepository;
use App\Services\CacheService;
use App\Services\RoundingService;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;
use App\Models\User;
use App\Models\Comment;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\Currency;
use App\Models\Client;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Project;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\OrderStatus;
use App\Models\TaskStatus;
use App\Models\TransactionCategory;
use App\Models\Sale;
use App\Models\Task;
use App\Models\OrderProduct;
use App\Models\OrderTempProduct;
use App\Models\Unit;
use Illuminate\Support\Facades\Log;

/**
 * Контроллер для работы с комментариями
 */
class CommentController extends BaseController
{
    protected CommentsRepository $itemsRepository;
    private array $productUnitCache = [];
    private array $unitCache = [];
    private ?string $defaultCurrencySymbolCache = null;

    /**
     * Конструктор контроллера
     *
     * @param CommentsRepository $itemsRepository
     */
    public function __construct(CommentsRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список комментариев для сущности
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->unauthorizedResponse();
        }

        $request->validate([
            'type' => 'required|string',
            'id' => 'required|integer',
        ]);

        $comments = $this->itemsRepository->getCommentsFor($request->type, $request->id);
        return response()->json($comments);
    }

    /**
     * Создать новый комментарий
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreCommentRequest $request)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->unauthorizedResponse();
        }

        $validatedData = $request->validated();

        $comment = $this->itemsRepository->createItem($validatedData['type'], $validatedData['id'], $validatedData['body'], $user->id);

        $this->invalidateTimelineCache($validatedData['type'], $validatedData['id']);

        return response()->json([
            'message' => 'Комментарий добавлен',
            'comment' => $comment,
        ]);
    }

    /**
     * Обновить комментарий
     *
     * @param Request $request
     * @param int $id ID комментария
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateCommentRequest $request, $id)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->unauthorizedResponse();
        }

        $validatedData = $request->validated();

        $updatedComment = $this->itemsRepository->updateItem($id, $user->id, $validatedData['body']);

        if (! $updatedComment) {
            return response()->json(['message' => 'Комментарий не найден или нет прав'], 403);
        }

        $this->invalidateTimelineCache($updatedComment->commentable_type, $updatedComment->commentable_id);

        return response()->json([
            'message' => 'Комментарий обновлён',
            'comment' => $updatedComment,
        ]);
    }

    /**
     * Удалить комментарий
     *
     * @param int $id ID комментария
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $user = auth('api')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $comment = Comment::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$comment) {
            return $this->forbiddenResponse('Комментарий не найден или нет прав');
        }

        $deleted = $this->itemsRepository->deleteItem($id, $user->id);

        if (!$deleted) {
            return $this->forbiddenResponse('Комментарий не найден или нет прав');
        }

        $this->invalidateTimelineCache($comment->commentable_type, $comment->commentable_id);

        return response()->json(['message' => 'Комментарий удалён']);
    }

    /**
     * Получить таймлайн для сущности
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function timeline(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->unauthorizedResponse();
        }

        $request->validate([
            'type' => 'required|string',
            'id' => 'required|integer',
        ]);

        try {
            $modelClass = $this->itemsRepository->resolveType($request->type);
            $cacheKey = "timeline_{$request->type}_{$request->id}";

            return CacheService::remember($cacheKey, function () use ($modelClass, $request) {
                return $this->buildTimeline($modelClass, $request->id);
            }, 600);

        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка загрузки таймлайна: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Построить таймлайн для модели
     *
     * @param string $modelClass Класс модели
     * @param int $id ID сущности
     * @return \Illuminate\Support\Collection
     * @throws \Exception
     */
    private function buildTimeline(string $modelClass, int $id)
    {
        try {
            // Определяем поля и связи в зависимости от типа модели
            $selectFields = ['id'];
            $withRelations = [];

            if ($modelClass === Task::class) {
                $selectFields = array_merge($selectFields, ['creator_id', 'supervisor_id', 'executor_id', 'status_id', 'project_id']);
                $withRelations = [
                    'creator:id,name',
                    'supervisor:id,name',
                    'executor:id,name',
                    'status:id,name',
                    'project:id,name'
                ];
            } else {
                // Для Order, Transaction, Sale и других
                $selectFields = array_merge($selectFields, ['client_id', 'user_id', 'status_id', 'category_id']);
                $withRelations = [
                    'client:id,first_name,last_name,contact_person',
                    'user:id,name',
                    'status:id,name',
                    'category:id,name'
                ];
            }

            $model = $modelClass::select($selectFields)
                ->with($withRelations)
                ->findOrFail($id);

            if (isset($model->client) && $model->client) {
                $model->client->name = $model->client->first_name . ' ' . $model->client->last_name;
            }

            if (!method_exists($model, 'comments') || !method_exists($model, 'activities') || !method_exists($model, 'getKey')) {
                throw new \Exception('Модель не поддерживает комментарии или активность');
            }

            $comments = $this->getOptimizedComments($model);

            $activities = $this->getOptimizedActivities($model, $modelClass);

            if ($modelClass === Order::class) {
                $orderActivities = $this->getOrderSpecificActivities($id);
                $activities = $activities->merge($orderActivities);
            }

            $timeline = collect($comments)
                ->merge($activities)
                ->sortBy(function ($item) {
                    return \Carbon\Carbon::parse($item['created_at']);
                })
                ->values();

            return $timeline;

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Получить оптимизированные комментарии для модели
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return \Illuminate\Support\Collection
     */
    private function getOptimizedComments($model)
    {
        return $model->comments()
            ->select([
                'comments.id', 'comments.body', 'comments.user_id', 'comments.created_at'
            ])
            ->with(['user:id,name,email'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($comment) {
                return [
                    'type' => 'comment',
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'user' => $comment->user,
                    'created_at' => $comment->created_at,
                ];
            });
    }

    /**
     * Получить оптимизированные активности для модели
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param string $modelClass Класс модели
     * @return \Illuminate\Support\Collection
     */
    private function getOptimizedActivities($model, string $modelClass)
    {
        return $model->activities()
            ->select([
                'activity_log.id', 'activity_log.description', 'activity_log.properties',
                'activity_log.causer_id', 'activity_log.created_at', 'activity_log.log_name'
            ])
            ->with(['causer:id,name', 'subject'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($log) use ($modelClass) {
                return $this->processActivityLog($log, $modelClass);
            });
    }

    /**
     * Обработать лог активности
     *
     * @param mixed $log Лог активности
     * @param string $modelClass Класс модели
     * @return array
     */
    private function processActivityLog($log, string $modelClass)
    {
        $user = $this->getUserForActivity($log, $modelClass);

        $description = $log->description;
        $meta = null;
        $logName = $log->log_name ?? null;
        $orderCurrencySymbol = null;

        if ($log->subject && get_class($log->subject) === Order::class) {
            $log->subject->loadMissing(['cash.currency']);
            $orderCurrencySymbol = optional(optional($log->subject->cash)->currency)->symbol;
        }

        try {
            if ($log->subject && get_class($log->subject) === Order::class && isset($log->subject->id)) {
                $description = rtrim($description) . ' #' . $log->subject->id;
            }

            if ($log->subject && get_class($log->subject) === Transaction::class) {
                $txnId = $log->subject->id ?? null;
                $amount = $log->subject->amount ?? null;
                $currencySymbol = null;
                $companyId = null;
                try {
                    if (isset($log->subject->currency) && isset($log->subject->currency->symbol)) {
                        $currencySymbol = $log->subject->currency->symbol;
                    } elseif (isset($log->subject->currency_id) && $log->subject->currency_id) {
                        $currencySymbol = optional(Currency::select('id','symbol')->find($log->subject->currency_id))->symbol;
                    }
                } catch (\Throwable $e) {}

                if ($txnId !== null || $amount !== null) {
                    $parts = [];
                    if ($txnId !== null) { $parts[] = '#' . $txnId; }
                    if ($amount !== null) {
                        $formatted = $this->formatAmountForCompany($companyId, (float)$amount);
                        $parts[] = 'сумма: ' . $formatted . ($currencySymbol ? (' ' . $currencySymbol) : '');
                    }
                    $description = rtrim($description) . ' (' . implode(', ', $parts) . ')';
                }
                $meta = [
                    'transaction_id' => $txnId,
                    'amount' => $amount !== null ? (float)$amount : null,
                    'currency_symbol' => isset($currencySymbol) ? $currencySymbol : null,
                ];
            }

            if (in_array($logName, ['order_product', 'order_temp_product'])) {
                $props = $log->properties;
                $attrs = null;
                $old = null;
                if (method_exists($props, 'toArray')) {
                    $arr = $props->toArray();
                    $attrs = $arr['attributes'] ?? null;
                    $old = $arr['old'] ?? null;
                } elseif (is_object($props)) {
                    $attrs = $props->attributes ?? null;
                    $old = $props->old ?? null;
                } elseif (is_array($props)) {
                    $attrs = $props['attributes'] ?? null;
                    $old = $props['old'] ?? null;
                }
                if (is_array($attrs)) {
                    $q = isset($attrs['quantity']) ? (float)$attrs['quantity'] : null;
                    $p = isset($attrs['price']) ? (float)$attrs['price'] : null;
                    $meta = array_merge($meta ?? [], [
                        'product_quantity' => $q,
                        'product_price' => $p,
                    ]);
                }

                $currencySymbol = $orderCurrencySymbol ?? $this->getDefaultCurrencySymbol();
                if ($currencySymbol) {
                    $meta = array_merge($meta ?? [], [
                        'product_currency_symbol' => $currencySymbol,
                    ]);
                }

                $unitName = null;
                if ($logName === 'order_product') {
                    $productId = $attrs['product_id'] ?? $old['product_id'] ?? null;
                    $unitName = $this->getProductUnitName($productId);
                } else {
                    $unitId = $attrs['unit_id'] ?? $old['unit_id'] ?? null;
                    $unitName = $this->getUnitName($unitId);
                }

                if ($unitName) {
                    $meta = array_merge($meta ?? [], [
                        'product_unit' => $unitName,
                    ]);
                }

            }
        } catch (\Throwable $e) {
        }

        if ($log->description === 'created' || $log->description === 'Создан заказ') {
            return [
                'type' => 'log',
                'id' => $log->id,
                'description' => $description,
                'changes' => null,
                'user' => $user,
                'created_at' => $log->created_at,
                'meta' => $meta,
                'log_name' => $logName,
            ];
        }

        $changes = $this->processActivityChanges($log->properties, $modelClass);

        return [
            'type' => 'log',
            'id' => $log->id,
            'description' => $description,
            'changes' => $changes,
            'user' => $user,
            'created_at' => $log->created_at,
            'meta' => $meta,
            'log_name' => $logName,
        ];
    }

    /**
     * Обработать изменения в активности
     *
     * @param mixed $changes Изменения
     * @param string $modelClass Класс модели
     * @return array|null
     */
    private function processActivityChanges($changes, string $modelClass)
    {
        if (!$changes) {
            return null;
        }

        $attributes = null;
        $old = null;

        if (method_exists($changes, 'toArray')) {
            $changesArray = $changes->toArray();
            $attributes = $changesArray['attributes'] ?? null;
            $old = $changesArray['old'] ?? null;
        } elseif (is_object($changes)) {
            $attributes = $changes->attributes ?? null;
            $old = $changes->old ?? null;
        } elseif (is_array($changes)) {
            $attributes = $changes['attributes'] ?? null;
            $old = $changes['old'] ?? null;
        }

        if (!is_array($attributes)) {
            return null;
        }

        $processedAttrs = [];
        $processedOld = [];

        foreach ($attributes as $key => $value) {
            if (in_array($key, ['product_id', 'unit_id'], true)) {
                continue;
            }
            if (str_ends_with($key, '_id') && $value) {
                $relatedModel = $this->getRelatedModelName($key, $modelClass);

                if ($relatedModel && class_exists($relatedModel)) {
                    try {
                        if ($relatedModel === Client::class) {
                            $relatedRecord = $relatedModel::select('id,first_name,last_name,contact_person')->find($value);
                            $relatedName = $relatedRecord ? ($relatedRecord->first_name . ' ' . $relatedRecord->last_name) : $value;

                            $oldRelatedRecord = $old && isset($old[$key]) ? $relatedModel::select('id,first_name,last_name,contact_person')->find($old[$key]) : null;
                            $oldRelatedName = $oldRelatedRecord ? ($oldRelatedRecord->first_name . ' ' . $oldRelatedRecord->last_name) : ($old && isset($old[$key]) ? $old[$key] : null);
                        } else {
                            $relatedName = $relatedModel::select('id,name')->find($value)?->name ?? $value;
                            $oldRelatedName = $old && isset($old[$key]) ? ($relatedModel::select('id,name')->find($old[$key])?->name ?? $old[$key]) : null;
                        }

                        $processedAttrs[$key] = $relatedName;
                        $processedOld[$key] = $oldRelatedName;
                    } catch (\Exception $e) {
                        $processedAttrs[$key] = $value;
                        $processedOld[$key] = $old && isset($old[$key]) ? $old[$key] : null;
                    }
                } else {
                    $processedAttrs[$key] = $value;
                    $processedOld[$key] = $old && isset($old[$key]) ? $old[$key] : null;
                }
            } else {
                $processedAttrs[$key] = $value;
                $processedOld[$key] = $old && isset($old[$key]) ? $old[$key] : null;
            }
        }

        if (!empty($processedAttrs)) {
            return [
                'attributes' => $processedAttrs,
                'old' => $processedOld,
            ];
        }

        return null;
    }

    /**
     * Получить специфичные активности для заказа
     *
     * @param int $orderId ID заказа
     * @return \Illuminate\Support\Collection
     */
    private function getOrderSpecificActivities(int $orderId)
    {
        $orderTransactionLogs = Transaction::select(['id', 'source_id', 'source_type', 'amount', 'currency_id'])
            ->where('source_type', Order::class)
            ->where('source_id', $orderId)
            ->with(['currency:id,symbol'])
            ->get()
            ->flatMap(function ($transaction) {
                return $transaction->activities()
                    ->select(['activity_log.id', 'activity_log.description', 'activity_log.causer_id', 'activity_log.created_at'])
                    ->with(['causer:id,name', 'subject'])
                    ->get()->map(function ($log) use ($transaction) {
                        $desc = $log->description;
                        $parts = [];
                        $parts[] = '#' . $transaction->id;
                        if (!is_null($transaction->amount)) {
                            $symbol = optional($transaction->currency)->symbol;
                            $formatted = $this->formatAmountForCompany(null, (float)$transaction->amount);
                            $parts[] = 'сумма: ' . $formatted . ($symbol ? (' ' . $symbol) : '');
                        }
                        $desc = rtrim($desc) . ' (' . implode(', ', $parts) . ')';
                        return $log->setAttribute('description', $desc);
                    });
            })->map(function ($log) {
                return [
                    'type' => 'log',
                    'id' => $log->id,
                    'description' => $log->description,
                    'changes' => null,
                    'user' => $this->getUserForActivity($log, Order::class),
                    'created_at' => $log->created_at,
                ];
            })->filter();

        return $orderTransactionLogs;
    }

    private function getProductUnitName(?int $productId): ?string
    {
        if (!$productId) {
            return null;
        }

        if (!array_key_exists($productId, $this->productUnitCache)) {
            $product = Product::select(['id', 'unit_id'])
                ->with(['unit:id,name,short_name'])
                ->find($productId);

            $this->productUnitCache[$productId] = $product && $product->unit
                ? ($product->unit->short_name ?? $product->unit->name)
                : null;
        }

        return $this->productUnitCache[$productId];
    }

    private function getUnitName(?int $unitId): ?string
    {
        if (!$unitId) {
            return null;
        }

        if (!array_key_exists($unitId, $this->unitCache)) {
            $unit = Unit::select(['id', 'name', 'short_name'])->find($unitId);
            $this->unitCache[$unitId] = $unit ? ($unit->short_name ?? $unit->name) : null;
        }

        return $this->unitCache[$unitId];
    }

    private function getDefaultCurrencySymbol(): ?string
    {
        if ($this->defaultCurrencySymbolCache === null) {
            $this->defaultCurrencySymbolCache = Currency::where('is_default', true)->value('symbol');
        }

        return $this->defaultCurrencySymbolCache;
    }

    /**
     * Форматировать сумму для компании
     *
     * @param int|null $companyId ID компании
     * @param float $amount Сумма
     * @return string
     */
    private function formatAmountForCompany(?int $companyId, float $amount): string
    {
        try {
            $rounding = new RoundingService();
            $decimals = $rounding->getDecimalsForCompany($companyId);
            $rounded = $rounding->roundForCompany($companyId, $amount);
            return number_format($rounded, $decimals, '.', ' ');
        } catch (\Throwable $e) {
            return (string)$amount;
        }
    }

    /**
     * Получить имя связанной модели по ключу
     *
     * @param string $key Ключ поля
     * @param string $modelClass Класс модели
     * @return string|null
     */
    private function getRelatedModelName(string $key, string $modelClass): ?string
    {
        $baseFieldToModelMap = [
            'client_id' => Client::class,
            'user_id' => User::class,
            'creator_id' => User::class,
            'supervisor_id' => User::class,
            'executor_id' => User::class,
            'product_id' => Product::class,
            'warehouse_id' => Warehouse::class,
            'project_id' => Project::class,
            'cash_register_id' => CashRegister::class,
            'cash_id' => CashRegister::class,
            'currency_id' => Currency::class,
        ];

        $specificFieldToModelMap = [
            Order::class => [
                'category_id' => Category::class,
                'status_id' => OrderStatus::class,
            ],
            Transaction::class => [
                'category_id' => TransactionCategory::class,
            ],
            Sale::class => [
                'category_id' => Category::class,
            ],
            Task::class => [
                'status_id' => \App\Models\TaskStatus::class,
            ],
        ];

        if (isset($specificFieldToModelMap[$modelClass]) && isset($specificFieldToModelMap[$modelClass][$key])) {
            return $specificFieldToModelMap[$modelClass][$key];
        }

        return $baseFieldToModelMap[$key] ?? null;
    }

    /**
     * Получить пользователя для активности
     *
     * @param mixed $log Лог активности
     * @param string $modelClass Класс модели
     * @return array|null
     */
    private function getUserForActivity($log, string $modelClass)
    {
        if ($log->causer) {
            return [
                'id' => $log->causer->id,
                'name' => $log->causer->name,
            ];
        }

        if ($log->causer_id) {
            try {
                $user = User::select('id', 'name')->find($log->causer_id);
                if ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                    ];
                }
            } catch (\Exception $e) {
            }
        }

        if ($log->description === 'created' || $log->description === 'Создан заказ') {
            try {
                $subject = $log->subject;
                if ($subject && isset($subject->user_id) && $subject->user_id) {
                    $user = User::select('id', 'name')->find($subject->user_id);
                    if ($user) {
                        return [
                            'id' => $user->id,
                            'name' => $user->name,
                        ];
                    }
                }
            } catch (\Exception $e) {
            }
        }

        return null;
    }

    /**
     * Инвалидировать кэш таймлайна
     *
     * @param string $type Тип сущности
     * @param int $id ID сущности
     * @return void
     */
    private function invalidateTimelineCache(string $type, int $id)
    {
        $cacheKey = "timeline_{$type}_{$id}";
        CacheService::forget($cacheKey);

        $this->itemsRepository->invalidateCommentsCacheByType($type);
    }

}
