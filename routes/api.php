<?php

use App\Http\Controllers\Api\AppController;
use App\Http\Controllers\Api\CashRegistersController;
use App\Http\Controllers\Api\CategoriesController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProjectsController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\TransactionsController;
use App\Http\Controllers\Api\TransfersController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UsersController;
use App\Http\Controllers\Api\WarehouseController;
use App\Http\Controllers\Api\WarehouseMovementController;
use App\Http\Controllers\Api\WarehouseReceiptController;
use App\Http\Controllers\Api\WarehouseStockController;
use App\Http\Controllers\Api\WarehouseWriteoffController;
use App\Http\Controllers\Api\OrderStatusController;
use App\Http\Controllers\Api\OrderStatusCategoryController;
use App\Http\Controllers\Api\OrderCategoryController;
use App\Http\Controllers\Api\TransactionCategoryController;
use App\Http\Controllers\Api\OrderTransactionController;
use App\Http\Controllers\Api\OrderAfController;
use App\Http\Controllers\Api\PerformanceController;
use App\Http\Controllers\Api\CurrencyHistoryController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\ProjectStatusController;

Route::post('user/login', [AuthController::class, 'login']);
Route::post('user/refresh', [AuthController::class, 'refresh']);

Route::middleware('auth:api')->group(function () {
    // app
    Route::get('app/currency', [AppController::class, 'getCurrencyList']);
    Route::get('app/currency/{id}/exchange-rate', [AppController::class, 'getCurrencyExchangeRate']);
    Route::get('app/units', [AppController::class, 'getUnitsList']);
    Route::get('app/product_statuses', [AppController::class, 'getProductStatuses']);

    // currency history
    // Route::middleware('permission:currency_history_view')->get('currency-history/currencies', [CurrencyHistoryController::class, 'getCurrenciesWithRates']);
    // Route::middleware('permission:currency_history_view')->get('currency-history/{currencyId}', [CurrencyHistoryController::class, 'index']);
    Route::get('currency-history/currencies', [CurrencyHistoryController::class, 'getCurrenciesWithRates']);
    Route::get('currency-history/{currencyId}', [CurrencyHistoryController::class, 'index']);
    Route::middleware('permission:currency_history_create')->post('currency-history/{currencyId}', [CurrencyHistoryController::class, 'store']);
    Route::middleware('permission:currency_history_update')->put('currency-history/{currencyId}/{historyId}', [CurrencyHistoryController::class, 'update']);
    Route::middleware('permission:currency_history_delete')->delete('currency-history/{currencyId}/{historyId}', [CurrencyHistoryController::class, 'destroy']);

    // user
    Route::post('user/logout', [AuthController::class, 'logout']);
    Route::get('user/me', [AuthController::class, 'me']);
    Route::get('user/current', [UsersController::class, 'getCurrentUser']);
    Route::post('user/profile', [UsersController::class, 'updateProfile']);

    // users
    // Route::middleware('permission:users_view')->get('users', [UsersController::class, 'index']);
    // Route::middleware('permission:users_view')->get('users/all', [UsersController::class, 'getAllUsers']);
    Route::get('users', [UsersController::class, 'index']);
    Route::get('users/all', [UsersController::class, 'getAllUsers']);
    Route::middleware('permission:users_create')->post('users', [UsersController::class, 'store']);
    Route::middleware('permission:users_update')->put('users/{id}', [UsersController::class, 'update']);
    Route::middleware('permission:users_delete')->delete('users/{id}', [UsersController::class, 'destroy']);
    Route::get('/permissions', [UsersController::class, 'permissions']);
    Route::get('users/{id}/permissions', [UsersController::class, 'checkPermissions']);

    // companies
    // Route::middleware('permission:companies_view')->get('companies', [App\Http\Controllers\Api\CompaniesController::class, 'index']);
    Route::get('companies', [App\Http\Controllers\Api\CompaniesController::class, 'index']);
    Route::middleware('permission:companies_create')->post('companies', [App\Http\Controllers\Api\CompaniesController::class, 'store']);
    Route::middleware('permission:companies_update')->put('companies/{id}', [App\Http\Controllers\Api\CompaniesController::class, 'update']);
    Route::middleware('permission:companies_delete')->delete('companies/{id}', [App\Http\Controllers\Api\CompaniesController::class, 'destroy']);

    // warehouses
    // Route::middleware('permission:warehouses_view')->get('warehouses', [WarehouseController::class, 'index']);
    // Route::middleware('permission:warehouses_view')->get('warehouses/all', [WarehouseController::class, 'all']);
    Route::get('warehouses', [WarehouseController::class, 'index']);
    Route::get('warehouses/all', [WarehouseController::class, 'all']);
    Route::middleware('permission:warehouses_create')->post('warehouses', [WarehouseController::class, 'store']);
    Route::middleware('permission:warehouses_update')->put('warehouses/{id}', [WarehouseController::class, 'update']);
    Route::middleware('permission:warehouses_delete')->delete('warehouses/{id}', [WarehouseController::class, 'destroy']);

    // warehouse_stocks
    // Route::middleware('permission:warehouse_stocks_view')->get('warehouse_stocks', [WarehouseStockController::class, 'index']);
    Route::get('warehouse_stocks', [WarehouseStockController::class, 'index']);

    // warehouse_receipts
    // Route::middleware('permission:warehouse_receipts_view')->get('warehouse_receipts', [WarehouseReceiptController::class, 'index']);
    Route::get('warehouse_receipts', [WarehouseReceiptController::class, 'index']);
    Route::middleware('permission:warehouse_receipts_create')->post('warehouse_receipts', [WarehouseReceiptController::class, 'store']);
    Route::middleware('permission:warehouse_receipts_update')->put('warehouse_receipts/{id}', [WarehouseReceiptController::class, 'update']);
    Route::middleware('permission:warehouse_receipts_delete')->delete('warehouse_receipts/{id}', [WarehouseReceiptController::class, 'destroy']);

    // warehouse_writeoffs
    // Route::middleware('permission:warehouse_writeoffs_view')->get('warehouse_writeoffs', [WarehouseWriteoffController::class, 'index']);
    Route::get('warehouse_writeoffs', [WarehouseWriteoffController::class, 'index']);
    Route::middleware('permission:warehouse_writeoffs_create')->post('warehouse_writeoffs', [WarehouseWriteoffController::class, 'store']);
    Route::middleware('permission:warehouse_writeoffs_update')->put('warehouse_writeoffs/{id}', [WarehouseWriteoffController::class, 'update']);
    Route::middleware('permission:warehouse_writeoffs_delete')->delete('warehouse_writeoffs/{id}', [WarehouseWriteoffController::class, 'destroy']);

    // warehouse_movements
    // Route::middleware('permission:warehouse_movements_view')->get('warehouse_movements', [WarehouseMovementController::class, 'index']);
    Route::get('warehouse_movements', [WarehouseMovementController::class, 'index']);
    Route::middleware('permission:warehouse_movements_create')->post('warehouse_movements', [WarehouseMovementController::class, 'store']);
    Route::middleware('permission:warehouse_movements_update')->put('warehouse_movements/{id}', [WarehouseMovementController::class, 'update']);
    Route::middleware('permission:warehouse_movements_delete')->delete('warehouse_movements/{id}', [WarehouseMovementController::class, 'destroy']);

    // categories
    // Route::middleware('permission:categories_view')->get('categories', [CategoriesController::class, 'index']);
    // Route::middleware('permission:categories_view')->get('categories/all', [CategoriesController::class, 'all']);
    // Route::middleware('permission:categories_view')->get('categories/parents', [CategoriesController::class, 'parents']);
    Route::get('categories', [CategoriesController::class, 'index']);
    Route::get('categories/all', [CategoriesController::class, 'all']);
    Route::get('categories/parents', [CategoriesController::class, 'parents']);
    Route::middleware('permission:categories_create')->post('categories', [CategoriesController::class, 'store']);
    Route::middleware('permission:categories_update')->put('categories/{id}', [CategoriesController::class, 'update']);
    Route::middleware('permission:categories_delete')->delete('categories/{id}', [CategoriesController::class, 'destroy']);

    // products
    // Route::middleware('permission:products_view')->get('products', [ProductController::class, 'products']);
    // Route::middleware('permission:products_view')->get('services', [ProductController::class, 'services']);
    // Route::middleware('permission:products_view')->get('products/search', [ProductController::class, 'search']);
    Route::get('products', [ProductController::class, 'products']);
    Route::get('services', [ProductController::class, 'services']);
    Route::get('products/search', [ProductController::class, 'search']);
    Route::middleware('permission:products_create')->post('products', [ProductController::class, 'store']);
    Route::middleware('permission:products_update')->post('products/{id}', [ProductController::class, 'update']);
    Route::middleware('permission:products_delete')->delete('products/{id}', [ProductController::class, 'destroy']);

    // Маршруты для работы с категориями продуктов
    // Route::middleware('permission:products_view')->get('products/{id}/categories', [ProductController::class, 'getProductCategories']);
    Route::get('products/{id}/categories', [ProductController::class, 'getProductCategories']);
    Route::middleware('permission:products_update')->post('products/{id}/categories', [ProductController::class, 'addCategory']);
    Route::middleware('permission:products_update')->delete('products/{id}/categories', [ProductController::class, 'removeCategory']);
    Route::middleware('permission:products_update')->post('products/{id}/categories/primary', [ProductController::class, 'setPrimaryCategory']);

    // clients
    // Route::middleware('permission:clients_view')->get('clients', [ClientController::class, 'index']);
    // Route::middleware('permission:clients_view')->get('clients/all', [ClientController::class, 'all']);
    // Route::middleware('permission:clients_view')->get('clients/search', [ClientController::class, 'search']);
    // Route::middleware('permission:clients_view')->get('clients/{id}', [ClientController::class, 'show']);
    Route::get('clients', [ClientController::class, 'index']);
    Route::get('clients/all', [ClientController::class, 'all']);
    Route::get('clients/search', [ClientController::class, 'search']);
    Route::get('clients/{id}', [ClientController::class, 'show']);
    Route::middleware('permission:clients_create')->post('clients', [ClientController::class, 'store']);
    Route::middleware('permission:clients_update')->put('clients/{id}', [ClientController::class, 'update']);
    // Route::middleware('permission:clients_view')->get('clients/{id}/balance-history', [ClientController::class, 'getBalanceHistory']);
    Route::get('clients/{id}/balance-history', [ClientController::class, 'getBalanceHistory']);
    Route::middleware('permission:clients_delete')->delete('clients/{id}', [ClientController::class, 'destroy']);

    // cash_registers
    // Route::middleware('permission:cash_registers_view')->get('cash_registers', [CashRegistersController::class, 'index']);
    // Route::middleware('permission:cash_registers_view')->get('cash_registers/all', [CashRegistersController::class, 'all']);
    // Route::middleware('permission:cash_registers_view')->get('cash_registers/balance', [CashRegistersController::class, 'getCashBalance']);
    Route::get('cash_registers', [CashRegistersController::class, 'index']);
    Route::get('cash_registers/all', [CashRegistersController::class, 'all']);
    Route::get('cash_registers/balance', [CashRegistersController::class, 'getCashBalance']);
    Route::middleware('permission:cash_registers_create')->post('cash_registers', [CashRegistersController::class, 'store']);
    Route::middleware('permission:cash_registers_update')->put('cash_registers/{id}', [CashRegistersController::class, 'update']);
    Route::middleware('permission:cash_registers_delete')->delete('cash_registers/{id}', [CashRegistersController::class, 'destroy']);

    // projects
    // Route::middleware('permission:projects_view')->get('projects', [ProjectsController::class, 'index']);
    // Route::middleware('permission:projects_view')->get('projects/all', [ProjectsController::class, 'all']);
    // Route::middleware('permission:projects_view')->get('projects/active', [ProjectsController::class, 'active']);
    // Route::middleware('permission:projects_view')->get('projects/{id}', [ProjectsController::class, 'show']);
    Route::get('projects', [ProjectsController::class, 'index']);
    Route::get('projects/all', [ProjectsController::class, 'all']);
    Route::get('projects/active', [ProjectsController::class, 'active']);
    Route::get('projects/{id}', [ProjectsController::class, 'show']);
    Route::middleware('permission:projects_create')->post('projects', [ProjectsController::class, 'store']);
    Route::middleware('permission:projects_update')->put('projects/{id}', [ProjectsController::class, 'update']);
    Route::middleware('permission:projects_update')->post('projects/{id}/upload-files', [ProjectsController::class, 'uploadFiles']);
    Route::middleware('permission:projects_update')->post('projects/{id}/delete-file', [ProjectsController::class, 'deleteFile']);
    Route::middleware('permission:projects_update')->post('projects/batch-status', [ProjectsController::class, 'batchUpdateStatus']);
    Route::middleware('permission:projects_delete')->delete('projects/{id}', [ProjectsController::class, 'destroy']);
    // Route::get('projects/{id}/balance-history', [\App\Http\Controllers\Api\ProjectsController::class, 'getBalanceHistory']);
    Route::get('projects/{id}/balance-history', [\App\Http\Controllers\Api\ProjectsController::class, 'getBalanceHistory']);

    // project contracts
    // Route::middleware('permission:projects_view')->get('projects/{projectId}/contracts', [\App\Http\Controllers\ProjectContractsController::class, 'index']);
    // Route::middleware('permission:projects_view')->get('projects/{projectId}/contracts/all', [\App\Http\Controllers\ProjectContractsController::class, 'getAll']);
    Route::get('projects/{projectId}/contracts', [\App\Http\Controllers\ProjectContractsController::class, 'index']);
    Route::get('projects/{projectId}/contracts/all', [\App\Http\Controllers\ProjectContractsController::class, 'getAll']);
    Route::middleware('permission:projects_create')->post('projects/{projectId}/contracts', [\App\Http\Controllers\ProjectContractsController::class, 'store']);
    // Route::middleware('permission:projects_view')->get('contracts/{id}', [\App\Http\Controllers\ProjectContractsController::class, 'show']);
    Route::get('contracts/{id}', [\App\Http\Controllers\ProjectContractsController::class, 'show']);
    Route::middleware('permission:projects_update')->put('contracts/{id}', [\App\Http\Controllers\ProjectContractsController::class, 'update']);
    Route::middleware('permission:projects_delete')->delete('contracts/{id}', [\App\Http\Controllers\ProjectContractsController::class, 'destroy']);

    // project statuses
    // Route::middleware('permission:projects_view')->get('project-statuses', [ProjectStatusController::class, 'index']);
    // Route::middleware('permission:projects_view')->get('project-statuses/all', [ProjectStatusController::class, 'all']);
    Route::get('project-statuses', [ProjectStatusController::class, 'index']);
    Route::get('project-statuses/all', [ProjectStatusController::class, 'all']);
    Route::middleware('permission:projects_create')->post('project-statuses', [ProjectStatusController::class, 'store']);
    Route::middleware('permission:projects_update')->put('project-statuses/{id}', [ProjectStatusController::class, 'update']);
    Route::middleware('permission:projects_delete')->delete('project-statuses/{id}', [ProjectStatusController::class, 'destroy']);

    // transactions
    // Route::middleware('permission:transactions_view')->get('transactions', [TransactionsController::class, 'index']);
    Route::get('transactions', [TransactionsController::class, 'index']);
    Route::middleware('permission:transactions_create')->post('transactions', [TransactionsController::class, 'store']);
    Route::middleware('permission:transactions_update')->put('transactions/{id}', [TransactionsController::class, 'update']);
    Route::middleware('permission:transactions_delete')->delete('transactions/{id}', [TransactionsController::class, 'destroy']);
    // Route::middleware('permission:transactions_view')->get('transactions/total', [TransactionsController::class, 'getTotalByOrderId']);
    // Route::middleware('permission:transactions_view')->get('transactions/{id}', [TransactionsController::class, 'show']);
    // Route::middleware('permission:transactions_view')->get('transactions/project-incomes', [TransactionsController::class, 'getProjectIncomes']);
    Route::get('transactions/total', [TransactionsController::class, 'getTotalByOrderId']);
    Route::get('transactions/{id}', [TransactionsController::class, 'show']);
    Route::get('transactions/project-incomes', [TransactionsController::class, 'getProjectIncomes']);

    // transfers
    // Route::middleware('permission:transfers_view')->get('transfers', [TransfersController::class, 'index']);
    Route::get('transfers', [TransfersController::class, 'index']);
    Route::middleware('permission:transfers_create')->post('transfers', [TransfersController::class, 'store']);
    Route::middleware('permission:transfers_update')->put('transfers/{id}', [TransfersController::class, 'update']);
    Route::middleware('permission:transfers_delete')->delete('transfers/{id}', [TransfersController::class, 'destroy']);

    // sales
    // Route::middleware(['permission:sales_view'])->get('sales', [SaleController::class, 'index']);
    Route::get('sales', [SaleController::class, 'index']);
    Route::middleware(['permission:sales_create'])->post('sales', [SaleController::class, 'store']);
    Route::middleware(['permission:sales_delete'])->delete('sales/{id}', [SaleController::class, 'destroy']);
    // Route::middleware('permission:sales_view')->get('sales/{id}', [SaleController::class, 'show']);
    Route::get('sales/{id}', [SaleController::class, 'show']);

    // orders
    // Route::middleware('permission:orders_view')->get('orders', [OrderController::class, 'index']);
    Route::get('orders', [OrderController::class, 'index']);
    Route::middleware('permission:orders_create')->post('orders', [OrderController::class, 'store']);
    Route::middleware('permission:orders_update')->put('orders/{id}', [OrderController::class, 'update']);
    Route::middleware('permission:orders_delete')->delete('orders/{id}', [OrderController::class, 'destroy']);
    Route::middleware('permission:orders_update')->post('orders/batch-status', [OrderController::class, 'batchUpdateStatus']);
    // Route::middleware('permission:orders_view')->get('orders/{id}', [OrderController::class, 'show']);
    // Route::middleware('permission:orders_view')->get('orders/category/{id}/additional-fields', [OrderController::class, 'getAdditionalFields']);
    // Route::middleware('permission:orders_view')->post('orders/categories/additional-fields', [OrderController::class, 'getAdditionalFieldsByCategories']);
    Route::get('orders/{id}', [OrderController::class, 'show']);
    Route::get('orders/category/{id}/additional-fields', [OrderController::class, 'getAdditionalFields']);
    Route::post('orders/categories/additional-fields', [OrderController::class, 'getAdditionalFieldsByCategories']);

    // order transactions
    Route::middleware('permission:orders_update')->post('orders/{orderId}/transactions', [OrderTransactionController::class, 'linkTransaction']);
    Route::middleware('permission:orders_update')->delete('orders/{orderId}/transactions/{transactionId}', [OrderTransactionController::class, 'unlinkTransaction']);
    // Route::middleware('permission:orders_view')->get('orders/{orderId}/transactions', [OrderTransactionController::class, 'getOrderTransactions']);
    Route::get('orders/{orderId}/transactions', [OrderTransactionController::class, 'getOrderTransactions']);

    // order statuses
    // Route::middleware('permission:order_statuses_view')->get('order_statuses', [OrderStatusController::class, 'index']);
    // Route::middleware('permission:order_statuses_view')->get('order_statuses/all', [OrderStatusController::class, 'all']);
    Route::get('order_statuses', [OrderStatusController::class, 'index']);
    Route::get('order_statuses/all', [OrderStatusController::class, 'all']);
    Route::middleware('permission:order_statuses_create')->post('order_statuses', [OrderStatusController::class, 'store']);
    Route::middleware('permission:order_statuses_update')->put('order_statuses/{id}', [OrderStatusController::class, 'update']);
    Route::middleware('permission:order_statuses_delete')->delete('order_statuses/{id}', [OrderStatusController::class, 'destroy']);

    // order_status_categories
    // Route::middleware('permission:order_statuscategories_view')->get('order_status_categories', [OrderStatusCategoryController::class, 'index']);
    // Route::middleware('permission:order_statuscategories_view')->get('order_status_categories/all', [OrderStatusCategoryController::class, 'all']);
    Route::get('order_status_categories', [OrderStatusCategoryController::class, 'index']);
    Route::get('order_status_categories/all', [OrderStatusCategoryController::class, 'all']);
    Route::middleware('permission:order_statuscategories_create')->post('order_status_categories', [OrderStatusCategoryController::class, 'store']);
    Route::middleware('permission:order_statuscategories_update')->put('order_status_categories/{id}', [OrderStatusCategoryController::class, 'update']);
    Route::middleware('permission:order_statuscategories_delete')->delete('order_status_categories/{id}', [OrderStatusCategoryController::class, 'destroy']);

    // order_categories
    // Route::middleware('permission:order_categories_view')->get('order_categories', [OrderCategoryController::class, 'index']);
    // Route::middleware('permission:order_categories_view')->get('order_categories/all', [OrderCategoryController::class, 'all']);
    Route::get('order_categories', [OrderCategoryController::class, 'index']);
    Route::get('order_categories/all', [OrderCategoryController::class, 'all']);
    Route::middleware('permission:order_categories_create')->post('order_categories', [OrderCategoryController::class, 'store']);
    Route::middleware('permission:order_categories_update')->put('order_categories/{id}', [OrderCategoryController::class, 'update']);
    Route::middleware('permission:order_categories_delete')->delete('order_categories/{id}', [OrderCategoryController::class, 'destroy']);

    // transaction_categories
    // Route::middleware('permission:transaction_categories_view')->get('transaction_categories', [TransactionCategoryController::class, 'index']);
    // Route::middleware('permission:transaction_categories_view')->get('transaction_categories/all', [TransactionCategoryController::class, 'all']);
    Route::get('transaction_categories', [TransactionCategoryController::class, 'index']);
    Route::get('transaction_categories/all', [TransactionCategoryController::class, 'all']);
    Route::middleware('permission:transaction_categories_create')->post('transaction_categories', [TransactionCategoryController::class, 'store']);
    Route::middleware('permission:transaction_categories_update')->put('transaction_categories/{id}', [TransactionCategoryController::class, 'update']);
    Route::middleware('permission:transaction_categories_delete')->delete('transaction_categories/{id}', [TransactionCategoryController::class, 'destroy']);

    // order additional fields
    // Route::middleware('permission:orders_view')->get('order-af', [OrderAfController::class, 'index']);
    Route::get('order-af', [OrderAfController::class, 'index']);
    Route::middleware('permission:orders_create')->post('order-af', [OrderAfController::class, 'store']);
    Route::middleware('permission:orders_update')->put('order-af/{id}', [OrderAfController::class, 'update']);
    Route::middleware('permission:orders_delete')->delete('order-af/{id}', [OrderAfController::class, 'destroy']);
    // Route::middleware('permission:orders_view')->get('order-af/{id}', [OrderAfController::class, 'show']);
    // Route::middleware('permission:orders_view')->get('order-af/category/{id}', [OrderAfController::class, 'getByCategory']);
    // Route::middleware('permission:orders_view')->post('order-af/categories', [OrderAfController::class, 'getByCategories']);
    // Route::middleware('permission:orders_view')->get('order-af/types', [OrderAfController::class, 'getFieldTypes']);
    Route::get('order-af/{id}', [OrderAfController::class, 'show']);
    Route::get('order-af/category/{id}', [OrderAfController::class, 'getByCategory']);
    Route::post('order-af/categories', [OrderAfController::class, 'getByCategories']);
    Route::get('order-af/types', [OrderAfController::class, 'getFieldTypes']);

    // invoices
    // Route::middleware('permission:invoices_view')->get('invoices', [InvoiceController::class, 'index']);
    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::middleware('permission:invoices_create')->post('invoices', [InvoiceController::class, 'store']);
    Route::middleware('permission:invoices_update')->put('invoices/{id}', [InvoiceController::class, 'update']);
    Route::middleware('permission:invoices_delete')->delete('invoices/{id}', [InvoiceController::class, 'destroy']);
    // Route::middleware('permission:invoices_view')->get('invoices/{id}', [InvoiceController::class, 'show']);
    // Route::middleware('permission:invoices_create')->post('invoices/orders', [InvoiceController::class, 'getOrdersForInvoice']);
    Route::get('invoices/{id}', [InvoiceController::class, 'show']);
    Route::post('invoices/orders', [InvoiceController::class, 'getOrdersForInvoice']);

    // comments
    Route::get('comments', [CommentController::class, 'index']);
    Route::post('comments', [CommentController::class, 'store']);
    Route::put('comments/{id}', [CommentController::class, 'update']);
    Route::delete('comments/{id}', [CommentController::class, 'destroy']);
    Route::get('comments/timeline', [CommentController::class, 'timeline']);


    // user company
    Route::get('user/current-company', [App\Http\Controllers\Api\UserCompanyController::class, 'getCurrentCompany']);
    Route::post('user/set-company', [App\Http\Controllers\Api\UserCompanyController::class, 'setCurrentCompany']);
    Route::get('user/companies', [App\Http\Controllers\Api\UserCompanyController::class, 'getUserCompanies']);


    // performance monitoring
    Route::get('performance/metrics', [PerformanceController::class, 'getDatabaseMetrics']);
    Route::get('performance/table-sizes', [PerformanceController::class, 'getTableSizes']);
    Route::post('performance/test', [PerformanceController::class, 'runPerformanceTest']);
    Route::get('performance/cache/stats', [PerformanceController::class, 'getCacheStats']);
    Route::post('performance/cache/clear', [PerformanceController::class, 'clearCache']);
    Route::get('performance/server-logs', [PerformanceController::class, 'getServerLogs']);
});
