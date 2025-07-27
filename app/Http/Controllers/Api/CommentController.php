<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\CommentsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
            Log::info('Timeline request', [
                'type' => $request->type,
                'id' => $request->id
            ]);

            $modelClass = $this->itemsRepository->resolveType($request->type);
            Log::info('Model class resolved', ['model_class' => $modelClass]);

            try {
                                // Загружаем модель с связанными данными
                $model = $modelClass::with(['client', 'user', 'status', 'category'])->findOrFail($request->id);
                Log::info('Model found', [
                    'model_id' => $model->id ?? 'unknown',
                    'model_type' => get_class($model)
                ]);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                Log::error('Model not found', [
                    'type' => $request->type,
                    'id' => $request->id,
                    'model_class' => $modelClass
                ]);
                return response()->json(['message' => 'Заказ не найден'], 404);
            }

            // Проверяем, что модель найдена и является объектом
            if (!$model || !is_object($model)) {
                return response()->json(['message' => 'Модель не найдена'], 404);
            }

            // Проверяем, что у модели есть необходимые методы
            if (!method_exists($model, 'comments') || !method_exists($model, 'activities') || !method_exists($model, 'getKey')) {
                return response()->json(['message' => 'Модель не поддерживает комментарии или активность'], 400);
            }

            Log::info('Model methods check passed', [
                'has_comments' => method_exists($model, 'comments'),
                'has_activities' => method_exists($model, 'activities'),
                'has_getKey' => method_exists($model, 'getKey')
            ]);

            // \Log::info('Model loaded', [
            //     'model_id' => $model->getKey(),
            //     'model_class' => get_class($model),
            // ]);

            // комментарии
            $comments = $model->comments()->with('user')->get()->map(function ($comment) {
                return [
                    'type' => 'comment',
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'user' => $comment->user,
                    'created_at' => $comment->created_at,
                ];
            });

            // логи активности
            $activities = $model->activities()->with('causer')->get()->map(function ($log) use ($modelClass) {
                // Для created и 'Создан заказ' не показываем изменения
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
                // Фильтрация только для заказов и только для updated
                if ($modelClass === \App\Models\Order::class && $log->description === 'updated' && is_array($changes?->attributes ?? null)) {
                    $attrs = $changes['attributes'] ?? [];
                    $old = $changes['old'] ?? [];

                    // Преобразуем ID в названия для связанных полей
                    $processedAttrs = [];
                    $processedOld = [];

                    foreach ($attrs as $key => $value) {
                        if ($key === 'total_price') {
                            $processedAttrs[$key] = $value;
                            $processedOld[$key] = $old[$key] ?? null;
                        } elseif ($key === 'client_id') {
                            // Получаем название клиента
                            $clientName = $value ? \App\Models\Client::find($value)?->name : null;
                            $oldClientName = $old[$key] ? \App\Models\Client::find($old[$key])?->name : null;
                            $processedAttrs[$key] = $clientName ?? $value;
                            $processedOld[$key] = $oldClientName ?? $old[$key] ?? null;
                        } elseif ($key === 'status_id') {
                            // Получаем название статуса
                            $statusName = $value ? \App\Models\OrderStatus::find($value)?->name : null;
                            $oldStatusName = $old[$key] ? \App\Models\OrderStatus::find($old[$key])?->name : null;
                            $processedAttrs[$key] = $statusName ?? $value;
                            $processedOld[$key] = $oldStatusName ?? $old[$key] ?? null;
                        } elseif ($key === 'category_id') {
                            // Получаем название категории
                            $categoryName = $value ? \App\Models\OrderCategory::find($value)?->name : null;
                            $oldCategoryName = $old[$key] ? \App\Models\OrderCategory::find($old[$key])?->name : null;
                            $processedAttrs[$key] = $categoryName ?? $value;
                            $processedOld[$key] = $oldCategoryName ?? $old[$key] ?? null;
                        } else {
                            $processedAttrs[$key] = $value;
                            $processedOld[$key] = $old[$key] ?? null;
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

            // Логи товаров заказа
            if ($modelClass === \App\Models\Order::class) {
                // Получаем только последние логи для товаров заказа, чтобы избежать дублирования
                $orderId = is_object($model) ? $model->id : $model['id'] ?? null;
                if (!$orderId) {
                    return response()->json(['message' => 'Не удалось получить ID заказа'], 400);
                }

                $orderProductLogs = \App\Models\OrderProduct::where('order_id', $orderId)
                    ->with('product')
                    ->get()
                    ->flatMap(function ($orderProduct) {
                        // Получаем только последний лог для каждого товара
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
                            // Только если НЕ order_product, добавляем changes
                            'changes' => $isOrderProduct ? null : $log->properties,
                            'user' => $log->causer ? [
                                'id' => $log->causer->id,
                                'name' => $log->causer->name,
                            ] : null,
                            'created_at' => $log->created_at,
                        ];
                    })->filter()->values(); // Фильтруем пустые значения и преобразуем в коллекцию

                // Преобразуем в коллекцию перед объединением
                $orderProductLogs = collect($orderProductLogs);

                Log::info('OrderProductLogs prepared', [
                    'count' => $orderProductLogs->count(),
                    'type' => get_class($orderProductLogs)
                ]);

                $activities = $activities->merge($orderProductLogs);
            }


            // Преобразуем в коллекции перед объединением
            $comments = collect($comments);
            $activities = collect($activities);

            Log::info('Collections prepared', [
                'comments_count' => $comments->count(),
                'activities_count' => $activities->count(),
                'comments_type' => get_class($comments),
                'activities_type' => get_class($activities),
                'comments_sample' => $comments->first(),
                'activities_sample' => $activities->first()
            ]);

            // объединяем и сортируем по дате (сначала старые, потом новые)
            $timeline = $comments->merge($activities)
                ->sortBy(function ($item) {
                    return \Carbon\Carbon::parse($item['created_at']);
                })
                ->values();


            return response()->json($timeline);
        } catch (\Throwable $e) {
            Log::error('Ошибка в timeline(): ' . $e->getMessage(), [
                'type' => $request->type,
                'id' => $request->id,
                'model_type' => $modelClass ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Ошибка загрузки таймлайна', 'error' => $e->getMessage()], 500);
        }
    }
}
