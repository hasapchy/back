<?php

namespace App\Services;

use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\DrivePermission;
use App\Models\User;
use App\Support\CompanyScopedPermissions;

class DriveAccessService
{
    private const ACTIONS = ['view', 'create', 'update', 'delete'];

    private const LEGACY_ACL_ABILITIES = [
        'upload' => 'create',
        'rename' => 'update',
        'share' => 'update',
    ];

    /**
     * @return bool
     */
    public function can(User $user, int $companyId, string $action, ?DriveFolder $folder = null, ?DriveFile $file = null): bool
    {
        if (! in_array($action, self::ACTIONS, true)) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        $permissions = CompanyScopedPermissions::namesForCompany($user, $companyId);

        if (! $this->hasModuleActionPermission($action, $permissions)) {
            return false;
        }

        $record = $file ?? $folder;
        if ($record === null) {
            return true;
        }

        if ($this->hasLegacyActionPermission($action, $permissions)) {
            return true;
        }

        if ($this->hasAclAllow($user, $companyId, $action, $folder, $file)) {
            return true;
        }

        return $this->isRecordCreator($user, $record);
    }

    /**
     * @return bool
     */
    public function passesBasePermission(User $user, string $action, int $companyId): bool
    {
        if ($user->is_admin) {
            return true;
        }

        if (! in_array($action, self::ACTIONS, true)) {
            return false;
        }

        $permissions = CompanyScopedPermissions::namesForCompany($user, $companyId);

        return $this->hasModuleActionPermission($action, $permissions);
    }

    /**
     * @return array<int, string>
     */
    public function aclAbilitiesFor(string $action): array
    {
        return array_values(array_unique(array_merge(
            [$action],
            array_keys(array_filter(self::LEGACY_ACL_ABILITIES, static fn (string $mapped) => $mapped === $action))
        )));
    }

    /**
     * @return string
     */
    public function normalizeAclAbility(string $ability): string
    {
        return self::LEGACY_ACL_ABILITIES[$ability] ?? $ability;
    }

    /**
     * @param array<int, string> $permissions
     * @return bool
     */
    private function hasModuleActionPermission(string $action, array $permissions): bool
    {
        return in_array("drive_{$action}_all", $permissions, true)
            || in_array("drive_{$action}", $permissions, true);
    }

    /**
     * @param array<int, string> $permissions
     * @return bool
     */
    private function hasLegacyActionPermission(string $action, array $permissions): bool
    {
        return in_array("drive_{$action}", $permissions, true);
    }

    /**
     * @param DriveFile|DriveFolder $record
     * @return bool
     */
    private function isRecordCreator(User $user, DriveFile|DriveFolder $record): bool
    {
        return (int) $record->creator_id === (int) $user->id;
    }

    /**
     * @return bool
     */
    private function hasAclAllow(User $user, int $companyId, string $action, ?DriveFolder $folder = null, ?DriveFile $file = null): bool
    {
        $abilities = $this->aclAbilitiesFor($action);
        $resources = $this->resolveResourceChain($folder, $file);

        foreach ($resources as $resource) {
            $exists = DrivePermission::query()
                ->where('company_id', $companyId)
                ->where('resource_type', $resource['type'])
                ->where('resource_id', $resource['id'])
                ->where('subject_type', DrivePermission::SUBJECT_USER)
                ->where('subject_id', (int) $user->id)
                ->where('effect', DrivePermission::EFFECT_ALLOW)
                ->whereIn('ability', $abilities)
                ->exists();

            if ($exists) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{type: string, id: int}>
     */
    private function resolveResourceChain(?DriveFolder $folder = null, ?DriveFile $file = null): array
    {
        $resources = [];
        if ($file) {
            $resources[] = ['type' => DrivePermission::RESOURCE_FILE, 'id' => (int) $file->id];
            if (! $folder && $file->folder) {
                $folder = $file->folder;
            }
        }

        if ($folder) {
            $resources[] = ['type' => DrivePermission::RESOURCE_FOLDER, 'id' => (int) $folder->id];
            foreach ($this->ancestorFolderIds($folder) as $ancestorId) {
                $resources[] = ['type' => DrivePermission::RESOURCE_FOLDER, 'id' => $ancestorId];
            }
        }

        return $resources;
    }

    /**
     * @return array<int, int>
     */
    private function ancestorFolderIds(DriveFolder $folder): array
    {
        $ids = [];
        $current = $folder->parent;
        while ($current) {
            $ids[] = (int) $current->id;
            $current = $current->parent;
        }

        return $ids;
    }
}
