<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\CommentsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

        return response()->json([
            'message' => 'Комментарий обновлён',
            'comment' => $updatedComment,
        ]);
    }


    public function destroy($id)
    {
        $user = auth('api')->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $deleted = $this->itemsRepository->deleteItem($id, $user->id);

        if (! $deleted) {
            return response()->json(['message' => 'Комментарий не найден или нет прав'], 403);
        }

        return response()->json(['message' => 'Комментарий удалён']);
    }

    public function timeline(Request $request)
    {
        $user = auth('api')->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'type' => 'required|string',
            'id' => 'required|integer',
        ]);

        try {
            $modelClass = $this->itemsRepository->resolveType($request->type);

            try {
                $model = $modelClass::with(['client', 'user', 'status', 'category'])->findOrFail($request->id);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                return response()->json(['message' => 'Заказ не найден'], 404);
            }

            if (!$model || !is_object($model)) {
                return response()->json(['message' => 'Модель не найдена'], 404);
            }

            if (!method_exists($model, 'comments') || !method_exists($model, 'activities') || !method_exists($model, 'getKey')) {
                return response()->json(['message' => 'Модель не поддерживает комментарии или активность'], 400);
            }

            $comments = $model->comments()->with('user')->get()->map(function ($comment) {
                return [
                    'type' => 'comment',
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'user' => $comment->user,
                    'created_at' => $comment->created_at,
                ];
            });

            $activities = $model->activities()->with('causer')->get()->map(function ($log) use ($modelClass) {
                if ($log->description === 'created' || $log->description === 'Создан заказ') {
                    return [
                        'type' => 'log',
                        'id' => $log->id,
                        'description' => $log->description,
                        'changes' => null,
                        'user' => $log->causer ? [
                            'id' => $log->causer->id,
                            'name' => $log->causer->name,
                        ] : null,
                        'created_at' => $log->created_at,
                    ];
                }

                $changes = $log->properties;

                // Обрабатываем изменения для всех событий, не только updated
                // Проверяем, что properties существует и содержит attributes
                if ($changes) {
                    $attributes = null;
                    $old = null;

                    // Если это коллекция, преобразуем в массив
                    if (method_exists($changes, 'toArray')) {
                        $changesArray = $changes->toArray();
                        $attributes = $changesArray['attributes'] ?? null;
                        $old = $changesArray['old'] ?? null;
                    }
                    // Если это объект, пытаемся получить attributes
                    elseif (is_object($changes)) {
                        $attributes = $changes->attributes ?? null;
                        $old = $changes->old ?? null;
                    }
                    // Если это массив
                    elseif (is_array($changes)) {
                        $attributes = $changes['attributes'] ?? null;
                        $old = $changes['old'] ?? null;
                    }

                    if (is_array($attributes)) {
                        $processedAttrs = [];
                        $processedOld = [];

                        foreach ($attributes as $key => $value) {
                            // Обработка полей с ID - заменяем на названия
                            if (str_ends_with($key, '_id') && $value) {
                                $relatedModel = $this->getRelatedModelName($key, $modelClass);

                                if ($relatedModel && class_exists($relatedModel)) {
                                    try {
                                        $relatedName = $relatedModel::find($value)?->name ?? $value;
                                        $oldRelatedName = $old && isset($old[$key]) ? ($relatedModel::find($old[$key])?->name ?? $old[$key]) : null;
                                        $processedAttrs[$key] = $relatedName;
                                        $processedOld[$key] = $oldRelatedName;
                                    } catch (\Exception $e) {
                                        // Если что-то пошло не так, используем исходные значения
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
                            $changes = [
                                'attributes' => $processedAttrs,
                                'old' => $processedOld,
                            ];
                        } else {
                            $changes = null;
                        }
                    }
                }

                return [
                    'type' => 'log',
                    'id' => $log->id,
                    'description' => $log->description,
                    'changes' => $changes,
                    'user' => $log->causer ? [
                        'id' => $log->causer->id,
                        'name' => $log->causer->name,
                    ] : null,
                    'created_at' => $log->created_at,
                ];
            });

            if ($modelClass === \App\Models\Order::class) {
                $orderId = is_object($model) ? $model->id : $model['id'] ?? null;
                if (!$orderId) {
                    return response()->json(['message' => 'Не удалось получить ID заказа'], 400);
                }

                // Активность товаров заказа
                $orderProductLogs = \App\Models\OrderProduct::where('order_id', $orderId)
                    ->with('product')
                    ->get()
                    ->flatMap(function ($orderProduct) {
                        return $orderProduct->activities()
                            ->with('causer')
                            ->latest()
                            ->limit(1)
                            ->get();
                    })->map(function ($log) {
                        $isOrderProduct = $log->log_name === 'order_product';

                        return [
                            'type' => 'log',
                            'id' => $log->id,
                            'description' => $log->description,
                            'changes' => $isOrderProduct ? null : $log->properties,
                            'user' => $log->causer ? [
                                'id' => $log->causer->id,
                                'name' => $log->causer->name,
                            ] : null,
                            'created_at' => $log->created_at,
                        ];
                    })->filter()->values();

                // Активность транзакций заказа
                $orderTransactionLogs = \App\Models\OrderTransaction::where('order_id', $orderId)
                    ->with('transaction')
                    ->get()
                    ->flatMap(function ($orderTransaction) {
                        return $orderTransaction->activities()
                            ->with('causer')
                            ->latest()
                            ->limit(1)
                            ->get();
                    })->map(function ($log) {
                        return [
                            'type' => 'log',
                            'id' => $log->id,
                            'description' => $log->description,
                            'changes' => null,
                            'user' => $log->causer ? [
                                'id' => $log->causer->id,
                                'name' => $log->causer->name,
                            ] : null,
                            'created_at' => $log->created_at,
                        ];
                    })->filter()->values();

                // Активность транзакций, связанных с заказом
                $transactionIds = \App\Models\OrderTransaction::where('order_id', $orderId)
                    ->pluck('transaction_id')
                    ->toArray();

                // Активность удаленных транзакций
                $transactionLogs = Activity::where('subject_type', \App\Models\Transaction::class)
                    ->where('description', 'Транзакция удалена')
                    ->where('created_at', '>=', now()->subHours(1)) // Ищем за последний час
                    ->with('causer')
                    ->get()
                    ->map(function ($log) {
                        return [
                            'type' => 'log',
                            'id' => $log->id,
                            'description' => 'Транзакция удалена из заказа',
                            'changes' => null,
                            'user' => $log->causer ? [
                                'id' => $log->causer->id,
                                'name' => $log->causer->name,
                            ] : null,
                            'created_at' => $log->created_at,
                        ];
                    })->values();

                $orderProductLogs = collect($orderProductLogs);
                $orderTransactionLogs = collect($orderTransactionLogs);
                $transactionLogs = collect($transactionLogs);

                $activities = $activities->merge($orderProductLogs)->merge($orderTransactionLogs)->merge($transactionLogs);
            }

            $comments = collect($comments);
            $activities = collect($activities);

            $timeline = $comments->merge($activities)
                ->sortBy(function ($item) {
                    return \Carbon\Carbon::parse($item['created_at']);
                })
                ->values();


            return response()->json($timeline);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Ошибка загрузки таймлайна', 'error' => $e->getMessage()], 500);
        }
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
}
