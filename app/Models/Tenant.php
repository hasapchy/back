<?php

namespace App\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Модель тенанта с поддержкой отдельной БД (TenantWithDatabase для CreateDatabase/MigrateDatabase).
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
}
