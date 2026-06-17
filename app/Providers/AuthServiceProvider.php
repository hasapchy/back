<?php

namespace App\Providers;

use App\Models\CashRegister;
use App\Models\CashTransfer;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Lead;
use App\Models\Leave;
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
use App\Models\Task;
use App\Models\Template;
use App\Models\Transaction;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WhPurchase;
use App\Models\WhReceipt;
use App\Models\WhWriteoff;
use App\Policies\CashRegisterPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\JournalEntryPolicy;
use App\Policies\LeadPolicy;
use App\Policies\LeavePolicy;
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
use App\Policies\TaskPolicy;
use App\Policies\TemplatePolicy;
use App\Policies\TransactionPolicy;
use App\Policies\TransferPolicy;
use App\Policies\UnitPolicy;
use App\Policies\UserPolicy;
use App\Policies\WarehousePolicy;
use App\Policies\WhPurchasePolicy;
use App\Policies\WhReceiptPolicy;
use App\Policies\WhWriteoffPolicy;
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
        CashTransfer::class => TransferPolicy::class,
        Category::class => CategoryPolicy::class,
        Invoice::class => InvoicePolicy::class,
        JournalEntry::class => JournalEntryPolicy::class,
        Lead::class => LeadPolicy::class,
        Leave::class => LeavePolicy::class,
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
        Task::class => TaskPolicy::class,
        Template::class => TemplatePolicy::class,
        Transaction::class => TransactionPolicy::class,
        Unit::class => UnitPolicy::class,
        User::class => UserPolicy::class,
        Warehouse::class => WarehousePolicy::class,
        WhPurchase::class => WhPurchasePolicy::class,
        WhReceipt::class => WhReceiptPolicy::class,
        WhWriteoff::class => WhWriteoffPolicy::class,
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
