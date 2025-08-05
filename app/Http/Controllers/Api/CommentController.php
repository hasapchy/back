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
                if ($modelClass === \App\Models\Order::class && $log->description === 'updated' && is_array($changes?->attributes ?? null)) {
                    $attrs = $changes['attributes'] ?? [];
                    $old = $changes['old'] ?? [];

                    $processedAttrs = [];
                    $processedOld = [];

                    foreach ($attrs as $key => $value) {
                        if ($key === 'total_price') {
                            $processedAttrs[$key] = $value;
                            $processedOld[$key] = $old[$key] ?? null;
                        } elseif ($key === 'client_id') {
                            $clientName = $value ? \App\Models\Client::find($value)?->name : null;
                            $oldClientName = $old[$key] ? \App\Models\Client::find($old[$key])?->name : null;
                            $processedAttrs[$key] = $clientName ?? $value;
                            $processedOld[$key] = $oldClientName ?? $old[$key] ?? null;
                        } elseif ($key === 'status_id') {
                            $statusName = $value ? \App\Models\OrderStatus::find($value)?->name : null;
                            $oldStatusName = $old[$key] ? \App\Models\OrderStatus::find($old[$key])?->name : null;
                            $processedAttrs[$key] = $statusName ?? $value;
                            $processedOld[$key] = $oldStatusName ?? $old[$key] ?? null;
                        } elseif ($key === 'category_id') {
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

            if ($modelClass === \App\Models\Order::class) {
                $orderId = is_object($model) ? $model->id : $model['id'] ?? null;
                if (!$orderId) {
                    return response()->json(['message' => 'Не удалось получить ID заказа'], 400);
                }

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

                $orderProductLogs = collect($orderProductLogs);

                $activities = $activities->merge($orderProductLogs);
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
}
