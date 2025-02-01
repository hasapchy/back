<?php

use App\Http\Controllers\DashboardController;
use App\Livewire\Admin\CashRegisters;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Livewire\Admin\Products;
use App\Livewire\Admin\Categories;
use App\Livewire\Admin\Clients;
use App\Livewire\Admin\Roles;
use App\Livewire\Admin\Users;
use App\Livewire\Admin\Settings;
use App\Livewire\Admin\Services;
use App\Livewire\Admin\Currencies;
use App\Livewire\Admin\Warehouses;
use App\Livewire\Admin\WarehouseOperations;
use App\Livewire\Admin\WarehouseProductReceipts;
use App\Livewire\Admin\WarehouseProductWriteOffs;
use App\Livewire\Admin\WarehouseProductMovements;
use App\Livewire\Admin\TransactionCategories;
use App\Livewire\Admin\Sales;
use App\Livewire\Admin\Transfers;
use App\Livewire\Admin\Projects;
use App\Livewire\Admin\OrderStatuses;
use App\Livewire\Admin\OrderCategories;
use App\Livewire\Admin\OrderStatusCategories;
use App\Livewire\Admin\Orders;
use App\Livewire\Admin\Af;

Auth::routes();

Route::get('/', function () {
    return redirect()->route('admin.dashboard');
});

Route::get('/dashboard', function () {
    return redirect()->route('admin.dashboard');
});

Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/roles', Roles::class)->name('roles.index')->middleware('permission:view_roles');
    Route::get('/users', Users::class)->name('users.index')->middleware('permission:view_users');
    Route::get('/clients', Clients::class)->name('clients.index')->middleware('permission:view_clients');
    Route::get('/settings', Settings::class)->name('settings.index')->middleware('permission:view_general_settings');
    Route::get('/products', Products::class)->name('products.index')->middleware('permission:view_products');
    Route::get('/categories', Categories::class)->name('categories.index')->middleware('permission:view_categories');
    Route::get('/services', Services::class)->name('services.index');
    Route::get('/currencies', Currencies::class)->name('currencies.index')->middleware('permission:view_currencies');
    Route::get('/warehouses', Warehouses::class)->name('warehouses.index')->middleware('permission:view_warehouses');
    Route::get('/warehouse-operations', WarehouseOperations::class)->name('warehouse.operations')->middleware('permission:view_warehouses');
    Route::get('/warehouse-reception', WarehouseProductReceipts::class)->name('warehouse.reception')->middleware('permission:view_receipts');
    Route::get('/warehouse-transfers', WarehouseProductMovements::class)->name('warehouse.transfers')->middleware('permission:view_movemenents');
    Route::get('/warehouse-write-offs', WarehouseProductWriteOffs::class)->name('warehouse.write-offs')->middleware('permission:view_write_offs');
    Route::get('/cash', CashRegisters::class)->name('cash.index')->middleware('permission:view_cash_registers');
    Route::get('/transaction_categories', TransactionCategories::class)->name('transaction_categories.create')->middleware('permission:view_expense_items');
    Route::get('/sales', Sales::class)->name('sales.index')->middleware('permission:view_sales');
    Route::get('/transfers', Transfers::class)->name('transfers.index')->middleware('permission:view_transfers');
    Route::get('/projects', Projects::class)->name('projects.index')->middleware('permission:view_projects');
    Route::get('/order-statuses', OrderStatuses::class)->name('order-statuses');
    Route::get('/order-categories', OrderCategories::class)->name('order-categories');
    Route::get('/order-status-categories', OrderStatusCategories::class)->name('order-status-categories');
    Route::get('/orders', Orders::class)->name('orders');
    Route::get('/orders-af', Af::class)->name('orders.af');
});