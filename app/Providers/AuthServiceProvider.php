<?php

namespace App\Providers;
use Illuminate\Support\Facades\Gate;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use App\Models\Project;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\Client;
use App\Models\Product;
use App\Models\User;
use App\Models\Sale;
use App\Models\CashRegister;
use App\Models\Warehouse;
use App\Models\Invoice;
use App\Policies\ProjectPolicy;
use App\Policies\OrderPolicy;
use App\Policies\TransactionPolicy;
use App\Policies\ClientPolicy;
use App\Policies\ProductPolicy;
use App\Policies\UserPolicy;
use App\Policies\SalePolicy;
use App\Policies\CashRegisterPolicy;
use App\Policies\WarehousePolicy;
use App\Policies\InvoicePolicy;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Project::class => ProjectPolicy::class,
        Order::class => OrderPolicy::class,
        Transaction::class => TransactionPolicy::class,
        Client::class => ClientPolicy::class,
        Product::class => ProductPolicy::class,
        User::class => UserPolicy::class,
        Sale::class => SalePolicy::class,
        CashRegister::class => CashRegisterPolicy::class,
        Warehouse::class => WarehousePolicy::class,
        Invoice::class => InvoicePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot()
    {
        Gate::before(function ($user, $ability) {
            try {
                return $user->hasPermissionTo($ability);
            } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
                return null;
            }
        });
    }
}
