<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Role с подключением к центральной БД (roles только в central).
 */
class Role extends SpatieRole
{
    protected $connection = 'central';
}
