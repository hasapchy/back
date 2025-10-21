<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\CommentsRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class CommentController extends Controller
{
    protected CommentsRepository $itemsRepository;

    public function __construct(CommentsRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    public function index(Request $request)
    {
        $user = auth('api')->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'type' => 'required|string',
            'id' => 'required|integer',
        ]);

        $comments = $this->itemsRepository->getCommentsFor($request->type, $request->id);
        return response()->json($comments);
    }

    public function store(Request $request)
    {
        $user = auth('api')->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'type' => 'required|string',
            'id' => 'required|integer',
            'body' => 'required|string|max:1000',
        ]);

        $comment = $this->itemsRepository->createItem($request->type, $request->id, $request->body, $user->id);

        // Инвалидируем кэш таймлайна
        $this->invalidateTimelineCache($request->type, $request->id);

        return response()->json([
            'message' => 'Комментарий добавлен',
            'comment' => $comment,
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = auth('api')->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'body' => 'required|string|max:1000',
        ]);

        $updatedComment = $this->itemsRepository->updateItem($id, $user->id, $request->body);

        if (! $updatedComment) {
            return response()->json(['message' => 'Комментарий не найден или нет прав'], 403);
        }

        // Инвалидируем кэш таймлайна
        $this->invalidateTimelineCache($updatedComment->commentable_type, $updatedComment->commentable_id);

        return response()->json([
            'message' => 'Комментарий обновлён',
            'comment' => $updatedComment,
        ]);
    }


    public function destroy($id)
    {
        $user = auth('api')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Получаем комментарий для определения типа и ID сущности
        $comment = \App\Models\Comment::select(['id', 'commentable_type', 'commentable_id'])
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$comment) {
            return response()->json(['message' => 'Комментарий не найден или нет прав'], 403);
        }

        $deleted = $this->itemsRepository->deleteItem($id, $user->id);

        if (!$deleted) {
            return response()->json(['message' => 'Комментарий не найден или нет прав'], 403);
        }

        // Инвалидируем кэш таймлайна
        $this->invalidateTimelineCache($comment->commentable_type, $comment->commentable_id);

        return response()->json(['message' => 'Комментарий удалён']);
    }

    public function timeline(Request $request)
    {
        $user = auth('api')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
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
            }, 600); // 10 минут для таймлайна

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Ошибка загрузки таймлайна', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Построение таймлайна с оптимизацией
     */
    private function buildTimeline(string $modelClass, int $id)
    {
        try {
            // Получаем модель с оптимизированными связями
            $model = $modelClass::select([
                'id', 'client_id', 'user_id', 'status_id', 'category_id'
            ])
            ->with([
                'client:id,first_name,last_name,contact_person',
                'user:id,name',
                'status:id,name',
                'category:id,name'
            ])
            ->findOrFail($id);

            // Добавляем виртуальное поле name для клиента, если оно нужно
            if ($model->client) {
                $model->client->name = $model->client->first_name . ' ' . $model->client->last_name;
            }

            if (!method_exists($model, 'comments') || !method_exists($model, 'activities') || !method_exists($model, 'getKey')) {
                throw new \Exception('Модель не поддерживает комментарии или активность');
            }

            // Получаем комментарии с оптимизацией
            $comments = $this->getOptimizedComments($model);

            // Получаем активность с оптимизацией
            $activities = $this->getOptimizedActivities($model, $modelClass);

            // Специальная обработка для заказов
            if ($modelClass === \App\Models\Order::class) {
                $orderActivities = $this->getOrderSpecificActivities($id);
                $activities = $activities->merge($orderActivities);
            }

            // Объединяем и сортируем
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
     * Получение оптимизированных комментариев
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
     * Получение оптимизированной активности
     */
    private function getOptimizedActivities($model, string $modelClass)
    {
        return $model->activities()
            ->select([
                'activity_log.id', 'activity_log.description', 'activity_log.properties',
                'activity_log.causer_id', 'activity_log.created_at'
            ])
            ->with(['causer:id,name'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($log) use ($modelClass) {
                return $this->processActivityLog($log, $modelClass);
            });
    }

    /**
     * Обработка лога активности
     */
    private function processActivityLog($log, string $modelClass)
    {
        // Получаем пользователя из causer или пытаемся найти его другим способом
        $user = $this->getUserForActivity($log, $modelClass);

        if ($log->description === 'created' || $log->description === 'Создан заказ') {
            return [
                'type' => 'log',
                'id' => $log->id,
                'description' => $log->description,
                'changes' => null,
                'user' => $user,
                'created_at' => $log->created_at,
            ];
        }

        $changes = $this->processActivityChanges($log->properties, $modelClass);

        return [
            'type' => 'log',
            'id' => $log->id,
            'description' => $log->description,
            'changes' => $changes,
            'user' => $user,
            'created_at' => $log->created_at,
        ];
    }

    /**
     * Обработка изменений в активности
     */
    private function processActivityChanges($changes, string $modelClass)
    {
        if (!$changes) {
            return null;
        }

        $attributes = null;
        $old = null;

        // Извлекаем attributes и old из properties
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
            // Обработка полей с ID - заменяем на названия
            if (str_ends_with($key, '_id') && $value) {
                $relatedModel = $this->getRelatedModelName($key, $modelClass);

                if ($relatedModel && class_exists($relatedModel)) {
                    try {
                        // Специальная обработка для разных типов моделей
                        if ($relatedModel === \App\Models\Client::class) {
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
     * Получение специфичной активности для заказов
     */
    private function getOrderSpecificActivities(int $orderId)
    {
        $activities = collect();

        // Активность товаров заказа с оптимизацией
        $orderProductLogs = \App\Models\OrderProduct::select(['id', 'order_id', 'product_id'])
            ->where('order_id', $orderId)
            ->with(['product:id,name'])
            ->get()
            ->flatMap(function ($orderProduct) {
                return $orderProduct->activities()
                    ->select(['activity_log.id', 'activity_log.description', 'activity_log.causer_id', 'activity_log.created_at'])
                    ->with(['causer:id,name'])
                    ->latest()
                    ->limit(1)
                    ->get();
            })->map(function ($log) {
                return [
                    'type' => 'log',
                    'id' => $log->id,
                    'description' => $log->description,
                    'changes' => null,
                    'user' => $this->getUserForActivity($log, \App\Models\Order::class),
                    'created_at' => $log->created_at,
                ];
            })->filter();

        $activities = $activities->merge($orderProductLogs);

        // Активность временных товаров заказа с оптимизацией
        $orderTempProductLogs = \App\Models\OrderTempProduct::select(['id', 'order_id', 'name'])
            ->where('order_id', $orderId)
            ->get()
            ->flatMap(function ($orderTempProduct) {
                return $orderTempProduct->activities()
                    ->select(['activity_log.id', 'activity_log.description', 'activity_log.causer_id', 'activity_log.created_at'])
                    ->with(['causer:id,name'])
                    ->latest()
                    ->limit(1)
                    ->get();
            })->map(function ($log) {
                return [
                    'type' => 'log',
                    'id' => $log->id,
                    'description' => $log->description,
                    'changes' => null,
                    'user' => $this->getUserForActivity($log, \App\Models\Order::class),
                    'created_at' => $log->created_at,
                ];
            })->filter();

        $activities = $activities->merge($orderTempProductLogs);

        // Активность транзакций заказа с оптимизацией (через полиморфную связь)
        $orderTransactionLogs = \App\Models\Transaction::select(['id', 'source_id', 'source_type', 'amount'])
            ->where('source_type', \App\Models\Order::class)
            ->where('source_id', $orderId)
            ->get()
            ->flatMap(function ($transaction) {
                return $transaction->activities()
                    ->select(['activity_log.id', 'activity_log.description', 'activity_log.causer_id', 'activity_log.created_at'])
                    ->with(['causer:id,name'])
                    ->latest()
                    ->limit(1)
                    ->get();
            })->map(function ($log) {
                return [
                    'type' => 'log',
                    'id' => $log->id,
                    'description' => $log->description,
                    'changes' => null,
                    'user' => $this->getUserForActivity($log, \App\Models\Order::class),
                    'created_at' => $log->created_at,
                ];
            })->filter();

        $activities = $activities->merge($orderTransactionLogs);

        return $activities;
    }

    private function getRelatedModelName(string $key, string $modelClass): ?string
    {
        // Базовый маппинг полей на модели
        $baseFieldToModelMap = [
            'client_id' => \App\Models\Client::class,
            'user_id' => \App\Models\User::class,
            'product_id' => \App\Models\Product::class,
            'warehouse_id' => \App\Models\Warehouse::class,
            'project_id' => \App\Models\Project::class,
            'cash_register_id' => \App\Models\CashRegister::class,
            'cash_id' => \App\Models\CashRegister::class,
            'currency_id' => \App\Models\Currency::class,
        ];

        // Специфичные маппинги для разных типов сущностей
        $specificFieldToModelMap = [
            \App\Models\Order::class => [
                'category_id' => \App\Models\OrderCategory::class,
                'status_id' => \App\Models\OrderStatus::class,
            ],
            \App\Models\Transaction::class => [
                'category_id' => \App\Models\TransactionCategory::class,
            ],
            \App\Models\Sale::class => [
                'category_id' => \App\Models\Category::class,
            ],
        ];

        // Сначала проверяем специфичные маппинги для модели
        if (isset($specificFieldToModelMap[$modelClass]) && isset($specificFieldToModelMap[$modelClass][$key])) {
            return $specificFieldToModelMap[$modelClass][$key];
        }

        // Затем проверяем базовый маппинг
        return $baseFieldToModelMap[$key] ?? null;
    }

    /**
     * Получение пользователя для активности
     */
    private function getUserForActivity($log, string $modelClass)
    {
        // Если есть causer, используем его
        if ($log->causer) {
            return [
                'id' => $log->causer->id,
                'name' => $log->causer->name,
            ];
        }

        // Если causer_id есть, но связь не загружена, пытаемся найти пользователя
        if ($log->causer_id) {
            try {
                $user = \App\Models\User::select('id', 'name')->find($log->causer_id);
                if ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                    ];
                }
            } catch (\Exception $e) {
                // Игнорируем ошибки
            }
        }

        // Если это создание заказа, пытаемся найти пользователя из самого заказа
        if ($log->description === 'created' || $log->description === 'Создан заказ') {
            try {
                $subject = $log->subject;
                if ($subject && isset($subject->user_id) && $subject->user_id) {
                    $user = \App\Models\User::select('id', 'name')->find($subject->user_id);
                    if ($user) {
                        return [
                            'id' => $user->id,
                            'name' => $user->name,
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Игнорируем ошибки
            }
        }

        // В крайнем случае возвращаем null, чтобы фронтенд показал "Система"
        return null;
    }

    /**
     * Инвалидация кэша таймлайна
     */
    private function invalidateTimelineCache(string $type, int $id)
    {
        $cacheKey = "timeline_{$type}_{$id}";
        \Illuminate\Support\Facades\Cache::forget($cacheKey);

        // Также инвалидируем кэш комментариев
        $this->itemsRepository->invalidateCommentsCacheByType($type);
    }
}
