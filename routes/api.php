<?php

use App\Http\Controllers\Api\AppController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\CashRegistersController;
use App\Http\Controllers\Api\CategoriesController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ClientBalanceController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\CompaniesController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\ProductionCalendarController;
use App\Http\Controllers\Api\CurrenciesController;
use App\Http\Controllers\Api\CurrencyHistoryController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DriveController;
use App\Http\Controllers\Api\EntityLinkPreviewController;
use App\Http\Controllers\Api\FcmStorageController;
use App\Http\Controllers\Api\FinancialAccountsController;
use App\Http\Controllers\Api\JournalEntriesController;
use App\Http\Controllers\Api\InAppNotificationController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\LeadSourceController;
use App\Http\Controllers\Api\LeadStatusController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\LeaveTypeController;
use App\Http\Controllers\Api\MessageTemplateController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderStatusCategoryController;
use App\Http\Controllers\Api\OrderStatusController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProjectContractsController;
use App\Http\Controllers\Api\ProjectsController;
use App\Http\Controllers\Api\ProjectStatusController;
use App\Http\Controllers\Api\RecurringTransactionsController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\RolesController;
use App\Http\Controllers\Api\UnitsController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\TasksController;
use App\Http\Controllers\Api\TaskStatusController;
use App\Http\Controllers\Api\TransactionCategoryController;
use App\Http\Controllers\Api\TransactionsController;
use App\Http\Controllers\Api\TransactionTemplateController;
use App\Http\Controllers\Api\TransfersController;
use App\Http\Controllers\Api\UserCompanyController;
use App\Http\Controllers\Api\UserFilterPresetsController;
use App\Http\Controllers\Api\UserSessionsController;
use App\Http\Controllers\Api\UsersController;
use App\Http\Controllers\Api\WarehouseController;
use App\Http\Controllers\Api\WarehouseMovementController;
use App\Http\Controllers\Api\WarehousePurchaseController;
use App\Http\Controllers\Api\WarehouseReceiptController;
use App\Http\Controllers\Api\WarehouseStockController;
use App\Http\Controllers\Api\WarehouseWriteoffController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::post('user/login', [AuthController::class, 'login'])->middleware('throttle:auth')->name('api.auth.login');
Route::post('user/refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth')->name('api.auth.refresh');

Route::middleware(['bc.json', 'auth:sanctum', 'throttle:api'])->post('broadcasting/auth', function (Request $request) {
    return Broadcast::auth($request);
})->name('api.broadcasting.auth');

Route::middleware('throttle:public')->get('system/ping', function () {
    return response()->json(['data' => true]);
})->name('api.system.ping');

// Main API routes - accessible to all authenticated users with appropriate permissions
Route::middleware(['auth:sanctum', 'throttle:api', 'resolve.company', 'user.active'])->group(function () {
    Route::post('batch', [BatchController::class, 'execute'])->name('api.batch.execute');

    Route::get('app/currency', [AppController::class, 'getCurrencyList'])->name('api.app.currency');
    Route::get('app/currency/{id}/exchange-rate', [AppController::class, 'getCurrencyExchangeRate'])->name('api.app.currency_exchange_rate');
    Route::get('app/units', [AppController::class, 'getUnitsList'])->name('api.app.units');
    Route::get('app/versions', [AppController::class, 'getVersions'])->name('api.app.versions');
    Route::middleware('permission.scope:currency_history_view_all,currency_history_view')->get('currency-history/currencies', [CurrencyHistoryController::class, 'getCurrenciesWithRates'])->name('api.currency_history.currencies');
    Route::middleware('permission.scope:currency_history_view_all,currency_history_view')->get('currency-history', [CurrencyHistoryController::class, 'indexAll'])->name('api.currency_history.index_all');
    Route::middleware('permission.scope:currency_history_view_all,currency_history_view')->get('currency-history/{currencyId}', [CurrencyHistoryController::class, 'index'])->name('api.currency_history.index');
    Route::middleware('permission.scope:currency_history_create,currency_history_update_all')->post('currency-history/{currencyId}', [CurrencyHistoryController::class, 'store'])->name('api.currency_history.store');
    Route::middleware('permission.scope:currency_history_update_all,currency_history_update')->put('currency-history/{currencyId}/{historyId}', [CurrencyHistoryController::class, 'update'])->name('api.currency_history.update');
    Route::middleware('permission.scope:currency_history_delete_all,currency_history_delete')->delete('currency-history/{currencyId}/{historyId}', [CurrencyHistoryController::class, 'destroy'])->name('api.currency_history.destroy');

    Route::middleware('permission.scope:currencies_view_all,currencies_view')->get('settings/currencies', [CurrenciesController::class, 'index'])->name('api.currencies.index');

    Route::middleware('permission.scope:units_view,units_create,units_update,units_delete')->get('units', [UnitsController::class, 'index'])->name('api.units.index');
    Route::middleware('permission:units_create')->post('units', [UnitsController::class, 'store'])->name('api.units.store');
    Route::middleware('permission:units_update')->put('units/{id}', [UnitsController::class, 'update'])->name('api.units.update');
    Route::middleware('permission:units_delete')->delete('units/{id}', [UnitsController::class, 'destroy'])->name('api.units.destroy');

    Route::post('user/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::get('user/me', [AuthController::class, 'me'])->name('api.auth.me');
    Route::get('user/current', [UsersController::class, 'getCurrentUser'])->name('api.users.current');
    Route::post('user/profile', [UsersController::class, 'updateProfile'])->name('api.users.update_profile');
    Route::get('user/profile-wallpapers', [UsersController::class, 'profileWallpapers'])->name('api.users.profile_wallpapers');
    Route::put('user/profile-wallpaper', [UsersController::class, 'updateProfileWallpaper'])->name('api.users.update_profile_wallpaper');
    Route::get('user/ui-preferences', [UsersController::class, 'uiPreferences'])->name('api.users.ui_preferences');
    Route::patch('user/ui-preferences', [UsersController::class, 'patchUiPreferences'])->name('api.users.patch_ui_preferences');
    Route::get('user/sessions', [UserSessionsController::class, 'index'])->name('api.user_sessions.index');
    Route::delete('user/sessions', [UserSessionsController::class, 'destroyAll'])->name('api.user_sessions.destroy_all');
    Route::delete('user/sessions/{id}', [UserSessionsController::class, 'destroy'])->name('api.user_sessions.destroy');
    Route::get('user/fcm-token', [FcmStorageController::class, 'show'])->name('api.fcm.show');
    Route::post('user/fcm-token', [FcmStorageController::class, 'upsert'])->name('api.fcm.upsert');
    Route::put('user/fcm-token', [FcmStorageController::class, 'upsert'])->name('api.fcm.upsert_put');
    Route::delete('user/fcm-token', [FcmStorageController::class, 'destroy'])->name('api.fcm.destroy');
    Route::post('user/fcm-token/test-send', [FcmStorageController::class, 'testSend'])->name('api.fcm.test_send');

    Route::get('user/notification-settings', [InAppNotificationController::class, 'settings'])->name('api.notifications.settings');
    Route::put('user/notification-settings', [InAppNotificationController::class, 'updateSettings'])->name('api.notifications.update_settings');
    Route::get('user/filter-presets', [UserFilterPresetsController::class, 'index'])->name('api.filter_presets.index');
    Route::post('user/filter-presets', [UserFilterPresetsController::class, 'store'])->name('api.filter_presets.store');
    Route::put('user/filter-presets/default', [UserFilterPresetsController::class, 'setDefault'])->name('api.filter_presets.set_default');
    Route::put('user/filter-presets/{id}', [UserFilterPresetsController::class, 'update'])->name('api.filter_presets.update');
    Route::delete('user/filter-presets/{id}', [UserFilterPresetsController::class, 'destroy'])->name('api.filter_presets.destroy');
    Route::get('user/notifications', [InAppNotificationController::class, 'index'])->name('api.notifications.index');
    Route::post('user/notifications/read-all', [InAppNotificationController::class, 'markAllRead'])->name('api.notifications.read_all');
    Route::post('user/notifications/{id}/read', [InAppNotificationController::class, 'markRead'])->name('api.notifications.read');

    Route::get('users', [UsersController::class, 'index'])->name('api.users.index');
    Route::get('users/all', [UsersController::class, 'getAllUsers'])->name('api.users.all');
    Route::get('users/search', [UsersController::class, 'search'])->name('api.users.search');
    Route::get('users/{id}', [UsersController::class, 'show'])->name('api.users.show');
    Route::middleware('permission:users_create')->post('users', [UsersController::class, 'store'])->name('api.users.store');
    Route::middleware('permission.scope:users_update_all,users_update')->put('users/{id}', [UsersController::class, 'update'])->name('api.users.update');
    Route::middleware('permission.scope:users_delete_all,users_delete')->delete('users/{id}', [UsersController::class, 'destroy'])->name('api.users.destroy');
    Route::get('permissions', [UsersController::class, 'permissions'])->name('api.users.permissions');
    Route::get('users/{id}/permissions', [UsersController::class, 'checkPermissions'])->name('api.users.check_permissions');
    Route::middleware('permission.scope:employee_salaries_view_all,employee_salaries_view_own')->get('users/{id}/salaries', [UsersController::class, 'getSalaries'])->name('api.users.salaries');
    Route::middleware('permission:employee_salaries_create')->post('users/{id}/salaries', [UsersController::class, 'createSalary'])->name('api.users.salaries_store');
    Route::middleware('permission.scope:employee_salaries_update_all,employee_salaries_update_own')->put('users/{userId}/salaries/{salaryId}', [UsersController::class, 'updateSalary'])->name('api.users.salaries_update');
    Route::middleware('permission.scope:employee_salaries_delete_all,employee_salaries_delete_own')->delete('users/{userId}/salaries/{salaryId}', [UsersController::class, 'deleteSalary'])->name('api.users.salaries_destroy');
    Route::middleware('permission.scope:settings_client_balance_view,settings_client_balance_view_own')->get('users/{id}/balance', [UsersController::class, 'getEmployeeBalance'])->name('api.users.balance');
    Route::middleware('permission.scope:settings_client_balance_view,settings_client_balance_view_own')->get('users/{id}/balance-history', [UsersController::class, 'getEmployeeBalanceHistory'])->name('api.users.balance_history');
    Route::get('users/{id}/sessions', [UserSessionsController::class, 'indexForUser'])->name('api.user_sessions.index_for_user');
    Route::delete('users/{id}/sessions', [UserSessionsController::class, 'destroyAllForUser'])->name('api.user_sessions.destroy_all_for_user');
    Route::delete('users/{id}/sessions/{sessionId}', [UserSessionsController::class, 'destroyForUser'])->name('api.user_sessions.destroy_for_user');

    Route::middleware('permission.scope:roles_view_all,roles_view')->get('roles', [RolesController::class, 'index'])->name('api.roles.index');
    Route::middleware('permission.scope:roles_view_all,roles_view')->get('roles/all', [RolesController::class, 'all'])->name('api.roles.all');
    Route::middleware('permission.scope:roles_view_all,roles_view')->get('roles/{id}', [RolesController::class, 'show'])->name('api.roles.show');
    Route::middleware('permission:roles_create')->post('roles', [RolesController::class, 'store'])->name('api.roles.store');
    Route::middleware('permission.scope:roles_update_all,roles_update')->put('roles/{id}', [RolesController::class, 'update'])->name('api.roles.update');
    Route::middleware('permission.scope:roles_delete_all,roles_delete')->delete('roles/{id}', [RolesController::class, 'destroy'])->name('api.roles.destroy');

    Route::get('companies', [CompaniesController::class, 'index'])->name('api.companies.index');
    Route::middleware('permission:companies_create')->post('companies', [CompaniesController::class, 'store'])->name('api.companies.store');
    Route::middleware('permission:companies_update_all')->patch('companies/{id}', [CompaniesController::class, 'update'])->name('api.companies.update');
    Route::middleware('permission:companies_update_all')->post('companies/{id}', [CompaniesController::class, 'update'])->name('api.companies.update_post');
    Route::middleware('permission:companies_delete_all')->delete('companies/{id}', [CompaniesController::class, 'destroy'])->name('api.companies.destroy');
    Route::middleware('permission:employee_salaries_accrue')->post('companies/{id}/salaries/accrue', [CompaniesController::class, 'accrueSalaries'])->name('api.companies.salaries_accrue');
    Route::middleware('permission:employee_salaries_accrue')->post('companies/{id}/salaries/pay', [CompaniesController::class, 'paySalaries'])->name('api.companies.salaries_pay');
    Route::middleware('permission:employee_salaries_accrue')->get('companies/{id}/salaries/check', [CompaniesController::class, 'checkExistingSalaries'])->name('api.companies.salaries_check');
    Route::middleware('permission:employee_salaries_accrue')->get('companies/{id}/salaries/preview', [CompaniesController::class, 'salaryAccrualPreview'])->name('api.companies.salaries_preview');
    Route::middleware('permission:employee_salaries_accrue')->get('companies/{id}/salaries/monthly-report', [CompaniesController::class, 'salaryMonthlyReport'])->name('api.companies.salaries_monthly_report');
    Route::middleware('permission:employee_salaries_accrue')->delete('companies/{id}/salaries/batch/{batchId}', [CompaniesController::class, 'deleteSalaryMonthlyReportBatch'])->name('api.companies.salaries_batch_destroy');

    Route::middleware('permission.scope:warehouses_view_all,warehouses_view')->get('warehouses', [WarehouseController::class, 'index'])->name('api.warehouses.index');
    Route::get('warehouses/all', [WarehouseController::class, 'all'])->name('api.warehouses.all');
    Route::middleware('permission:warehouses_create')->post('warehouses', [WarehouseController::class, 'store'])->name('api.warehouses.store');
    Route::middleware('permission.scope:warehouses_update_all,warehouses_update')->put('warehouses/{id}', [WarehouseController::class, 'update'])->name('api.warehouses.update');
    Route::middleware('permission.scope:warehouses_delete_all,warehouses_delete')->delete('warehouses/{id}', [WarehouseController::class, 'destroy'])->name('api.warehouses.destroy');

    Route::middleware('permission.scope:warehouse_stocks_view_all,warehouse_stocks_view')->get('warehouse_stocks', [WarehouseStockController::class, 'index'])->name('api.warehouse_stocks.index');
    Route::middleware('permission.scope:inventories_view_all,inventories_view_own')->get('inventories', [InventoryController::class, 'index'])->name('api.inventories.index');
    Route::middleware('permission.scope:inventories_view_all,inventories_view_own')->get('inventories/{id}', [InventoryController::class, 'show'])->name('api.inventories.show');
    Route::middleware('permission.scope:inventories_view_all,inventories_view_own')->get('inventories/{id}/items', [InventoryController::class, 'items'])->name('api.inventories.items');
    Route::middleware('permission.scope:inventories_view_all,inventories_view_own')->get('inventories/{id}/report', [InventoryController::class, 'report'])->name('api.inventories.report');
    Route::middleware('permission:inventories_export')->get('inventories/{id}/export', [InventoryController::class, 'export'])->name('api.inventories.export');
    Route::middleware('permission:inventories_create')->post('inventories', [InventoryController::class, 'store'])->name('api.inventories.store');
    Route::middleware('permission.scope:inventories_delete_all,inventories_delete_own')->delete('inventories/{id}', [InventoryController::class, 'destroy'])->name('api.inventories.destroy');
    Route::middleware('permission:inventories_count')->patch('inventories/{id}/items', [InventoryController::class, 'updateItems'])->name('api.inventories.update_items');
    Route::middleware('permission:inventories_finalize')->post('inventories/{id}/finalize', [InventoryController::class, 'finalize'])->name('api.inventories.finalize');
    Route::middleware('permission:inventories_finalize')->post('inventories/{id}/apply-shortage', [InventoryController::class, 'applyInventoryStockAdjustment'])->name('api.inventories.apply_shortage');

    Route::middleware('permission.scope:warehouse_receipts_view_all,warehouse_receipts_view_own')->get('warehouse_receipts', [WarehouseReceiptController::class, 'index'])->name('api.warehouse_receipts.index');
    Route::middleware('permission.scope:warehouse_receipts_view_all,warehouse_receipts_view_own')->get('warehouse_receipts/{id}', [WarehouseReceiptController::class, 'show'])->name('api.warehouse_receipts.show');
    Route::middleware('permission:warehouse_receipts_create')->post('warehouse_receipts', [WarehouseReceiptController::class, 'store'])->name('api.warehouse_receipts.store');
    Route::middleware('permission.scope:warehouse_receipts_update_all,warehouse_receipts_update_own')->put('warehouse_receipts/{id}', [WarehouseReceiptController::class, 'update'])->name('api.warehouse_receipts.update');
    Route::middleware(['permission.scope:warehouse_receipts_delete_all,warehouse_receipts_delete_own', 'time.restriction:WhReceipt'])->delete('warehouse_receipts/{id}', [WarehouseReceiptController::class, 'destroy'])->name('api.warehouse_receipts.destroy');
    Route::middleware('permission.scope:warehouse_purchases_view_all,warehouse_purchases_view_own')->get('warehouse_purchases', [WarehousePurchaseController::class, 'index'])->name('api.warehouse_purchases.index');
    Route::middleware('permission.scope:warehouse_purchases_view_all,warehouse_purchases_view_own')->get('warehouse_purchases/{id}', [WarehousePurchaseController::class, 'show'])->name('api.warehouse_purchases.show');
    Route::middleware('permission:warehouse_purchases_create')->post('warehouse_purchases', [WarehousePurchaseController::class, 'store'])->name('api.warehouse_purchases.store');
    Route::middleware('permission.scope:warehouse_purchases_update_all,warehouse_purchases_update_own')->put('warehouse_purchases/{id}', [WarehousePurchaseController::class, 'update'])->name('api.warehouse_purchases.update');
    Route::middleware('permission.scope:warehouse_purchases_update_all,warehouse_purchases_update_own')->post('warehouse_purchases/{id}/pay', [WarehousePurchaseController::class, 'pay'])->name('api.warehouse_purchases.pay');
    Route::middleware('permission.scope:warehouse_purchases_delete_all,warehouse_purchases_delete_own')->delete('warehouse_purchases/{id}', [WarehousePurchaseController::class, 'destroy'])->name('api.warehouse_purchases.destroy');

    Route::middleware('permission.scope:warehouse_writeoffs_view_all,warehouse_writeoffs_view_own')->get('warehouse_writeoffs', [WarehouseWriteoffController::class, 'index'])->name('api.warehouse_writeoffs.index');
    Route::middleware('permission.scope:warehouse_writeoffs_view_all,warehouse_writeoffs_view_own')->get('warehouse_writeoffs/{id}', [WarehouseWriteoffController::class, 'show'])->name('api.warehouse_writeoffs.show');
    Route::middleware('permission:warehouse_writeoffs_create')->post('warehouse_writeoffs', [WarehouseWriteoffController::class, 'store'])->name('api.warehouse_writeoffs.store');
    Route::middleware('permission.scope:warehouse_writeoffs_update_all,warehouse_writeoffs_update_own')->put('warehouse_writeoffs/{id}', [WarehouseWriteoffController::class, 'update'])->name('api.warehouse_writeoffs.update');
    Route::middleware(['permission.scope:warehouse_writeoffs_delete_all,warehouse_writeoffs_delete_own', 'time.restriction:WhWriteoff'])->delete('warehouse_writeoffs/{id}', [WarehouseWriteoffController::class, 'destroy'])->name('api.warehouse_writeoffs.destroy');

    Route::middleware('permission.scope:warehouse_movements_view_all,warehouse_movements_view_own')->get('warehouse_movements', [WarehouseMovementController::class, 'index'])->name('api.warehouse_movements.index');
    Route::middleware('permission:warehouse_movements_create')->post('warehouse_movements', [WarehouseMovementController::class, 'store'])->name('api.warehouse_movements.store');
    Route::middleware('permission.scope:warehouse_movements_update_all,warehouse_movements_update_own')->put('warehouse_movements/{id}', [WarehouseMovementController::class, 'update'])->name('api.warehouse_movements.update');
    Route::middleware(['permission.scope:warehouse_movements_delete_all,warehouse_movements_delete_own', 'time.restriction:WhMovement'])->delete('warehouse_movements/{id}', [WarehouseMovementController::class, 'destroy'])->name('api.warehouse_movements.destroy');

    Route::get('categories', [CategoriesController::class, 'index'])->name('api.categories.index');
    Route::get('categories/all', [CategoriesController::class, 'all'])->name('api.categories.all');
    Route::get('categories/parents', [CategoriesController::class, 'parents'])->name('api.categories.parents');
    Route::middleware('permission:categories_create')->post('categories', [CategoriesController::class, 'store'])->name('api.categories.store');
    Route::middleware('permission:categories_update_all')->put('categories/{id}', [CategoriesController::class, 'update'])->name('api.categories.update');
    Route::middleware('permission:categories_delete_all')->delete('categories/{id}', [CategoriesController::class, 'destroy'])->name('api.categories.destroy');

    Route::get('products', [ProductController::class, 'products'])->name('api.products.index');
    Route::get('services', [ProductController::class, 'services'])->name('api.products.services');
    Route::get('products/search', [ProductController::class, 'search'])->name('api.products.search');
    Route::middleware('permission.scope:products_view_all,products_view')->get('products/{id}', [ProductController::class, 'show'])->name('api.products.show');
    Route::middleware('permission:products_create')->post('products', [ProductController::class, 'store'])->name('api.products.store');
    Route::middleware('permission.scope:products_update_all,products_update')->put('products/{id}', [ProductController::class, 'update'])->name('api.products.update');
    Route::middleware('permission.scope:products_update_all,products_update')->post('products/{id}', [ProductController::class, 'update'])->name('api.products.update_post');
    Route::middleware('permission.scope:products_delete_all,products_delete')->delete('products/{id}', [ProductController::class, 'destroy'])->name('api.products.destroy');

    Route::middleware('permission.scope:products_view_all,products_view')->get('products/{id}/history', [ProductController::class, 'history'])->name('api.products.history');
    Route::middleware('permission.scope:products_view_all,products_view')->get('products/{id}/categories', [ProductController::class, 'getProductCategories'])->name('api.products.categories');
    Route::middleware('permission.scope:products_update_all,products_update')->post('products/{id}/categories', [ProductController::class, 'addCategory'])->name('api.products.add_category');
    Route::middleware('permission.scope:products_update_all,products_update')->delete('products/{id}/categories', [ProductController::class, 'removeCategory'])->name('api.products.remove_category');
    Route::middleware('permission.scope:products_update_all,products_update')->post('products/{id}/categories/primary', [ProductController::class, 'setPrimaryCategory'])->name('api.products.set_primary_category');

    Route::get('clients', [ClientController::class, 'index'])->name('api.clients.index');
    Route::get('clients/all', [ClientController::class, 'all'])->name('api.clients.all');
    Route::get('clients/search', [ClientController::class, 'search'])->name('api.clients.search');
    Route::middleware('permission:settings_client_balance_view')->get(
        'clients/settlements-summary',
        [ClientController::class, 'settlementsSummary']
    )->name('api.clients.settlements_summary');
    Route::middleware('permission:clients_export')->get('clients/export', [ClientController::class, 'export'])->name('api.clients.export');
    Route::middleware('permission.scope:clients_view_all,clients_view,settings_client_balance_view_own')->get('clients/{id}', [ClientController::class, 'show'])->name('api.clients.show');
    Route::middleware('permission:clients_create')->post('clients', [ClientController::class, 'store'])->name('api.clients.store');
    Route::middleware('permission.scope:clients_update_all,clients_update')->put('clients/{id}', [ClientController::class, 'update'])->name('api.clients.update');
    Route::middleware('permission.scope:clients_view_all,clients_view,settings_client_balance_view_own')->get('clients/{id}/balance-history', [ClientController::class, 'getBalanceHistory'])->name('api.clients.balance_history');
    Route::middleware('permission.scope:clients_delete_all,clients_delete')->delete('clients/{id}', [ClientController::class, 'destroy'])->name('api.clients.destroy');

    Route::middleware('permission:client_balances_view_all')->get('clients/{clientId}/balances', [ClientBalanceController::class, 'index'])->name('api.client_balances.index');
    Route::middleware('permission:client_balances_create')->post('clients/{clientId}/balances', [ClientBalanceController::class, 'store'])->name('api.client_balances.store');
    Route::middleware('permission:client_balances_update_all')->put('clients/{clientId}/balances/{id}', [ClientBalanceController::class, 'update'])->name('api.client_balances.update');
    Route::middleware('permission:client_balances_delete_all')->delete('clients/{clientId}/balances/{id}', [ClientBalanceController::class, 'destroy'])->name('api.client_balances.destroy');

    Route::middleware('permission.scope:cash_registers_view_all,cash_registers_view')->get('cash_registers', [CashRegistersController::class, 'index'])->name('api.cash_registers.index');
    Route::get('cash_registers/all', [CashRegistersController::class, 'all'])->name('api.cash_registers.all');
    Route::middleware('permission.scope:cash_registers_view_all,cash_registers_view')->get('cash_registers/balance', [CashRegistersController::class, 'getCashBalance'])->name('api.cash_registers.balance');
    Route::middleware('permission:cash_registers_create')->post('cash_registers', [CashRegistersController::class, 'store'])->name('api.cash_registers.store');
    Route::middleware('permission.scope:cash_registers_update_all,cash_registers_update')->put('cash_registers/{id}', [CashRegistersController::class, 'update'])->name('api.cash_registers.update');
    Route::middleware('permission.scope:cash_registers_delete_all,cash_registers_delete')->delete('cash_registers/{id}', [CashRegistersController::class, 'destroy'])->name('api.cash_registers.destroy');

    Route::middleware('permission:financial_accounts_view')->get('financial/accounts', [FinancialAccountsController::class, 'index'])->name('api.financial_accounts.index');
    Route::middleware('permission:financial_accounts_view')->get('financial/accounts/{id}', [FinancialAccountsController::class, 'show'])->name('api.financial_accounts.show');
    Route::middleware('permission:financial_accounts_view')->get('financial/accounts/{id}/history', [FinancialAccountsController::class, 'history'])->name('api.financial_accounts.history');
    Route::middleware('permission:financial_accounts_view')->get('financial/accounts/{id}/balance-at', [FinancialAccountsController::class, 'balanceAt'])->name('api.financial_accounts.balance_at');

    Route::middleware('permission.scope:journal_entries_view_all,journal_entries_view')->get('journal/entries', [JournalEntriesController::class, 'index'])->name('api.journal_entries.index');
    Route::middleware('permission.scope:journal_entries_view_all,journal_entries_view')->get('journal/entries/{id}', [JournalEntriesController::class, 'show'])->name('api.journal_entries.show');
    Route::middleware('permission:journal_entries_create')->post('journal/entries', [JournalEntriesController::class, 'store'])->name('api.journal_entries.store');
    Route::middleware('permission.scope:journal_entries_update_all,journal_entries_update')->post('journal/entries/{id}/post', [JournalEntriesController::class, 'post'])->name('api.journal_entries.post');
    Route::middleware('permission.scope:journal_entries_update_all,journal_entries_update')->post('journal/entries/{id}/reverse', [JournalEntriesController::class, 'reverse'])->name('api.journal_entries.reverse');

    Route::get('projects', [ProjectsController::class, 'index'])->name('api.projects.index');
    Route::get('projects/all', [ProjectsController::class, 'all'])->name('api.projects.all');
    Route::get('projects/{id}', [ProjectsController::class, 'show'])->name('api.projects.show');
    Route::middleware('permission:projects_create')->post('projects', [ProjectsController::class, 'store'])->name('api.projects.store');
    Route::middleware('permission.scope:projects_update_all,projects_update')->put('projects/{id}', [ProjectsController::class, 'update'])->name('api.projects.update');
    Route::middleware('permission.scope:projects_delete_all,projects_delete')->delete('projects/{id}', [ProjectsController::class, 'destroy'])->name('api.projects.destroy');
    Route::middleware('permission.scope:projects_view_all,projects_view,projects_view_own')->get('projects/{id}/balance-history', [ProjectsController::class, 'getBalanceHistory'])->name('api.projects.balance_history');
    Route::middleware('permission.scope:projects_view_all,projects_view,projects_view_own')->get('projects/{id}/detailed-balance', [ProjectsController::class, 'getDetailedBalance'])->name('api.projects.detailed_balance');
    Route::middleware('permission.scope:chats_view_all,chats_view')->post('projects/{id}/chat', [ProjectsController::class, 'ensureChat'])->name('api.projects.ensure_chat');
    Route::middleware('permission.scope:projects_update_all,projects_update')->post('projects/{id}/drive-folder', [ProjectsController::class, 'createDriveFolder'])->name('api.projects.create_drive_folder');

    Route::middleware('permission.scope:projects_view_all,projects_view,projects_view_own')->get('projects/{projectId}/contracts/all', [ProjectContractsController::class, 'getAll'])->name('api.project_contracts.all');
    Route::middleware('permission.scope:projects_update_all,projects_update')->post('projects/{projectId}/contracts', [ProjectContractsController::class, 'store'])->name('api.project_contracts.store');
    Route::middleware('permission.scope:contracts_view_all,contracts_view_own')->get('contracts', [ProjectContractsController::class, 'getAllContracts'])->name('api.project_contracts.index');
    Route::middleware('permission.scope:contracts_view_all,contracts_view_own')->get('contracts/{id}', [ProjectContractsController::class, 'show'])->name('api.project_contracts.show');
    Route::middleware('permission.scope:contracts_update_all,contracts_update_own')->patch('contracts/{id}', [ProjectContractsController::class, 'patch'])->name('api.project_contracts.patch');
    Route::middleware('permission.scope:contracts_delete_all,contracts_delete_own')->delete('contracts/{id}', [ProjectContractsController::class, 'destroy'])->name('api.project_contracts.destroy');

    Route::get('project-statuses', [ProjectStatusController::class, 'index'])->name('api.project_statuses.index');
    Route::get('project-statuses/all', [ProjectStatusController::class, 'all'])->name('api.project_statuses.all');
    Route::middleware('permission:project_statuses_create')->post('project-statuses', [ProjectStatusController::class, 'store'])->name('api.project_statuses.store');
    Route::middleware('permission.scope:project_statuses_update_all,project_statuses_update')->put('project-statuses/{id}', [ProjectStatusController::class, 'update'])->name('api.project_statuses.update');
    Route::middleware('permission.scope:project_statuses_delete_all,project_statuses_delete')->delete('project-statuses/{id}', [ProjectStatusController::class, 'destroy'])->name('api.project_statuses.destroy');
    Route::middleware('permission.scope:transactions_view_all,transactions_view')->get('transactions', [TransactionsController::class, 'index'])->name('api.transactions.index');
    Route::middleware('permission:transactions_create')->post('transactions', [TransactionsController::class, 'store'])->name('api.transactions.store');
    Route::middleware('permission.scope:transactions_update_all,transactions_update')->put('transactions/{id}', [TransactionsController::class, 'update'])->name('api.transactions.update');
    Route::middleware(['permission.scope:transactions_delete_all,transactions_delete', 'time.restriction:Transaction'])->delete('transactions/{id}', [TransactionsController::class, 'destroy'])->name('api.transactions.destroy');
    Route::middleware('permission.scope:transactions_view_all,transactions_view')->get('transactions/total', [TransactionsController::class, 'getTotalByOrderId'])->name('api.transactions.total');
    Route::middleware('permission:transactions_export')->get('transactions/export', [TransactionsController::class, 'export'])->name('api.transactions.export');
    Route::middleware('permission.scope:transactions_view_all,transactions_view')->get('transactions/{id}', [TransactionsController::class, 'show'])->name('api.transactions.show');

    Route::middleware('permission:reports_view_by_categories')->get('reports/by-categories', [ReportsController::class, 'byCategories'])->name('api.reports.by_categories');
    Route::middleware('permission:reports_view_by_categories')->get('reports/cashflow', [ReportsController::class, 'cashflow'])->name('api.reports.cashflow');
    Route::middleware('permission:reports_view_by_categories')->get('reports/counterparties', [ReportsController::class, 'counterparties'])->name('api.reports.counterparties');
    Route::middleware('permission:reports_view_by_categories')->get('reports/orders', [ReportsController::class, 'orders'])->name('api.reports.orders');
    Route::middleware('permission:reports_view_by_categories')->get('reports/contracts', [ReportsController::class, 'contracts'])->name('api.reports.contracts');
    Route::middleware('permission:reports_view_by_categories')->get('reports/plan-fact-blueprint', [ReportsController::class, 'planFactBlueprint'])->name('api.reports.plan_fact_blueprint');

    Route::middleware('permission.scope:transaction_templates_view_all,transaction_templates_view_own')->get('transaction-templates', [TransactionTemplateController::class, 'index'])->name('api.transaction_templates.index');
    Route::middleware('permission.scope:transaction_templates_view_all,transaction_templates_view_own')->get('transaction-templates/all', [TransactionTemplateController::class, 'all'])->name('api.transaction_templates.all');
    Route::middleware('permission.scope:transaction_templates_view_all,transaction_templates_view_own')->get('transaction-templates/{id}/apply', [TransactionTemplateController::class, 'apply'])->name('api.transaction_templates.apply');
    Route::middleware('permission.scope:transaction_templates_view_all,transaction_templates_view_own')->get('transaction-templates/{id}', [TransactionTemplateController::class, 'show'])->name('api.transaction_templates.show');
    Route::middleware('permission:transaction_templates_create')->post('transaction-templates', [TransactionTemplateController::class, 'store'])->name('api.transaction_templates.store');
    Route::middleware('permission.scope:transaction_templates_update_all,transaction_templates_update_own')->put('transaction-templates/{id}', [TransactionTemplateController::class, 'update'])->name('api.transaction_templates.update');
    Route::middleware('permission.scope:transaction_templates_delete_all,transaction_templates_delete_own')->delete('transaction-templates/{id}', [TransactionTemplateController::class, 'destroy'])->name('api.transaction_templates.destroy');

    Route::get('recurring-transactions', [RecurringTransactionsController::class, 'index'])->name('api.recurring_transactions.index');
    Route::get('recurring-transactions/{id}', [RecurringTransactionsController::class, 'show'])->name('api.recurring_transactions.show');
    Route::post('recurring-transactions', [RecurringTransactionsController::class, 'store'])->name('api.recurring_transactions.store');
    Route::put('recurring-transactions/{id}', [RecurringTransactionsController::class, 'update'])->name('api.recurring_transactions.update');
    Route::delete('recurring-transactions/{id}', [RecurringTransactionsController::class, 'destroy'])->name('api.recurring_transactions.destroy');

    Route::middleware('permission.scope:transfers_view_all,transfers_view_own')->get('transfers', [TransfersController::class, 'index'])->name('api.transfers.index');
    Route::middleware('permission:transfers_create')->post('transfers', [TransfersController::class, 'store'])->name('api.transfers.store');
    Route::middleware('permission.scope:transfers_update_all,transfers_update_own')->put('transfers/{id}', [TransfersController::class, 'update'])->name('api.transfers.update');
    Route::middleware(['permission.scope:transfers_delete_all,transfers_delete_own', 'time.restriction:CashTransfer'])->delete('transfers/{id}', [TransfersController::class, 'destroy'])->name('api.transfers.destroy');

    Route::middleware('permission.scope:sales_view_all,sales_view')->get('sales', [SaleController::class, 'index'])->name('api.sales.index');
    Route::middleware(['permission:sales_create'])->post('sales', [SaleController::class, 'store'])->name('api.sales.store');
    Route::middleware('permission:sales_update')->put('sales/{id}', [SaleController::class, 'update'])->name('api.sales.update');
    Route::middleware(['permission.scope:sales_delete_all,sales_delete', 'time.restriction:Sale'])->delete('sales/{id}', [SaleController::class, 'destroy'])->name('api.sales.destroy');
    Route::middleware('permission.scope:sales_view_all,sales_view')->get('sales/{id}', [SaleController::class, 'show'])->name('api.sales.show');

    Route::get('orders', [OrderController::class, 'index'])->name('api.orders.index');
    Route::middleware('permission:orders_export,orders_simple_export')->get('orders/export', [OrderController::class, 'export'])->name('api.orders.export');
    Route::get('orders/first-stage-count', [OrderController::class, 'stageOneCount'])->name('api.orders.first_stage_count');
    Route::middleware('permission:orders_create,orders_simple_create')->post('orders', [OrderController::class, 'store'])->name('api.orders.store');
    Route::middleware('permission.scope:orders_update_all,orders_update,orders_simple_update_all,orders_simple_update')->put('orders/{id}', [OrderController::class, 'update'])->name('api.orders.update');
    Route::middleware('permission.scope:orders_delete_all,orders_delete,orders_simple_delete_all,orders_simple_delete')->delete('orders/{id}', [OrderController::class, 'destroy'])->name('api.orders.destroy');
    Route::middleware('permission.scope:orders_view_all,orders_view,orders_simple_view_all,orders_simple_view')->get('orders/{id}', [OrderController::class, 'show'])->name('api.orders.show');

    Route::get('order_statuses', [OrderStatusController::class, 'index'])->name('api.order_statuses.index');
    Route::get('order_statuses/all', [OrderStatusController::class, 'all'])->name('api.order_statuses.all');
    Route::middleware('permission:order_statuses_create')->post('order_statuses', [OrderStatusController::class, 'store'])->name('api.order_statuses.store');
    Route::middleware('permission:order_statuses_update')->put('order_statuses/{id}', [OrderStatusController::class, 'update'])->name('api.order_statuses.update');
    Route::middleware('permission:order_statuses_delete')->delete('order_statuses/{id}', [OrderStatusController::class, 'destroy'])->name('api.order_statuses.destroy');
    Route::get('order_status_categories', [OrderStatusCategoryController::class, 'index'])->name('api.order_status_categories.index');
    Route::get('order_status_categories/all', [OrderStatusCategoryController::class, 'all'])->name('api.order_status_categories.all');
    Route::middleware('permission:order_statuscategories_create')->post('order_status_categories', [OrderStatusCategoryController::class, 'store'])->name('api.order_status_categories.store');
    Route::middleware('permission:order_statuscategories_update')->put('order_status_categories/{id}', [OrderStatusCategoryController::class, 'update'])->name('api.order_status_categories.update');
    Route::middleware('permission:order_statuscategories_delete')->delete('order_status_categories/{id}', [OrderStatusCategoryController::class, 'destroy'])->name('api.order_status_categories.destroy');

    Route::get('lead_statuses', [LeadStatusController::class, 'index'])->name('api.lead_statuses.index');
    Route::get('lead_statuses/all', [LeadStatusController::class, 'all'])->name('api.lead_statuses.all');
    Route::middleware('permission:lead_statuses_create')->post('lead_statuses', [LeadStatusController::class, 'store'])->name('api.lead_statuses.store');
    Route::middleware('permission.scope:lead_statuses_update_all,lead_statuses_update')->put('lead_statuses/{id}', [LeadStatusController::class, 'update'])->name('api.lead_statuses.update');
    Route::middleware('permission.scope:lead_statuses_delete_all,lead_statuses_delete')->delete('lead_statuses/{id}', [LeadStatusController::class, 'destroy'])->name('api.lead_statuses.destroy');

    Route::get('lead_sources', [LeadSourceController::class, 'index'])->name('api.lead_sources.index');
    Route::get('lead_sources/all', [LeadSourceController::class, 'all'])->name('api.lead_sources.all');
    Route::middleware('permission:lead_sources_create')->post('lead_sources', [LeadSourceController::class, 'store'])->name('api.lead_sources.store');
    Route::middleware('permission.scope:lead_sources_update_all,lead_sources_update')->put('lead_sources/{id}', [LeadSourceController::class, 'update'])->name('api.lead_sources.update');
    Route::middleware('permission.scope:lead_sources_delete_all,lead_sources_delete')->delete('lead_sources/{id}', [LeadSourceController::class, 'destroy'])->name('api.lead_sources.destroy');

    Route::middleware('permission.scope:leads_view_all,leads_view_own')->get('leads', [LeadController::class, 'index'])->name('api.leads.index');
    Route::middleware('permission.scope:leads_view_all,leads_view_own')->get('leads/{id}', [LeadController::class, 'show'])->name('api.leads.show');
    Route::middleware('permission:leads_create')->post('leads', [LeadController::class, 'store'])->name('api.leads.store');
    Route::middleware('permission.scope:leads_update_all,leads_update_own')->put('leads/{id}', [LeadController::class, 'update'])->name('api.leads.update');
    Route::middleware(['permission.scope:leads_update_all,leads_update_own', 'throttle:20,1'])->post('leads/{id}/files', [LeadController::class, 'uploadFiles'])->name('api.leads.upload_files');
    Route::middleware('permission.scope:leads_delete_all,leads_delete_own')->delete('leads/{id}', [LeadController::class, 'destroy'])->name('api.leads.destroy');

    Route::middleware('permission:leave_types_view_all')->get('leave_types', [LeaveTypeController::class, 'index'])->name('api.leave_types.index');
    Route::middleware('permission.scope:leave_types_view_all,leaves_view_all')->get('leave_types/all', [LeaveTypeController::class, 'all'])->name('api.leave_types.all');
    Route::middleware('permission:leave_types_create_all')->post('leave_types', [LeaveTypeController::class, 'store'])->name('api.leave_types.store');
    Route::middleware('permission:leave_types_update_all')->put('leave_types/{id}', [LeaveTypeController::class, 'update'])->name('api.leave_types.update');
    Route::middleware('permission:leave_types_delete_all')->delete('leave_types/{id}', [LeaveTypeController::class, 'destroy'])->name('api.leave_types.destroy');
    Route::middleware('permission:leaves_view_all')->get('leaves', [LeaveController::class, 'index'])->name('api.leaves.index');
    Route::middleware('permission:leaves_view_all')->get('leaves/all', [LeaveController::class, 'all'])->name('api.leaves.all');
    Route::middleware('permission:leaves_view_all')->get('leaves/{id}', [LeaveController::class, 'show'])->name('api.leaves.show');
    Route::middleware('permission:leaves_create_all')->post('leaves', [LeaveController::class, 'store'])->name('api.leaves.store');
    Route::middleware('permission:leaves_update_all')->put('leaves/{id}', [LeaveController::class, 'update'])->name('api.leaves.update');
    Route::middleware('permission:leaves_delete_all')->delete('leaves/{id}', [LeaveController::class, 'destroy'])->name('api.leaves.destroy');

    Route::get('holidays', [HolidayController::class, 'index'])->name('api.holidays.index');
    Route::get('holidays/all', [HolidayController::class, 'all'])->name('api.holidays.all');
    Route::get('holidays/{id}', [HolidayController::class, 'show'])->name('api.holidays.show');
    Route::middleware('permission:holidays_create')->post('holidays', [HolidayController::class, 'store'])->name('api.holidays.store');
    Route::middleware('permission:holidays_update_all')->put('holidays/{id}', [HolidayController::class, 'update'])->name('api.holidays.update');
    Route::middleware('permission:holidays_delete_all')->delete('holidays/{id}', [HolidayController::class, 'destroy'])->name('api.holidays.destroy');
    Route::get('production-calendar-days/all', [ProductionCalendarController::class, 'all'])->name('api.production_calendar.all');
    Route::middleware('permission:production_calendar_create')->post('production-calendar-days', [ProductionCalendarController::class, 'store'])->name('api.production_calendar.store');
    Route::middleware('permission:production_calendar_update_all')->put('production-calendar-days/{id}', [ProductionCalendarController::class, 'update'])->name('api.production_calendar.update');
    Route::middleware('permission:production_calendar_delete_all')->delete('production-calendar-days/{id}', [ProductionCalendarController::class, 'destroy'])->name('api.production_calendar.destroy');

    Route::get('transaction_categories', [TransactionCategoryController::class, 'index'])->name('api.transaction_categories.index');
    Route::get('transaction_categories/all', [TransactionCategoryController::class, 'all'])->name('api.transaction_categories.all');
    Route::get('transaction_categories/translations/dictionary', [TransactionCategoryController::class, 'translationDictionary'])->name('api.transaction_categories.translations.dictionary');
    Route::middleware('permission:transaction_categories_update')->put('transaction_categories/translations', [TransactionCategoryController::class, 'upsertTranslations'])->name('api.transaction_categories.translations.upsert');
    Route::middleware('permission:transaction_categories_create')->post('transaction_categories', [TransactionCategoryController::class, 'store'])->name('api.transaction_categories.store');
    Route::middleware('permission:transaction_categories_update')->put('transaction_categories/{id}', [TransactionCategoryController::class, 'update'])->name('api.transaction_categories.update');
    Route::middleware('permission:transaction_categories_delete')->delete('transaction_categories/{id}', [TransactionCategoryController::class, 'destroy'])->name('api.transaction_categories.destroy');
    Route::get('invoices', [InvoiceController::class, 'index'])->name('api.invoices.index');
    Route::middleware('permission:invoices_create')->post('invoices', [InvoiceController::class, 'store'])->name('api.invoices.store');
    Route::middleware('permission.scope:invoices_update_all,invoices_update')->put('invoices/{id}', [InvoiceController::class, 'update'])->name('api.invoices.update');
    Route::middleware('permission.scope:invoices_delete_all,invoices_delete')->delete('invoices/{id}', [InvoiceController::class, 'destroy'])->name('api.invoices.destroy');
    Route::get('invoices/{id}', [InvoiceController::class, 'show'])->name('api.invoices.show');
    Route::post('invoices/orders', [InvoiceController::class, 'getOrdersForInvoice'])->name('api.invoices.orders');

    Route::get('comments/timeline', [CommentController::class, 'timeline'])->name('api.comments.timeline');
    Route::post('comments/timeline/unread-counts', [CommentController::class, 'unreadCounts'])->name('api.comments.unread_counts');
    Route::post('comments/timeline/read', [CommentController::class, 'markRead'])->name('api.comments.mark_read');
    Route::get('comments', [CommentController::class, 'index'])->name('api.comments.index');
    Route::middleware('throttle:60,1')->post('comments', [CommentController::class, 'store'])->name('api.comments.store');
    Route::put('comments/{id}', [CommentController::class, 'update'])->name('api.comments.update');
    Route::delete('comments/{id}', [CommentController::class, 'destroy'])->name('api.comments.destroy');
    Route::middleware('throttle:60,1')->post('comments/{id}/reaction', [CommentController::class, 'setReaction'])->name('api.comments.set_reaction');

    Route::get('user/current-company', [UserCompanyController::class, 'getCurrentCompany'])->name('api.user_company.current');
    Route::post('user/set-company', [UserCompanyController::class, 'setCurrentCompany'])->name('api.user_company.set_current');
    Route::get('user/companies', [UserCompanyController::class, 'getUserCompanies'])->name('api.user_company.companies');

    // Tasks routes
    Route::middleware('permission.scope:tasks_view_all,tasks_view,tasks_view_own')->get('tasks', [TasksController::class, 'index'])->name('api.tasks.index');
    Route::middleware('permission.scope:tasks_view_all,tasks_view,tasks_view_own')->get('tasks/overdue-count', [TasksController::class, 'overdueCount'])->name('api.tasks.overdue_count');
    Route::middleware('permission.scope:tasks_view_all,tasks_view,tasks_view_own')->get('tasks/{id}', [TasksController::class, 'show'])->name('api.tasks.show');
    Route::middleware('permission:tasks_create')->post('tasks', [TasksController::class, 'store'])->name('api.tasks.store');
    Route::middleware('permission.scope:tasks_update_all,tasks_update')->put('tasks/{id}', [TasksController::class, 'update'])->name('api.tasks.update');
    Route::middleware('permission.scope:tasks_delete_all,tasks_delete')->delete('tasks/{id}', [TasksController::class, 'destroy'])->name('api.tasks.destroy');

    // Task actions
    Route::middleware('permission.scope:tasks_update_all,tasks_update')->post('tasks/{id}/complete', [TasksController::class, 'complete'])->name('api.tasks.complete');
    Route::middleware('permission.scope:tasks_update_all,tasks_update')->post('tasks/{id}/accept', [TasksController::class, 'accept'])->name('api.tasks.accept');
    Route::middleware('permission.scope:tasks_update_all,tasks_update')->post('tasks/{id}/return', [TasksController::class, 'return'])->name('api.tasks.return');

    // Task files
    Route::middleware(['permission.scope:tasks_update_all,tasks_update', 'throttle:20,1'])->post('tasks/{id}/files', [TasksController::class, 'uploadFiles'])->name('api.tasks.upload_files');

    Route::middleware('permission.scope:drive_view_all,drive_view')->get('drive/config', [DriveController::class, 'config'])->name('api.drive.config');
    Route::middleware('permission.scope:drive_view_all,drive_view')->get('drive', [DriveController::class, 'index'])->name('api.drive.index');
    Route::middleware('permission:drive_create')->post('drive/folders', [DriveController::class, 'createFolder'])->name('api.drive.create_folder');
    Route::middleware('permission.scope:drive_update_all,drive_update')->put('drive/folders/{id}', [DriveController::class, 'renameFolder'])->name('api.drive.rename_folder');
    Route::middleware('permission.scope:drive_delete_all,drive_delete')->delete('drive/folders/{id}', [DriveController::class, 'deleteFolder'])->name('api.drive.delete_folder');
    Route::middleware('permission:drive_create')->post('drive/files/upload', [DriveController::class, 'upload'])->name('api.drive.upload');
    Route::middleware('permission.scope:drive_view_all,drive_view')->get('drive/files/{id}/download', [DriveController::class, 'download'])->name('api.drive.download');
    Route::middleware('permission.scope:drive_view_all,drive_view')->get('drive/files/{id}/preview', [DriveController::class, 'preview'])->name('api.drive.preview');
    Route::middleware('permission.scope:drive_update_all,drive_update')->put('drive/files/{id}', [DriveController::class, 'renameFile'])->name('api.drive.rename_file');
    Route::middleware('permission.scope:drive_delete_all,drive_delete')->delete('drive/files/{id}', [DriveController::class, 'deleteFile'])->name('api.drive.delete_file');
    Route::middleware('permission.scope:drive_update_all,drive_update')->post('drive/files/move', [DriveController::class, 'moveFiles'])->name('api.drive.move_files');
    Route::middleware('permission.scope:drive_update_all,drive_update')->get('drive/permissions', [DriveController::class, 'listPermissions'])->name('api.drive.list_permissions');
    Route::middleware('permission.scope:drive_update_all,drive_update')->post('drive/permissions', [DriveController::class, 'setPermission'])->name('api.drive.set_permission');
    Route::middleware('permission.scope:drive_update_all,drive_update')->put('drive/permissions', [DriveController::class, 'syncPermission'])->name('api.drive.sync_permission');

    Route::prefix('v2')->group(function () {
        Route::middleware(['permission.scope:tasks_update_all,tasks_update', 'throttle:20,1'])->delete('tasks/{id}/files', [TasksController::class, 'deleteFile'])->name('api.v2.tasks.delete_file');
    });

    // Task statuses
    Route::get('task-statuses', [TaskStatusController::class, 'index'])->name('api.task_statuses.index');
    Route::get('task-statuses/all', [TaskStatusController::class, 'all'])->name('api.task_statuses.all');
    Route::middleware('permission:task_statuses_create')->post('task-statuses', [TaskStatusController::class, 'store'])->name('api.task_statuses.store');
    Route::middleware('permission.scope:task_statuses_update_all,task_statuses_update')->put('task-statuses/{id}', [TaskStatusController::class, 'update'])->name('api.task_statuses.update');
    Route::middleware('permission.scope:task_statuses_delete_all,task_statuses_delete')->delete('task-statuses/{id}', [TaskStatusController::class, 'destroy'])->name('api.task_statuses.destroy');
    // Departments routes
    Route::middleware('permission:departments_view_all')->get('departments', [DepartmentController::class, 'index'])->name('api.departments.index');
    Route::middleware('permission:departments_view_all')->get('departments/all', [DepartmentController::class, 'all'])->name('api.departments.all');
    Route::middleware('permission:departments_create')->post('departments', [DepartmentController::class, 'store'])->name('api.departments.store');
    Route::middleware('permission:departments_update_all')->put('departments/{id}', [DepartmentController::class, 'update'])->name('api.departments.update');
    Route::middleware('permission:departments_delete_all')->delete('departments/{id}', [DepartmentController::class, 'destroy'])->name('api.departments.destroy');

    // Chats
    Route::middleware('permission.scope:chats_view_all,chats_view')->get('entity-links/preview', [EntityLinkPreviewController::class, 'preview'])->name('api.entity_links.preview');
    Route::middleware('permission.scope:chats_view_all,chats_view')->get('chats', [ChatController::class, 'index'])->name('api.chats.index');
    Route::middleware('permission.scope:chats_view_all,chats_view')->post('chats/general', [ChatController::class, 'general'])->name('api.chats.general');
    Route::middleware('permission.scope:chats_view_all,chats_view')->post('chats/direct', [ChatController::class, 'startDirect'])->name('api.chats.start_direct');
    Route::middleware('permission.scope:chats_group_create,chats_group_create')->post('chats/groups', [ChatController::class, 'createGroup'])->name('api.chats.create_group');
    Route::middleware('permission.scope:chats_view_all,chats_view')->get('chats/{chat}/messages/search', [ChatController::class, 'searchMessages'])->name('api.chats.search_messages');
    Route::middleware('permission.scope:chats_view_all,chats_view')->get('chats/{chat}/messages', [ChatController::class, 'messages'])->name('api.chats.messages');
    Route::middleware('permission.scope:chats_view_all,chats_view')->post('chats/{chat}/read', [ChatController::class, 'markAsRead'])->name('api.chats.mark_as_read');
    Route::middleware(['permission.scope:chats_view_all,chats_view', 'throttle:30,60'])->post('chats/{chat}/typing', [ChatController::class, 'typing'])->name('api.chats.typing');
    Route::middleware('permission.scope:chats_write_all,chats_write')->post('chats/{chat}/messages', [ChatController::class, 'storeMessage'])->name('api.chats.store_message');
    Route::middleware('permission.scope:chats_write_all,chats_write')->put('chats/{chat}/messages/{message}', [ChatController::class, 'updateMessage'])->name('api.chats.update_message');
    Route::middleware('permission.scope:chats_write_all,chats_write')->delete('chats/{chat}/messages/{message}', [ChatController::class, 'deleteMessage'])->name('api.chats.delete_message');
    Route::middleware('permission.scope:chats_write_all,chats_write')->post('chats/{chat}/messages/{message}/forward', [ChatController::class, 'forwardMessage'])->name('api.chats.forward_message');
    Route::middleware('permission.scope:chats_write_all,chats_write')->post('chats/{chat}/messages/{message}/reaction', [ChatController::class, 'setReaction'])->name('api.chats.set_reaction');
    Route::middleware('permission.scope:chats_write_all,chats_write')->post('chats/{chat}/messages/{message}/pin', [ChatController::class, 'pinMessage'])->name('api.chats.pin_message');
    Route::middleware('permission.scope:chats_write_all,chats_write')->delete('chats/{chat}/pin', [ChatController::class, 'unpinMessage'])->name('api.chats.unpin_message');
    Route::middleware('permission.scope:chats_view_all,chats_view')->delete('chats/{chat}', [ChatController::class, 'destroy'])->name('api.chats.destroy');

    // News routes
    Route::get('news', [NewsController::class, 'index'])->name('api.news.index');
    Route::get('news/all', [NewsController::class, 'all'])->name('api.news.all');
    Route::get('news/{id}', [NewsController::class, 'show'])->name('api.news.show');
    Route::middleware('permission:news_create')->post('news', [NewsController::class, 'store'])->name('api.news.store');
    Route::middleware('permission.scope:news_update_all,news_update')->put('news/{id}', [NewsController::class, 'update'])->name('api.news.update');
    Route::middleware('permission.scope:news_delete_all,news_delete')->delete('news/{id}', [NewsController::class, 'destroy'])->name('api.news.destroy');
    Route::middleware('throttle:60,1')->post('news/{id}/reaction', [NewsController::class, 'setReaction'])->name('api.news.set_reaction');
    Route::middleware('throttle:120,1')->post('news/{id}/view', [NewsController::class, 'markViewed'])->name('api.news.mark_viewed');
    Route::middleware('throttle:60,1')->post('news/{id}/acknowledge', [NewsController::class, 'acknowledge'])->name('api.news.acknowledge');

    // Message Templates routes
    Route::middleware('permission.scope:templates_view_all,templates_view')->get('message-templates', [MessageTemplateController::class, 'index'])->name('api.message_templates.index');
    Route::middleware('permission.scope:templates_view_all,templates_view')->get('message-templates/all', [MessageTemplateController::class, 'all'])->name('api.message_templates.all');
    Route::middleware('permission.scope:templates_view_all,templates_view')->get('message-templates/types', [MessageTemplateController::class, 'getTypes'])->name('api.message_templates.types');
    Route::middleware('permission.scope:templates_view_all,templates_view')->get('message-templates/{id}', [MessageTemplateController::class, 'show'])->name('api.message_templates.show');
    Route::middleware('permission:templates_create')->post('message-templates', [MessageTemplateController::class, 'store'])->name('api.message_templates.store');
    Route::middleware('permission.scope:templates_update_all,templates_update')->put('message-templates/{id}', [MessageTemplateController::class, 'update'])->name('api.message_templates.update');
    Route::middleware('permission.scope:templates_delete_all,templates_delete')->delete('message-templates/{id}', [MessageTemplateController::class, 'destroy'])->name('api.message_templates.destroy');
});
