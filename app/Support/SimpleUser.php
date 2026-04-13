<?php

namespace App\Support;

use App\Models\Category;
use App\Models\User;

final class SimpleUser
{
    public static function matches(?User $user): bool
    {
        return $user instanceof User && ! $user->is_admin && (bool) $user->is_simple_user;
    }

    public static function orderAccess(?User $user): array
    {
        $isSimple = self::matches($user);

        return [
            'resource' => $isSimple ? 'orders_simple' : 'orders',
            'is_simple' => $isSimple,
        ];
    }

    public static function ordersPermissionResource(?User $user): string
    {
        return self::orderAccess($user)['resource'];
    }

    /**
     * ID корневой категории заказов для simple в контексте текущей компании из запроса.
     */
    public static function rootCategoryIdForCurrentCompany(?User $user): ?int
    {
        if (! self::matches($user) || ! $user->simple_category_id) {
            return null;
        }

        $rootId = (int) $user->simple_category_id;
        $companyId = ResolvedCompany::fromRequest(request());
        $q = Category::query()->whereKey($rootId);
        if ($companyId !== null) {
            $q->where('company_id', $companyId);
        }

        $id = $q->value('id');

        return $id !== null ? (int) $id : null;
    }
}
