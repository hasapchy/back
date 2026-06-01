<?php

namespace Tests\Unit;

use App\Support\ClientBalanceViewAccess;
use Tests\TestCase;

class ClientBalanceViewAccessTest extends TestCase
{
    public function test_allowed_types_when_no_type_permissions_returns_both(): void
    {
        $allowed = ClientBalanceViewAccess::allowedTypesFromPermissionNames([
            ClientBalanceViewAccess::PERM_VIEW,
        ]);

        $this->assertEquals(
            [ClientBalanceViewAccess::TYPE_NON_CASH, ClientBalanceViewAccess::TYPE_CASH],
            $allowed
        );
    }

    public function test_allowed_types_when_only_cash_permission(): void
    {
        $allowed = ClientBalanceViewAccess::allowedTypesFromPermissionNames([
            ClientBalanceViewAccess::PERM_VIEW,
            ClientBalanceViewAccess::PERM_VIEW_CASH,
        ]);

        $this->assertSame([ClientBalanceViewAccess::TYPE_CASH], $allowed);
    }

    public function test_allowed_types_when_only_non_cash_permission(): void
    {
        $allowed = ClientBalanceViewAccess::allowedTypesFromPermissionNames([
            ClientBalanceViewAccess::PERM_VIEW,
            ClientBalanceViewAccess::PERM_VIEW_NON_CASH,
        ]);

        $this->assertSame([ClientBalanceViewAccess::TYPE_NON_CASH], $allowed);
    }
}
