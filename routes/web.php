<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Livewire\Admin\Transactions;
use App\Livewire\Admin\Products;
use App\Livewire\Admin\Categories;
use App\Livewire\Admin\Clients;
use App\Livewire\Admin\Users;
use App\Livewire\Admin\Settings;
use App\Livewire\Admin\Services;
use App\Livewire\Admin\Currencies;
use App\Livewire\Admin\Warehouses;
use App\Livewire\Admin\WarehouseOperations;
use App\Livewire\Admin\WhReceipts;
use App\Livewire\Admin\WhWriteoffs;
use App\Livewire\Admin\WhMovements;
use App\Livewire\Admin\TransactionCategories;
use App\Livewire\Admin\Sales;
use App\Livewire\Admin\Transfers;
use App\Livewire\Admin\Projects;
use App\Livewire\Admin\OrderStatuses;
use App\Livewire\Admin\OrderCategories;
use App\Livewire\Admin\OrderStatusCategories;
use App\Livewire\Admin\Orders;
use App\Livewire\Admin\Af;
use App\Livewire\Admin\Templates;
use App\Livewire\Admin\Cashes;
use App\Livewire\Admin\Roles;

Auth::routes();

Route::get('/', function () {
    return redirect()->route('admin.dashboard');
});

Route::get('/dashboard', function () {
    return redirect()->route('admin.dashboard');
});

Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/users', Users::class)->name('users.index');
    Route::get('/clients', Clients::class)->name('clients.index');
    Route::get('/settings', Settings::class)->name('settings.index');
    Route::get('/products', Products::class)->name('products.index');
    Route::get('/categories', Categories::class)->name('categories.index');
    Route::get('/services', Services::class)->name('services.index');
    Route::get('/currencies', Currencies::class)->name('currencies.index');
    Route::get('/warehouses', Warehouses::class)->name('warehouses.index');
    Route::get('/warehouse-operations', WarehouseOperations::class)->name('warehouse.operations');
    Route::get('/warehouse-reception', WhReceipts::class)->name('warehouse.reception');
    Route::get('/warehouse-transfers', WhMovements::class)->name('warehouse.transfers');
    Route::get('/warehouse-write-offs', WhWriteoffs::class)->name('warehouse.write-offs');
    Route::get('/finance', Transactions::class)->name('finance.index');
    Route::get('/cashes', Cashes::class)->name('cash.index');
    Route::get('/transaction_categories', TransactionCategories::class)->name('transaction_categories.create');
    Route::get('/sales', Sales::class)->name('sales.index');
    Route::get('/transfers', Transfers::class)->name('transfers.index');
    Route::get('/templates', Templates::class)->name('templates.index');
    Route::get('/projects', Projects::class)->name('projects.index');
    Route::get('/order-statuses', OrderStatuses::class)->name('order-statuses');
    Route::get('/order-categories', OrderCategories::class)->name('order-categories');
    Route::get('/order-status-categories', OrderStatusCategories::class)->name('order-status-categories');
    Route::get('/orders', Orders::class)->name('orders');
    Route::get('/orders-af', Af::class)->name('orders.af');
    Route::get('/roles', Roles::class)->name('roles');
});
