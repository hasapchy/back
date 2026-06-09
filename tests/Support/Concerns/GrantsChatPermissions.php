<?php

namespace Tests\Support\Concerns;

use App\Models\Company;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

trait GrantsChatPermissions
{
    /**
     * @param  array<int, string>  $permissions
     */
    protected function grantCompanyPermissions(User $user, Company $company, array $permissions): void
    {
        $permissionIds = [];
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'api',
            ]);
            $permissionIds[] = $permission->id;
        }

        $role = Role::query()->create([
            'name' => 'chat_test_'.uniqid('', true),
            'guard_name' => 'api',
        ]);
        $role->syncPermissions($permissionIds);
        $user->companyRoles()->syncWithoutDetaching([
            $role->id => ['company_id' => $company->id],
        ]);
    }

    protected function grantChatViewPermission(User $user, ?Company $company = null): void
    {
        $company ??= $this->company;
        $this->grantCompanyPermissions($user, $company, ['chats_view']);
    }
}
