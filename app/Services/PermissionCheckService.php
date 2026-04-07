<?php

namespace App\Services;

use App\Models\User;

class PermissionCheckService
{
    /**
     * @param User $user
     * @param string $resource
     * @param string $action
     * @param mixed $record
     * @param array<int, string> $userPermissions
     * @return bool
     */
    public function canPerformAction(User $user, string $resource, string $action, $record = null, array $userPermissions = []): bool
    {
        if ($user->is_admin) {
            return true;
        }

        $allPermission = PermissionParser::generate($resource, $action, 'all');
        if (in_array($allPermission, $userPermissions)) {
            return true;
        }

        $ownPermission = PermissionParser::generate($resource, $action, 'own');
        if (in_array($ownPermission, $userPermissions)) {
            return $this->checkOwnAccess($user, $resource, $record);
        }

        $legacyPermission = PermissionParser::generate($resource, $action);
        if (in_array($legacyPermission, $userPermissions)) {
            return true;
        }

        return false;
    }

    /**
     * @param User $user
     * @param string $resource
     * @param mixed $record
     * @return bool
     */
    private function checkOwnAccess(User $user, string $resource, $record): bool
    {
        if (!$record) {
            return true;
        }

        $config = config("permissions.resources.{$resource}") ?? [];
        $strategy = $config['check_strategy'] ?? 'default';

        if ($strategy === 'many_to_many') {
            return method_exists($record, 'hasUser') && $record->hasUser($user->id);
        }

        if ($resource === 'users' && method_exists($record, 'getKey')) {
            return (int) $record->getKey() === (int) $user->id;
        }

        $requireOwner = (bool) ($config['require_owner_for_own'] ?? false);

        if ($strategy === 'user_id') {
            return $this->userMatchesOwnOwner($user, $record->user_id ?? $record->creator_id ?? null, $requireOwner);
        }

        if ($strategy === 'creator_id' || $strategy === 'default') {
            return $this->userMatchesOwnOwner($user, $record->creator_id ?? $record->user_id ?? null, $requireOwner);
        }

        return true;
    }

    /**
     * @param User $user
     * @param mixed $ownerId
     * @param bool $requireOwner
     * @return bool
     */
    private function userMatchesOwnOwner(User $user, $ownerId, bool $requireOwner): bool
    {
        if ($ownerId === null) {
            return !$requireOwner;
        }

        return (int) $ownerId === (int) $user->id;
    }
}
