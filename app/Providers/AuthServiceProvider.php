<?php

namespace App\Providers;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Department;
use App\Models\EmployeeSalary;
use App\Models\MessageTemplate;
use App\Models\News;
use App\Models\Order;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\RecSchedule;
use App\Models\Sale;
use App\Models\Template;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Policies\CashRegisterPolicy;
use App\Policies\ClientPolicy;
use App\Policies\DepartmentPolicy;
use App\Policies\EmployeeSalaryPolicy;
use App\Policies\MessageTemplatePolicy;
use App\Policies\NewsPolicy;
use App\Policies\OrderPolicy;
use App\Policies\ProductPolicy;
use App\Policies\ProjectContractPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\RecSchedulePolicy;
use App\Policies\SalePolicy;
use App\Policies\TemplatePolicy;
use App\Policies\TransactionPolicy;
use App\Policies\UserPolicy;
use App\Policies\WarehousePolicy;
use App\Support\CompanyScopedPermissions;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        CashRegister::class => CashRegisterPolicy::class,
        Client::class => ClientPolicy::class,
        Department::class => DepartmentPolicy::class,
        EmployeeSalary::class => EmployeeSalaryPolicy::class,
        MessageTemplate::class => MessageTemplatePolicy::class,
        News::class => NewsPolicy::class,
        Order::class => OrderPolicy::class,
        Product::class => ProductPolicy::class,
        Project::class => ProjectPolicy::class,
        ProjectContract::class => ProjectContractPolicy::class,
        RecSchedule::class => RecSchedulePolicy::class,
        Sale::class => SalePolicy::class,
        Template::class => TemplatePolicy::class,
        Transaction::class => TransactionPolicy::class,
        User::class => UserPolicy::class,
        Warehouse::class => WarehousePolicy::class,
    ];

    public function boot(): void
    {
        Gate::before(function ($user, string $ability) {
            if (! $user) {
                return null;
            }
            if ($user->is_admin) {
                return true;
            }
            $policyAbilities = ['viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete'];
            if (! in_array($ability, $policyAbilities, true)) {
                return CompanyScopedPermissions::userHas($user, $ability);
            }

            return null;
        });
    }
}
