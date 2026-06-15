<?php

namespace App\Services\Timeline;

use App\Models\User;

class TimelineUserFormatter
{
    public const SELECT_COLUMNS = 'id,name,surname,photo';

    /**
     * @return array{id: int, name: string, surname: string, photo: string|null}|null
     */
    public static function toArray(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'name' => (string) ($user->name ?? ''),
            'surname' => (string) ($user->surname ?? ''),
            'photo' => $user->photo,
        ];
    }
}
