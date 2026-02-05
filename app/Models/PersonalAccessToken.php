<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * PersonalAccessToken с подключением к центральной БД (токены привязаны к users в central).
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $connection = 'central';
}
