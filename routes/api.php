<?php

use App\Http\Controllers\Api\AppController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CacheController as ApiCacheController;
use App\Http\Controllers\Api\CashRegistersController;
use App\Http\Controllers\Api\CategoriesController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\CompaniesController;
use App\Http\Controllers\Api\CurrencyHistoryController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\LeaveTypeController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderStatusCategoryController;
use App\Http\Controllers\Api\OrderStatusController;
use App\Http\Controllers\Api\OrderTransactionController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProjectContractsController;
use App\Http\Controllers\Api\ProjectsController;
use App\Http\Controllers\Api\ProjectStatusController;
use App\Http\Controllers\Api\RolesController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\TasksController;
use App\Http\Controllers\Api\TaskStatusController;
use App\Http\Controllers\Api\TransactionCategoryController;
use App\Http\Controllers\Api\TransactionsController;
use App\Http\Controllers\Api\TransfersController;
use App\Http\Controllers\Api\UserCompanyController;
use App\Http\Controllers\Api\UsersController;
use App\Http\Controllers\Api\WarehouseController;
use App\Http\Controllers\Api\WarehouseMovementController;
use App\Http\Controllers\Api\WarehouseReceiptController;
use App\Http\Controllers\Api\WarehouseStockController;
use App\Http\Controllers\Api\WarehouseWriteoffController;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:auth'])->group(function () {
    Route::post('user/login', [AuthController::class, 'login']);
    Route::post('user/refresh', [AuthController::class, 'refresh']);
});

Route::get('transaction_categories/all', [TransactionCategoryController::class, 'all']);

Route::middleware(['auth:sanctum', 'user.active', 'basement.worker'])->prefix('basement')->group(function () {
    Route::get('user/me', [AuthController::class, 'me']);
    Route::post('user/logout', [AuthController::class, 'logout']);
    Route::post('user/profile', [UsersController::class, 'updateProfile']);

    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{id}', [OrderController::class, 'show']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::put('orders/{id}', [OrderController::class, 'update']);
    Route::delete('orders/{id}', [OrderController::class, 'destroy']);

    Route::get('order_statuses', [OrderStatusController::class, 'index']);
    Route::get('order_statuses/all', [OrderStatusController::class, 'all']);

    Route::get('clients', [ClientController::class, 'index']);
    Route::get('clients/all', [ClientController::class, 'all']);
    Route::get('clients/search', [ClientController::class, 'search']);
    Route::get('clients/{id}', [ClientController::class, 'show']);
    Route::post('clients', [ClientController::class, 'store']);

    Route::get('products', [ProductController::class, 'products']);
    Route::get('services', [ProductController::class, 'services']);
    Route::get('products/search', [ProductController::class, 'search']);

    Route::get('projects', [ProjectsController::class, 'index']);
    Route::get('projects/all', [ProjectsController::class, 'all']);
    Route::get('projects/{id}', [ProjectsController::class, 'show']);

    Route::get('warehouses', [WarehouseController::class, 'index']);
    Route::get('warehouses/all', [WarehouseController::class, 'all']);

    Route::get('cash_registers', [CashRegistersController::class, 'index']);
    Route::get('cash_registers/all', [CashRegistersController::class, 'all']);

    Route::get('app/currency', [AppController::class, 'getCurrencyList']);
    Route::get('app/units', [AppController::class, 'getUnitsList']);
});

Route::middleware(['auth:sanctum', 'user.active', 'prevent.basement'])->group(function () {
    Route::get('app/currency', [AppController::class, 'getCurrencyList']);
    Route::get('app/currency/{id}/exchange-rate', [AppController::class, 'getCurrencyExchangeRate']);
    Route::get('app/units', [AppController::class, 'getUnitsList']);
    Route::post('cache/clear', [ApiCacheController::class, 'clear']);

    Route::get('currency-history/currencies', [CurrencyHistoryController::class, 'getCurrenciesWithRates']);
    Route::get('currency-history/{currencyId}', [CurrencyHistoryController::class, 'index']);
    Route::middleware('permission:currency_history_create')->post('currency-history/{currencyId}', [CurrencyHistoryController::class, 'store']);
    Route::middleware('permission:currency_history_update')->put('currency-history/{currencyId}/{historyId}', [CurrencyHistoryController::class, 'update']);
    Route::middleware('permission:currency_history_delete')->delete('currency-history/{currencyId}/{historyId}', [CurrencyHistoryController::class, 'destroy']);

    Route::post('user/logout', [AuthController::class, 'logout']);
    Route::get('user/me', [AuthController::class, 'me']);
    Route::get('user/current', [UsersController::class, 'getCurrentUser']);
    Route::post('user/profile', [UsersController::class, 'updateProfile']);

    Route::middleware('permission.scope:users_view_all,users_view')->get('users', [UsersController::class, 'index']);
    Route::middleware('permission.scope:users_view_all,users_view')->get('users/all', [UsersController::class, 'getAllUsers']);
    Route::middleware('permission.scope:users_view_all,users_view')->get('users/search', [UsersController::class, 'search']);
    Route::middleware('permission.scope:users_view_all,users_view')->get('users/{id}', [UsersController::class, 'show']);
    Route::middleware('permission:users_create')->post('users', [UsersController::class, 'store']);
    Route::middleware('permission.scope:users_update_all,users_update')->put('users/{id}', [UsersController::class, 'update']);
    Route::middleware('permission.scope:users_delete_all,users_delete')->delete('users/{id}', [UsersController::class, 'destroy']);
    Route::get('permissions', [UsersController::class, 'permissions']);
    Route::get('users/{id}/permissions', [UsersController::class, 'checkPermissions']);
    Route::middleware('permission.scope:employee_salaries_view_all,employee_salaries_view_own')->get('users/{id}/salaries', [UsersController::class, 'getSalaries']);
    Route::middleware('permission:employee_salaries_create')->post('users/{id}/salaries', [UsersController::class, 'createSalary']);
    Route::middleware('permission.scope:employee_salaries_update_all,employee_salaries_update_own')->put('users/{userId}/salaries/{salaryId}', [UsersController::class, 'updateSalary']);
    Route::middleware('permission.scope:employee_salaries_delete_all,employee_salaries_delete_own')->delete('users/{userId}/salaries/{salaryId}', [UsersController::class, 'deleteSalary']);
    Route::middleware('permission.scope:users_view_all,users_view')->get('users/{id}/balance', [UsersController::class, 'getEmployeeBalance']);
    Route::middleware('permission.scope:users_view_all,users_view')->get('users/{id}/balance-history', [UsersController::class, 'getEmployeeBalanceHistory']);

    Route::middleware('permission.scope:roles_view_all,roles_view')->get('roles', [RolesController::class, 'index']);
    Route::middleware('permission.scope:roles_view_all,roles_view')->get('roles/all', [RolesController::class, 'all']);
    Route::middleware('permission.scope:roles_view_all,roles_view')->get('roles/{id}', [RolesController::class, 'show']);
    Route::middleware('permission:roles_create')->post('roles', [RolesController::class, 'store']);
    Route::middleware('permission.scope:roles_update_all,roles_update')->put('roles/{id}', [RolesController::class, 'update']);
    Route::middleware('permission.scope:roles_delete_all,roles_delete')->delete('roles/{id}', [RolesController::class, 'destroy']);

    Route::get('companies', [CompaniesController::class, 'index']);
    Route::middleware('permission:companies_create')->post('companies', [CompaniesController::class, 'store']);
    Route::middleware('permission:companies_update_all')->post('companies/{id}', [CompaniesController::class, 'update']);
    Route::middleware('permission:companies_delete_all')->delete('companies/{id}', [CompaniesController::class, 'destroy']);
    Route::middleware('permission:employee_salaries_accrue')->post('companies/{id}/salaries/accrue', [CompaniesController::class, 'accrueSalaries']);
    Route::middleware('permission:employee_salaries_accrue')->get('companies/{id}/salaries/check', [CompaniesController::class, 'checkExistingSalaries']);

    Route::middleware('permission.scope:warehouses_view_all,warehouses_view')->get('warehouses', [WarehouseController::class, 'index']);
    Route::get('warehouses/all', [WarehouseController::class, 'all']);
    Route::middleware('permission:warehouses_create')->post('warehouses', [WarehouseController::class, 'store']);
    Route::middleware('permission.scope:warehouses_update_all,warehouses_update')->put('warehouses/{id}', [WarehouseController::class, 'update']);
    Route::middleware('permission.scope:warehouses_delete_all,warehouses_delete')->delete('warehouses/{id}', [WarehouseController::class, 'destroy']);

    Route::get('warehouse_stocks', [WarehouseStockController::class, 'index']);

    Route::get('warehouse_receipts', [WarehouseReceiptController::class, 'index']);
    Route::get('warehouse_receipts/{id}', [WarehouseReceiptController::class, 'show']);
    Route::middleware('permission:warehouse_receipts_create')->post('warehouse_receipts', [WarehouseReceiptController::class, 'store']);
    Route::middleware(['permission:warehouse_receipts_update', 'time.restriction:WhReceipt'])->put('warehouse_receipts/{id}', [WarehouseReceiptController::class, 'update']);
    Route::middleware(['permission:warehouse_receipts_delete', 'time.restriction:WhReceipt'])->delete('warehouse_receipts/{id}', [WarehouseReceiptController::class, 'destroy']);

    Route::get('warehouse_writeoffs', [WarehouseWriteoffController::class, 'index']);
    Route::middleware('permission:warehouse_writeoffs_create')->post('warehouse_writeoffs', [WarehouseWriteoffController::class, 'store']);
    Route::middleware(['permission:warehouse_writeoffs_update', 'time.restriction:WhWriteoff'])->put('warehouse_writeoffs/{id}', [WarehouseWriteoffController::class, 'update']);
    Route::middleware(['permission:warehouse_writeoffs_delete', 'time.restriction:WhWriteoff'])->delete('warehouse_writeoffs/{id}', [WarehouseWriteoffController::class, 'destroy']);

    Route::get('warehouse_movements', [WarehouseMovementController::class, 'index']);
    Route::middleware('permission:warehouse_movements_create')->post('warehouse_movements', [WarehouseMovementController::class, 'store']);
    Route::middleware(['permission:warehouse_movements_update', 'time.restriction:WhMovement'])->put('warehouse_movements/{id}', [WarehouseMovementController::class, 'update']);
    Route::middleware(['permission:warehouse_movements_delete', 'time.restriction:WhMovement'])->delete('warehouse_movements/{id}', [WarehouseMovementController::class, 'destroy']);

    Route::get('categories', [CategoriesController::class, 'index']);
    Route::get('categories/all', [CategoriesController::class, 'all']);
    Route::get('categories/parents', [CategoriesController::class, 'parents']);
    Route::middleware('permission:categories_create')->post('categories', [CategoriesController::class, 'store']);
    Route::middleware('permission:categories_update_all')->put('categories/{id}', [CategoriesController::class, 'update']);
    Route::middleware('permission:categories_delete_all')->delete('categories/{id}', [CategoriesController::class, 'destroy']);

    Route::middleware('permission.scope:products_view_all,products_view')->get('products', [ProductController::class, 'products']);
    Route::middleware('permission.scope:products_view_all,products_view')->get('services', [ProductController::class, 'services']);
    Route::middleware('permission.scope:products_view_all,products_view')->get('products/search', [ProductController::class, 'search']);
    Route::middleware('permission.scope:products_view_all,products_view')->get('products/{id}', [ProductController::class, 'show']);
    Route::middleware('permission:products_create')->post('products', [ProductController::class, 'store']);
    Route::middleware('permission.scope:products_update_all,products_update')->post('products/{id}', [ProductController::class, 'update']);
    Route::middleware('permission.scope:products_delete_all,products_delete')->delete('products/{id}', [ProductController::class, 'destroy']);

    Route::middleware('permission.scope:products_view_all,products_view')->get('products/{id}/categories', [ProductController::class, 'getProductCategories']);
    Route::middleware('permission.scope:products_update_all,products_update')->post('products/{id}/categories', [ProductController::class, 'addCategory']);
    Route::middleware('permission.scope:products_update_all,products_update')->delete('products/{id}/categories', [ProductController::class, 'removeCategory']);
    Route::middleware('permission.scope:products_update_all,products_update')->post('products/{id}/categories/primary', [ProductController::class, 'setPrimaryCategory']);

    Route::middleware('permission.scope:clients_view_all,clients_view')->get('clients', [ClientController::class, 'index']);
    Route::get('clients/all', [ClientController::class, 'all']);
    Route::middleware('permission.scope:clients_view_all,clients_view')->get('clients/search', [ClientController::class, 'search']);
    Route::middleware('permission.scope:clients_view_all,clients_view')->get('clients/{id}', [ClientController::class, 'show']);
    Route::middleware('permission:clients_create')->post('clients', [ClientController::class, 'store']);
    Route::middleware('permission.scope:clients_update_all,clients_update')->put('clients/{id}', [ClientController::class, 'update']);
    Route::middleware('permission.scope:clients_view_all,clients_view')->get('clients/{id}/balance-history', [ClientController::class, 'getBalanceHistory']);
    Route::middleware('permission.scope:clients_delete_all,clients_delete')->delete('clients/{id}', [ClientController::class, 'destroy']);

    Route::middleware('permission.scope:cash_registers_view_all,cash_registers_view')->get('cash_registers', [CashRegistersController::class, 'index']);
    Route::get('cash_registers/all', [CashRegistersController::class, 'all']);
    Route::middleware('permission.scope:cash_registers_view_all,cash_registers_view')->get('cash_registers/balance', [CashRegistersController::class, 'getCashBalance']);
    Route::middleware('permission:cash_registers_create')->post('cash_registers', [CashRegistersController::class, 'store']);
    Route::middleware('permission.scope:cash_registers_update_all,cash_registers_update')->put('cash_registers/{id}', [CashRegistersController::class, 'update']);
    Route::middleware('permission.scope:cash_registers_delete_all,cash_registers_delete')->delete('cash_registers/{id}', [CashRegistersController::class, 'destroy']);

    Route::middleware('permission.scope:projects_view_all,projects_view')->get('projects', [ProjectsController::class, 'index']);
    Route::get('projects/all', [ProjectsController::class, 'all']);
    Route::middleware('permission.scope:projects_view_all,projects_view')->get('projects/{id}', [ProjectsController::class, 'show']);
    Route::middleware('permission:projects_create')->post('projects', [ProjectsController::class, 'store']);
    Route::middleware('permission.scope:projects_update_all,projects_update')->put('projects/{id}', [ProjectsController::class, 'update']);
    Route::middleware('permission.scope:projects_update_all,projects_update')->post('projects/{id}/upload-files', [ProjectsController::class, 'uploadFiles']);
    Route::middleware('permission.scope:projects_update_all,projects_update')->post('projects/{id}/delete-file', [ProjectsController::class, 'deleteFile']);
    Route::middleware('permission.scope:projects_update_all,projects_update')->post('projects/batch-status', [ProjectsController::class, 'batchUpdateStatus']);
    Route::middleware('permission.scope:projects_delete_all,projects_delete')->delete('projects/{id}', [ProjectsController::class, 'destroy']);
    Route::middleware('permission.scope:projects_view_all,projects_view')->get('projects/{id}/balance-history', [ProjectsController::class, 'getBalanceHistory']);
    Route::middleware('permission.scope:projects_view_all,projects_view')->get('projects/{id}/detailed-balance', [ProjectsController::class, 'getDetailedBalance']);

    Route::middleware('permission.scope:projects_view_all,projects_view')->get('projects/{projectId}/contracts', [ProjectContractsController::class, 'index']);
    Route::middleware('permission.scope:projects_view_all,projects_view')->get('projects/{projectId}/contracts/all', [ProjectContractsController::class, 'getAll']);
    Route::middleware('permission.scope:projects_update_all,projects_update')->post('projects/{projectId}/contracts', [ProjectContractsController::class, 'store']);
    Route::middleware('permission.scope:projects_view_all,projects_view')->get('contracts/{id}', [ProjectContractsController::class, 'show']);
    Route::middleware('permission.scope:projects_update_all,projects_update')->put('contracts/{id}', [ProjectContractsController::class, 'update']);
    Route::middleware('permission.scope:projects_update_all,projects_update')->delete('contracts/{id}', [ProjectContractsController::class, 'destroy']);

    Route::middleware('permission.scope:project_statuses_view_all,project_statuses_view')->get('project-statuses', [ProjectStatusController::class, 'index']);
    Route::middleware('permission.scope:project_statuses_view_all,project_statuses_view')->get('project-statuses/all', [ProjectStatusController::class, 'all']);
    Route::middleware('permission:project_statuses_create')->post('project-statuses', [ProjectStatusController::class, 'store']);
    Route::middleware('permission.scope:project_statuses_update_all,project_statuses_update')->put('project-statuses/{id}', [ProjectStatusController::class, 'update']);
    Route::middleware('permission.scope:project_statuses_delete_all,project_statuses_delete')->delete('project-statuses/{id}', [ProjectStatusController::class, 'destroy']);

    Route::middleware('permission.scope:transactions_view_all,transactions_view')->get('transactions', [TransactionsController::class, 'index']);
    Route::middleware('permission:transactions_create')->post('transactions', [TransactionsController::class, 'store']);
    Route::middleware(['permission.scope:transactions_update_all,transactions_update', 'time.restriction:Transaction'])->put('transactions/{id}', [TransactionsController::class, 'update']);
    Route::middleware(['permission.scope:transactions_delete_all,transactions_delete', 'time.restriction:Transaction'])->delete('transactions/{id}', [TransactionsController::class, 'destroy']);
    Route::middleware('permission.scope:transactions_view_all,transactions_view')->get('transactions/total', [TransactionsController::class, 'getTotalByOrderId']);
    Route::middleware('permission.scope:transactions_view_all,transactions_view')->get('transactions/{id}', [TransactionsController::class, 'show']);

    Route::middleware('permission.scope:transfers_view_all,transfers_view')->get('transfers', [TransfersController::class, 'index']);
    Route::middleware('permission:transfers_create')->post('transfers', [TransfersController::class, 'store']);
    Route::middleware(['permission:transfers_update', 'time.restriction:CashTransfer'])->put('transfers/{id}', [TransfersController::class, 'update']);
    Route::middleware(['permission:transfers_delete', 'time.restriction:CashTransfer'])->delete('transfers/{id}', [TransfersController::class, 'destroy']);

    Route::middleware('permission.scope:sales_view_all,sales_view')->get('sales', [SaleController::class, 'index']);
    Route::middleware(['permission:sales_create'])->post('sales', [SaleController::class, 'store']);
    Route::middleware(['permission.scope:sales_delete_all,sales_delete', 'time.restriction:Sale'])->delete('sales/{id}', [SaleController::class, 'destroy']);
    Route::middleware('permission.scope:sales_view_all,sales_view')->get('sales/{id}', [SaleController::class, 'show']);

    Route::middleware('permission.scope:orders_view_all,orders_view')->get('orders', [OrderController::class, 'index']);
    Route::middleware('permission:orders_create')->post('orders', [OrderController::class, 'store']);
    Route::middleware('permission.scope:orders_update_all,orders_update')->put('orders/{id}', [OrderController::class, 'update']);
    Route::middleware('permission.scope:orders_delete_all,orders_delete')->delete('orders/{id}', [OrderController::class, 'destroy']);
    Route::middleware('permission.scope:orders_update_all,orders_update')->post('orders/batch-status', [OrderController::class, 'batchUpdateStatus']);
    Route::middleware('permission.scope:orders_view_all,orders_view')->get('orders/{id}', [OrderController::class, 'show']);

    Route::middleware('permission:orders_update')->post('orders/{orderId}/transactions', [OrderTransactionController::class, 'linkTransaction']);
    Route::middleware('permission:orders_update')->delete('orders/{orderId}/transactions/{transactionId}', [OrderTransactionController::class, 'unlinkTransaction']);
    Route::get('orders/{orderId}/transactions', [OrderTransactionController::class, 'getOrderTransactions']);

    Route::get('order_statuses', [OrderStatusController::class, 'index']);
    Route::get('order_statuses/all', [OrderStatusController::class, 'all']);
    Route::middleware('permission:order_statuses_create')->post('order_statuses', [OrderStatusController::class, 'store']);
    Route::middleware('permission:order_statuses_update')->put('order_statuses/{id}', [OrderStatusController::class, 'update']);
    Route::middleware('permission:order_statuses_delete')->delete('order_statuses/{id}', [OrderStatusController::class, 'destroy']);

    Route::get('order_status_categories', [OrderStatusCategoryController::class, 'index']);
    Route::get('order_status_categories/all', [OrderStatusCategoryController::class, 'all']);
    Route::middleware('permission:order_statuscategories_create')->post('order_status_categories', [OrderStatusCategoryController::class, 'store']);
    Route::middleware('permission:order_statuscategories_update')->put('order_status_categories/{id}', [OrderStatusCategoryController::class, 'update']);
    Route::middleware('permission:order_statuscategories_delete')->delete('order_status_categories/{id}', [OrderStatusCategoryController::class, 'destroy']);

    Route::middleware('permission:leave_types_view_all')->get('leave_types', [LeaveTypeController::class, 'index']);
    Route::middleware('permission:leave_types_view_all')->get('leave_types/all', [LeaveTypeController::class, 'all']);
    Route::middleware('permission:leave_types_create_all')->post('leave_types', [LeaveTypeController::class, 'store']);
    Route::middleware('permission:leave_types_update_all')->put('leave_types/{id}', [LeaveTypeController::class, 'update']);
    Route::middleware('permission:leave_types_delete_all')->delete('leave_types/{id}', [LeaveTypeController::class, 'destroy']);

    Route::middleware('permission:leaves_view_all')->get('leaves', [LeaveController::class, 'index']);
    Route::middleware('permission:leaves_view_all')->get('leaves/all', [LeaveController::class, 'all']);
    Route::middleware('permission:leaves_view_all')->get('leaves/{id}', [LeaveController::class, 'show']);
    Route::middleware('permission:leaves_create_all')->post('leaves', [LeaveController::class, 'store']);
    Route::middleware('permission:leaves_update_all')->put('leaves/{id}', [LeaveController::class, 'update']);
    Route::middleware('permission:leaves_delete_all')->delete('leaves/{id}', [LeaveController::class, 'destroy']);

    Route::get('transaction_categories', [TransactionCategoryController::class, 'index']);
    Route::middleware('permission:transaction_categories_create')->post('transaction_categories', [TransactionCategoryController::class, 'store']);
    Route::middleware('permission:transaction_categories_update')->put('transaction_categories/{id}', [TransactionCategoryController::class, 'update']);
    Route::middleware('permission:transaction_categories_delete')->delete('transaction_categories/{id}', [TransactionCategoryController::class, 'destroy']);

    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::middleware('permission:invoices_create')->post('invoices', [InvoiceController::class, 'store']);
    Route::middleware('permission:invoices_update')->put('invoices/{id}', [InvoiceController::class, 'update']);
    Route::middleware('permission:invoices_delete')->delete('invoices/{id}', [InvoiceController::class, 'destroy']);
    Route::get('invoices/{id}', [InvoiceController::class, 'show']);
    Route::post('invoices/orders', [InvoiceController::class, 'getOrdersForInvoice']);

    Route::get('comments/timeline', [CommentController::class, 'timeline']);
    Route::get('comments', [CommentController::class, 'index']);
    Route::post('comments', [CommentController::class, 'store']);
    Route::put('comments/{id}', [CommentController::class, 'update']);
    Route::delete('comments/{id}', [CommentController::class, 'destroy']);

    Route::get('user/current-company', [UserCompanyController::class, 'getCurrentCompany']);
    Route::post('user/set-company', [UserCompanyController::class, 'setCurrentCompany']);
    Route::get('user/companies', [UserCompanyController::class, 'getUserCompanies']);

    // Tasks routes
    Route::middleware('permission.scope:tasks_view_all,tasks_view')->get('tasks', [TasksController::class, 'index']);
    Route::middleware('permission.scope:tasks_view_all,tasks_view')->get('tasks/{id}', [TasksController::class, 'show']);
    Route::middleware('permission:tasks_create')->post('tasks', [TasksController::class, 'store']);
    Route::middleware('permission.scope:tasks_update_all,tasks_update')->put('tasks/{id}', [TasksController::class, 'update']);
    Route::middleware('permission.scope:tasks_delete_all,tasks_delete')->delete('tasks/{id}', [TasksController::class, 'destroy']);

    // Task actions
    Route::middleware('permission.scope:tasks_update_all,tasks_update')->post('tasks/{id}/complete', [TasksController::class, 'complete']);
    Route::middleware('permission.scope:tasks_update_all,tasks_update')->post('tasks/{id}/accept', [TasksController::class, 'accept']);
    Route::middleware('permission.scope:tasks_update_all,tasks_update')->post('tasks/{id}/return', [TasksController::class, 'return']);

    // Task files
    Route::middleware('permission.scope:tasks_update_all,tasks_update')->post('tasks/{id}/files', [TasksController::class, 'uploadFiles']);
    Route::middleware('permission.scope:tasks_update_all,tasks_update')->post('tasks/{id}/delete-file', [TasksController::class, 'deleteFile']);

    // Task statuses
    Route::middleware('permission.scope:task_statuses_view_all,task_statuses_view')->get('task-statuses', [TaskStatusController::class, 'index']);
    Route::middleware('permission.scope:task_statuses_view_all,task_statuses_view')->get('task-statuses/all', [TaskStatusController::class, 'all']);
    Route::middleware('permission:task_statuses_create')->post('task-statuses', [TaskStatusController::class, 'store']);
    Route::middleware('permission.scope:task_statuses_update_all,task_statuses_update')->put('task-statuses/{id}', [TaskStatusController::class, 'update']);
    Route::middleware('permission.scope:task_statuses_delete_all,task_statuses_delete')->delete('task-statuses/{id}', [TaskStatusController::class, 'destroy']);

    // Chats
    Route::middleware('permission:chats_view')->get('chats', [ChatController::class, 'index']);
    Route::middleware('permission:chats_view')->post('chats/general', [ChatController::class, 'general']);
    Route::middleware('permission:chats_view')->post('chats/direct', [ChatController::class, 'startDirect']);
    Route::middleware('permission:chats_group_create')->post('chats/groups', [ChatController::class, 'createGroup']);
    Route::middleware('permission:chats_view')->get('chats/{chat}/messages', [ChatController::class, 'messages']);
    Route::middleware('permission:chats_write')->post('chats/{chat}/messages', [ChatController::class, 'storeMessage']);
});
