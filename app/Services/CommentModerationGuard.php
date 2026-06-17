<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\News;
use App\Models\User;

class CommentModerationGuard
{
    /**
     * @param User $user
     * @param Comment $comment
     * @param int $companyId
     * @return bool
     */
    public function canDelete(User $user, Comment $comment, int $companyId): bool
    {
        if ((int) $comment->creator_id === (int) $user->id) {
            return true;
        }

        if ($user->is_admin) {
            return true;
        }

        if ($comment->commentable_type !== News::class) {
            return false;
        }

        return $user->getAllPermissionsForCompany($companyId)->pluck('name')->contains('news_delete_all');
    }

    /**
     * @param User $user
     * @param Comment $comment
     * @return bool
     */
    public function canUpdate(User $user, Comment $comment): bool
    {
        return (int) $comment->creator_id === (int) $user->id;
    }
}
