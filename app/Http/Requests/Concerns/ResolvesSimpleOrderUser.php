<?php

namespace App\Http\Requests\Concerns;

use App\Support\SimpleUser;

trait ResolvesSimpleOrderUser
{
    protected function isSimpleOrderUser(): bool
    {
        return SimpleUser::matches(auth('api')->user());
    }
}
