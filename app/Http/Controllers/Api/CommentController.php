<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\CommentsRepository;
use Illuminate\Http\Request;

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
            $model = $modelClass::findOrFail($request->id);
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
            $activities = $model->activities()->with('causer')->get()->map(function ($log) {
                return [
                    'type' => 'log',
                    'id' => $log->id,
                    'description' => $log->description,
                    'changes' => $log->description === 'created' ? null : $log->properties,
                    'user' => $log->causer ? [
                        'id' => $log->causer->id,
                        'name' => $log->causer->name,
                    ] : null,
                    'created_at' => $log->created_at,
                ];
            });


            // объединяем и сортируем по дате
            $timeline = $comments->merge($activities)
                ->sortByDesc(function ($item) {
                    return \Carbon\Carbon::parse($item['created_at']);
                })
                ->values();


            return response()->json($timeline);
        } catch (\Throwable $e) {
            \Log::error('Ошибка в timeline(): ' . $e->getMessage(), [
                'type' => $request->type,
                'id' => $request->id,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Ошибка загрузки таймлайна', 'error' => $e->getMessage()], 500);
        }
    }
}
