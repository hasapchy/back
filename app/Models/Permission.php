<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Permission с подключением к центральной БД (permissions только в central).
 */
class Permission extends SpatiePermission
{
    protected $connection = 'central';
}
