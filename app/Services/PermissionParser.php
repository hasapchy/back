<?php

namespace App\Services;

class PermissionParser
{
    /**
     * Парсит имя права и возвращает структурированные данные
     *
     * @param string $permissionName
     * @return array|null Возвращает null если право не соответствует стандартному формату
     */
    public static function parse(string $permissionName): ?array
    {
        if (empty($permissionName)) {
            return null;
        }

        if (str_starts_with($permissionName, 'settings_')) {
            return [
                'type' => 'custom',
                'category' => 'settings',
                'name' => $permissionName,
                'resource' => null,
                'action' => null,
                'scope' => null,
            ];
        }

        $parts = explode('_', $permissionName);

        if (count($parts) < 2) {
            return null;
        }

        $lastPart = $parts[count($parts) - 1];
        $secondLastPart = $parts[count($parts) - 2] ?? null;

        if ($lastPart === 'all' || $lastPart === 'own') {
            $scope = $lastPart;
            $action = $secondLastPart;
            $resource = implode('_', array_slice($parts, 0, -2));
        } elseif ($lastPart === 'create') {
            $scope = null;
            $action = 'create';
            $resource = implode('_', array_slice($parts, 0, -1));
        } elseif (in_array($lastPart, ['view', 'update', 'delete'])) {
            $scope = null;
            $action = $lastPart;
            $resource = implode('_', array_slice($parts, 0, -1));
        } else {
            $resourceConfig = config('permissions.resources');
            foreach ($resourceConfig as $resourceName => $config) {
                if (isset($config['custom_permissions'])) {
                    foreach ($config['custom_permissions'] as $key => $customPermName) {
                        if ($customPermName === $permissionName) {
                            return [
                                'type' => 'custom',
                                'category' => 'resource_custom',
                                'name' => $permissionName,
                                'resource' => $resourceName,
                                'action' => $key,
                                'scope' => null,
                            ];
                        }
                    }
                }
            }

            return null;
        }

        return [
            'type' => 'standard',
            'category' => 'resource',
            'name' => $permissionName,
            'resource' => $resource,
            'action' => $action,
            'scope' => $scope,
        ];
    }

    /**
     * Генерирует имя права на основе параметров
     *
     * @param string $resource
     * @param string $action
     * @param string|null $scope (all, own или null)
     * @return string
     */
    public static function generate(string $resource, string $action, ?string $scope = null): string
    {
        if ($scope) {
            return "{$resource}_{$action}_{$scope}";
        }

        return "{$resource}_{$action}";
    }

    /**
     * Проверяет, является ли право стандартным (не custom)
     *
     * @param string $permissionName
     * @return bool
     */
    public static function isStandard(string $permissionName): bool
    {
        $parsed = self::parse($permissionName);
        return $parsed !== null && $parsed['type'] === 'standard';
    }

    /**
     * Проверяет, является ли право custom
     *
     * @param string $permissionName
     * @return bool
     */
    public static function isCustom(string $permissionName): bool
    {
        $parsed = self::parse($permissionName);
        return $parsed !== null && $parsed['type'] === 'custom';
    }
}

