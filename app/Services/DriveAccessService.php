<?php

namespace App\Services;

use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\DrivePermission;
use App\Models\User;
use App\Support\CompanyScopedPermissions;
use Illuminate\Support\Collection;

class DriveAccessService
{
    private const ABILITIES = ['view', 'upload', 'rename', 'delete', 'share'];

    /**
     * @return bool
     */
    public function can(User $user, int $companyId, string $ability, ?DriveFolder $folder = null, ?DriveFile $file = null): bool
    {
        if (! in_array($ability, self::ABILITIES, true)) {
            return false;
        }

        if (! $this->passesBasePermission($user, $ability, $companyId)) {
            return false;
        }

        $decision = $this->resolveAcl($user, $companyId, $ability, $folder, $file);

        return $decision !== DrivePermission::EFFECT_DENY;
    }

    /**
     * @return bool
     */
    public function passesBasePermission(User $user, string $ability, int $companyId): bool
    {
        if ($user->is_admin) {
            return true;
        }

        $permissionMap = [
            'view' => ['drive_view_all', 'drive_view'],
            'upload' => ['drive_create'],
            'rename' => ['drive_update_all', 'drive_update'],
            'delete' => ['drive_delete_all', 'drive_delete'],
            'share' => ['drive_share'],
        ];

        $required = $permissionMap[$ability] ?? [];
        if ($required === []) {
            return false;
        }

        $userPermissions = CompanyScopedPermissions::namesForCompany($user, $companyId);
        foreach ($required as $permissionName) {
            if (in_array($permissionName, $userPermissions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string|null
     */
    public function resolveAcl(User $user, int $companyId, string $ability, ?DriveFolder $folder = null, ?DriveFile $file = null): ?string
    {
        $subjects = $this->resolveSubjects($user, $companyId);
        $resources = $this->resolveResourceChain($folder, $file);

        foreach ($resources as $resource) {
            $rules = DrivePermission::query()
                ->where('company_id', $companyId)
                ->where('resource_type', $resource['type'])
                ->where('resource_id', $resource['id'])
                ->where('ability', $ability)
                ->where(function ($query) use ($subjects) {
                    foreach ($subjects as $subject) {
                        $query->orWhere(function ($subQuery) use ($subject) {
                            $subQuery->where('subject_type', $subject['type'])
                                ->where('subject_id', $subject['id']);
                        });
                    }
                })
                ->get();

            $decision = $this->prioritizeRules($rules);
            if ($decision !== null) {
                return $decision;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{type: string, id: int}>
     */
    private function resolveSubjects(User $user, int $companyId): array
    {
        $subjects = [
            ['type' => DrivePermission::SUBJECT_USER, 'id' => (int) $user->id],
        ];

        $roleIds = $user->getRolesForCompany($companyId)->pluck('id')->map(static fn ($id) => (int) $id)->all();
        foreach ($roleIds as $roleId) {
            $subjects[] = ['type' => DrivePermission::SUBJECT_ROLE, 'id' => $roleId];
        }

        return $subjects;
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

    /**
     * @param  Collection<int, DrivePermission>  $rules
     * @return string|null
     */
    private function prioritizeRules(Collection $rules): ?string
    {
        if ($rules->isEmpty()) {
            return null;
        }

        if ($rules->contains(static fn (DrivePermission $rule) => $rule->effect === DrivePermission::EFFECT_DENY)) {
            return DrivePermission::EFFECT_DENY;
        }

        if ($rules->contains(static fn (DrivePermission $rule) => $rule->effect === DrivePermission::EFFECT_ALLOW)) {
            return DrivePermission::EFFECT_ALLOW;
        }

        return null;
    }
}
