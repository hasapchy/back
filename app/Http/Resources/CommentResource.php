<?php

namespace App\Http\Resources;

use App\Models\Comment;
use App\Services\ReactionToggleService;
use App\Services\Timeline\TimelineUserFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    /**
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        if (is_array($this->resource)) {
            return $this->resource;
        }

        if (! $this->resource instanceof Comment) {
            return (array) $this->resource;
        }

        /** @var Comment $comment */
        $comment = $this->resource;

        $parent = null;
        if ($comment->parent_id && $comment->relationLoaded('parent') && $comment->parent) {
            $parent = [
                'id' => (int) $comment->parent->id,
                'body' => $comment->parent->body,
                'user' => $comment->parent->relationLoaded('creator') && $comment->parent->creator
                    ? TimelineUserFormatter::toArray($comment->parent->creator)
                    : null,
            ];
        }

        $replies = [];
        if ($comment->relationLoaded('replies')) {
            $replies = $comment->replies->map(function (Comment $reply) {
                $payload = (new self($reply))->toArray(request());

                return is_array($payload) ? $payload : [];
            })->values()->all();
        }

        $reactions = [];
        if ($comment->relationLoaded('reactions')) {
            $reactions = app(ReactionToggleService::class)->formatReactionCollection($comment->reactions);
        }

        $viewedBy = $comment->getAttribute('viewed_by');
        if (! is_array($viewedBy)) {
            $viewedBy = [];
        }

        return [
            'id' => (int) $comment->id,
            'body' => $comment->body,
            'parent_id' => $comment->parent_id ? (int) $comment->parent_id : null,
            'parent' => $parent,
            'creator_id' => (int) $comment->creator_id,
            'user' => $comment->relationLoaded('creator') && $comment->creator
                ? TimelineUserFormatter::toArray($comment->creator)
                : null,
            'replies' => $replies,
            'reactions' => $reactions,
            'viewed_by' => $viewedBy,
            'commentable_type' => $comment->commentable_type,
            'commentable_id' => (int) $comment->commentable_id,
            'created_at' => $comment->created_at?->toISOString(),
            'updated_at' => $comment->updated_at?->toISOString(),
        ];
    }
}
