<?php

namespace App\Models\Traits;

trait HasManyToManyUsers
{
    /**
     * Accessor для получения пользователей
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUsersAttribute()
    {
        $relation = $this->getRelationValue('users');

        if ($relation !== null) {
            return $relation;
        }

        return $this->users()->get();
    }

    /**
     * Проверить, есть ли у сущности пользователь
     *
     * @param int $userId ID пользователя
     * @return bool
     */
    public function hasUser($userId)
    {
        return $this->users()->wherePivot('creator_id', $userId)->exists();
    }
}

