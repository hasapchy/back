<?php

namespace App\Services;

use App\Events\CommentReactionUpdated;
use App\Events\NewsReactionUpdated;
use App\Models\Comment;
use App\Models\CommentReaction;
use App\Models\NewsReaction;

class EngagementReactionService
{
    public function __construct(
        protected ReactionToggleService $reactionToggleService,
    ) {}

    /**
     * @return list<array{emoji: string, creator_id: int, user: array<string, mixed>|null}>
     */
    public function setNewsReaction(int $companyId, int $newsId, int $userId, ?string $emoji): array
    {
        $this->reactionToggleService->toggle(
            NewsReaction::class,
            'news_id',
            $newsId,
            $userId,
            $emoji
        );

        $reactions = $this->reactionToggleService->formatReactions(
            NewsReaction::class,
            'news_id',
            $newsId
        );

        event(new NewsReactionUpdated($companyId, $newsId, $reactions));

        return $reactions;
    }

    /**
     * @return list<array{emoji: string, creator_id: int, user: array<string, mixed>|null}>
     */
    public function setCommentReaction(int $companyId, int $newsId, int $commentId, int $userId, ?string $emoji): array
    {
        $this->reactionToggleService->toggle(
            CommentReaction::class,
            'comment_id',
            $commentId,
            $userId,
            $emoji
        );

        $reactions = $this->reactionToggleService->formatReactions(
            CommentReaction::class,
            'comment_id',
            $commentId
        );

        event(new CommentReactionUpdated($companyId, $newsId, $commentId, $reactions));

        return $reactions;
    }

    /**
     * @return list<array{emoji: string, creator_id: int, user: array<string, mixed>|null}>
     */
    public function setCommentReactionForModel(int $companyId, Comment $comment, int $userId, ?string $emoji): array
    {
        return $this->setCommentReaction(
            $companyId,
            (int) $comment->commentable_id,
            (int) $comment->id,
            $userId,
            $emoji
        );
    }
}
