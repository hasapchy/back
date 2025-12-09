<?php

namespace App\Services;

use App\Models\User;

class PermissionCheckService
{
    /**
     * Проверить, может ли пользователь выполнить действие с записью
     *
     * @param User $user
     * @param string $resource
     * @param string $action
     * @param mixed $record
     * @param array $userPermissions
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
     * Проверить доступ к своей записи
     *
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

        $config = config("permissions.resources.{$resource}");
        $strategy = $config['check_strategy'] ?? 'default';

        if ($strategy === 'many_to_many') {
            if (method_exists($record, 'hasUser')) {
                return $record->hasUser($user->id);
            }
            if (method_exists($record, 'users')) {
                return $record->users()->where('user_id', $user->id)->exists();
            }
            return false;
        }

        if ($strategy === 'user_id' || $strategy === 'default') {
            if ($resource === 'users' && method_exists($record, 'getKey')) {
                return $record->getKey() === $user->id;
            }
            $userId = $record->user_id ?? null;
            return $userId && $userId === $user->id;
        }

        return true;
    }
}
