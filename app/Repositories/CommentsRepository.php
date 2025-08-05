<?php

namespace App\Repositories;

use App\Models\Comment;

class CommentsRepository
{
    public function getCommentsFor(string $type, int $id)
    {
        $modelClass = $this->resolveType($type);

        return Comment::with('user')
            ->where('commentable_type', $modelClass)
            ->where('commentable_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function createItem(string $type, int $id, string $body, int $userId): array
    {
        $modelClass = $this->resolveType($type);

        $comment = Comment::create([
            'body' => $body,
            'user_id' => $userId,
            'commentable_type' => $modelClass,
            'commentable_id' => $id,
        ])->load('user');

        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'commentable_type' => $comment->commentable_type,
            'commentable_id' => $comment->commentable_id,
            'created_at' => $comment->created_at,
            'updated_at' => $comment->updated_at,
            'user_id' => $comment->user_id,
            'user' => [
                'id' => $comment->user->id,
                'name' => $comment->user->name,
            ],
        ];
    }


    public function updateItem(int $id, int $userId, string $body)
    {
        $comment = Comment::where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (! $comment) {
            return false;
        }

        $comment->body = $body;
        $comment->save();

        return $comment->load('user');
    }


    public function deleteItem(int $id, int $userId)
    {
        $comment = Comment::where('id', $id)->where('user_id', $userId)->first();

        if (! $comment) {
            return false;
        }

        return $comment->delete();
    }

    public function resolveType(string $type): string
    {
        return match ($type) {
            'order' => \App\Models\Order::class,
            'sale' => \App\Models\Sale::class,
            'transaction' => \App\Models\Transaction::class,
            'order_transaction' => \App\Models\OrderTransaction::class,
            default => throw new \InvalidArgumentException("Unknown commentable type: $type"),
        };
    }
}
