<?php

namespace App\Policies;

use App\Models\User;

class AppPolicy
{
    public function __call($method, $arguments)
    {
        /** @var User $user */
        $user = $arguments[0];

        return $user->can($method);
    }
}
