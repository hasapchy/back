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

    public function createItem(string $type, int $id, string $body, int $userId)
    {
        $modelClass = $this->resolveType($type);

        return Comment::create([
            'body' => $body,
            'user_id' => $userId,
            'commentable_type' => $modelClass,
            'commentable_id' => $id,
        ]) -> load('user');;
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
            default => throw new \InvalidArgumentException("Unknown commentable type: $type"),
        };
    }
}
