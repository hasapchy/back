<?php

namespace App\Support;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Collection;

class ClientBalanceViewAccess
{
    public const TYPE_NON_CASH = 0;

    public const TYPE_CASH = 1;

    public const PERM_VIEW = 'settings_client_balance_view';

    public const PERM_VIEW_OWN = 'settings_client_balance_view_own';

    public const PERM_VIEW_CASH = 'settings_client_balance_view_cash';

    public const PERM_VIEW_NON_CASH = 'settings_client_balance_view_non_cash';

    /**
     * @return array<int, int>
     */
    public static function getAllowedBalanceTypes(?User $user, ?int $companyId = null): array
    {
        if (! $user || $user->is_admin) {
            return $user ? [self::TYPE_NON_CASH, self::TYPE_CASH] : [];
        }

        $permissionNames = self::permissionNamesForUser($user, $companyId);

        if (! in_array(self::PERM_VIEW, $permissionNames, true)
            && ! in_array(self::PERM_VIEW_OWN, $permissionNames, true)) {
            return [];
        }

        return self::allowedTypesFromPermissionNames($permissionNames);
    }

    /**
     * @param  array<int, string>  $permissionNames
     * @return array<int, int>
     */
    public static function allowedTypesFromPermissionNames(array $permissionNames): array
    {
        $hasCash = in_array(self::PERM_VIEW_CASH, $permissionNames, true);
        $hasNonCash = in_array(self::PERM_VIEW_NON_CASH, $permissionNames, true);

        if (! $hasCash && ! $hasNonCash) {
            return [self::TYPE_NON_CASH, self::TYPE_CASH];
        }

        $allowed = [];
        if ($hasNonCash) {
            $allowed[] = self::TYPE_NON_CASH;
        }
        if ($hasCash) {
            $allowed[] = self::TYPE_CASH;
        }

        return $allowed;
    }

    public static function canViewBalanceType(?User $user, int $type, ?int $companyId = null): bool
    {
        return in_array($type, self::getAllowedBalanceTypes($user, $companyId), true);
    }

    public static function userCanBeAssignedToBalance(User $user, int $balanceType, ?int $companyId = null): bool
    {
        if ($user->is_admin) {
            return true;
        }

        $permissionNames = self::permissionNamesForUser($user, $companyId);

        if (! in_array(self::PERM_VIEW, $permissionNames, true)) {
            return false;
        }

        return in_array($balanceType, self::allowedTypesFromPermissionNames($permissionNames), true);
    }

    /**
     * @param  array<int, int|string>  $userIds
     * @return array<int, string>
     */
    public static function validateAssigneeUserIds(array $userIds, int $balanceType, ?int $companyId = null): array
    {
        if ($userIds === []) {
            return [];
        }

        $invalid = [];
        $users = User::query()->whereIn('id', $userIds)->get();

        foreach ($users as $user) {
            if (! self::userCanBeAssignedToBalance($user, $balanceType, $companyId)) {
                $invalid[] = (string) $user->id;
            }
        }

        return $invalid;
    }

    /**
     * @param  Collection<int, \App\Models\ClientBalance>  $balances
     * @return Collection<int, \App\Models\ClientBalance>
     */
    public static function visibleDefaultBalanceValue(Client $client, ?User $user = null, ?int $companyId = null): float
    {
        $user = $user ?? auth('api')->user();
        $balances = $client->relationLoaded('balances')
            ? $client->balances
            : $client->balances()->with('currency')->get();
        $filtered = self::filterBalancesForUser($balances, $user, $companyId);
        $visibleDefault = $filtered->firstWhere('is_default', true) ?? $filtered->first();

        return $visibleDefault ? (float) $visibleDefault->balance : 0.0;
    }

    public static function filterBalancesForUser(Collection $balances, ?User $user, ?int $companyId = null): Collection
    {
        if (! $user) {
            return collect();
        }

        if ($user->is_admin) {
            return $balances;
        }

        $allowedTypes = self::getAllowedBalanceTypes($user, $companyId);

        return $balances->filter(function ($balance) use ($user, $allowedTypes) {
            if (! in_array((int) $balance->type, $allowedTypes, true)) {
                return false;
            }

            return $balance->isVisibleToUser($user);
        });
    }

    /**
     * @return array<int, string>
     */
    private static function permissionNamesForUser(User $user, ?int $companyId): array
    {
        $resolvedCompanyId = $companyId ?? ResolvedCompany::fromRequest(request());

        if ($resolvedCompanyId) {
            return $user->getAllPermissionsForCompany((int) $resolvedCompanyId)
                ->pluck('name')
                ->all();
        }

        return $user->getAllPermissions()->pluck('name')->all();
    }
}
