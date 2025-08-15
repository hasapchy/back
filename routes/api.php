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
use App\Http\Controllers\Api\OrderTransactionController;
use App\Http\Controllers\Api\SettingsController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\CommentController;

Route::post('user/login', [AuthController::class, 'login']);
Route::post('user/refresh', [AuthController::class, 'refresh']);

Route::middleware('auth:api')->group(function () {
    // app
    Route::get('app/currency', [AppController::class, 'getCurrencyList']);
    Route::get('app/units', [AppController::class, 'getUnitsList']);
    Route::get('app/product_statuses', [AppController::class, 'getProductStatuses']);
    Route::get('app/transaction_categories', [AppController::class, 'getTransactionCategories']);

    // user
    Route::post('user/logout', [AuthController::class, 'logout']);
    Route::get('user/me', [AuthController::class, 'me']);

    // users
    Route::middleware('permission:users_view')->get('users', [UsersController::class, 'index']);
    Route::middleware('permission:users_view')->get('users/all', [UsersController::class, 'getAllUsers']);
    Route::middleware('permission:users_create')->post('users', [UsersController::class, 'store']);
    Route::middleware('permission:users_update')->put('users/{id}', [UsersController::class, 'update']);
    Route::middleware('permission:users_delete')->delete('users/{id}', [UsersController::class, 'destroy']);
    Route::get('/permissions', [UsersController::class, 'permissions']);

    // warehouses
    Route::middleware('permission:warehouses_view')->get('warehouses', [WarehouseController::class, 'index']);
    Route::middleware('permission:warehouses_view')->get('warehouses/all', [WarehouseController::class, 'all']);
    Route::middleware('permission:warehouses_create')->post('warehouses', [WarehouseController::class, 'store']);
    Route::middleware('permission:warehouses_update')->put('warehouses/{id}', [WarehouseController::class, 'update']);
    Route::middleware('permission:warehouses_delete')->delete('warehouses/{id}', [WarehouseController::class, 'destroy']);

    // warehouse_stocks
    Route::middleware('permission:warehouse_stocks_view')->get('warehouse_stocks', [WarehouseStockController::class, 'index']);

    // warehouse_receipts
    Route::middleware('permission:warehouse_receipts_view')->get('warehouse_receipts', [WarehouseReceiptController::class, 'index']);
    Route::middleware('permission:warehouse_receipts_create')->post('warehouse_receipts', [WarehouseReceiptController::class, 'store']);
    Route::middleware('permission:warehouse_receipts_update')->put('warehouse_receipts/{id}', [WarehouseReceiptController::class, 'update']);
    Route::middleware('permission:warehouse_receipts_delete')->delete('warehouse_receipts/{id}', [WarehouseReceiptController::class, 'destroy']);

    // warehouse_writeoffs
    Route::middleware('permission:warehouse_writeoffs_view')->get('warehouse_writeoffs', [WarehouseWriteoffController::class, 'index']);
    Route::middleware('permission:warehouse_writeoffs_create')->post('warehouse_writeoffs', [WarehouseWriteoffController::class, 'store']);
    Route::middleware('permission:warehouse_writeoffs_update')->put('warehouse_writeoffs/{id}', [WarehouseWriteoffController::class, 'update']);
    Route::middleware('permission:warehouse_writeoffs_delete')->delete('warehouse_writeoffs/{id}', [WarehouseWriteoffController::class, 'destroy']);

    // warehouse_movements
    Route::middleware('permission:warehouse_movements_view')->get('warehouse_movements', [WarehouseMovementController::class, 'index']);
    Route::middleware('permission:warehouse_movements_create')->post('warehouse_movements', [WarehouseMovementController::class, 'store']);
    Route::middleware('permission:warehouse_movements_update')->put('warehouse_movements/{id}', [WarehouseMovementController::class, 'update']);
    Route::middleware('permission:warehouse_movements_delete')->delete('warehouse_movements/{id}', [WarehouseMovementController::class, 'destroy']);

    // categories
    Route::middleware('permission:categories_view')->get('categories', [CategoriesController::class, 'index']);
    Route::middleware('permission:categories_view')->get('categories/all', [CategoriesController::class, 'all']);
    Route::middleware('permission:categories_create')->post('categories', [CategoriesController::class, 'store']);
    Route::middleware('permission:categories_update')->put('categories/{id}', [CategoriesController::class, 'update']);
    Route::middleware('permission:categories_delete')->delete('categories/{id}', [CategoriesController::class, 'destroy']);

    // products
    Route::middleware('permission:products_view')->get('products', [ProductController::class, 'products']);
    Route::middleware('permission:products_view')->get('services', [ProductController::class, 'services']);
    Route::middleware('permission:products_view')->get('products/search', [ProductController::class, 'search']);
    Route::middleware('permission:products_create')->post('products', [ProductController::class, 'store']);
    Route::middleware('permission:products_update')->post('products/{id}', [ProductController::class, 'update']);
    Route::middleware('permission:products_delete')->delete('products/{id}', [ProductController::class, 'destroy']);

    // clients
    Route::middleware('permission:clients_view')->get('clients', [ClientController::class, 'index']);
    Route::middleware('permission:clients_view')->get('clients/search', [ClientController::class, 'search']);
    Route::middleware('permission:clients_create')->post('clients', [ClientController::class, 'store']);
    Route::middleware('permission:clients_update')->put('clients/{id}', [ClientController::class, 'update']);
    Route::middleware('permission:clients_view')->get('clients/{id}/balance-history', [ClientController::class, 'getBalanceHistory']);
    Route::middleware('permission:clients_delete')->delete('clients/{id}', [ClientController::class, 'destroy']);

    // cash_registers
    Route::middleware('permission:cash_registers_view')->get('cash_registers', [CashRegistersController::class, 'index']);
    Route::middleware('permission:cash_registers_view')->get('cash_registers/all', [CashRegistersController::class, 'all']);
    Route::middleware('permission:cash_registers_view')->get('cash_registers/balance', [CashRegistersController::class, 'getCashBalance']);
    Route::middleware('permission:cash_registers_create')->post('cash_registers', [CashRegistersController::class, 'store']);
    Route::middleware('permission:cash_registers_update')->put('cash_registers/{id}', [CashRegistersController::class, 'update']);
    Route::middleware('permission:cash_registers_delete')->delete('cash_registers/{id}', [CashRegistersController::class, 'destroy']);

    // projects
    Route::middleware('permission:projects_view')->get('projects', [ProjectsController::class, 'index']);
    Route::middleware('permission:projects_view')->get('projects/all', [ProjectsController::class, 'all']);
    Route::middleware('permission:projects_view')->get('projects/{id}', [ProjectsController::class, 'show']);
    Route::middleware('permission:projects_create')->post('projects', [ProjectsController::class, 'store']);
    Route::middleware('permission:projects_update')->put('projects/{id}', [ProjectsController::class, 'update']);
    Route::middleware('permission:projects_update')->post('projects/{id}/upload-files', [ProjectsController::class, 'uploadFiles']);
    Route::middleware('permission:projects_update')->post('projects/{id}/delete-file', [ProjectsController::class, 'deleteFile']);
    Route::get('projects/{id}/balance-history', [\App\Http\Controllers\Api\ProjectsController::class, 'getBalanceHistory']);

    // transactions
    Route::middleware('permission:transactions_view')->get('transactions', [TransactionsController::class, 'index']);
    Route::middleware('permission:transactions_create')->post('transactions', [TransactionsController::class, 'store']);
    Route::middleware('permission:transactions_update')->put('transactions/{id}', [TransactionsController::class, 'update']);
    Route::middleware('permission:transactions_delete')->delete('transactions/{id}', [TransactionsController::class, 'destroy']);
    Route::middleware('permission:transactions_view')->get('transactions/total', [TransactionsController::class, 'getTotalByOrderId']);
    Route::middleware('permission:transactions_view')->get('transactions/{id}', [TransactionsController::class, 'show']);

    // transfers
    Route::middleware('permission:transfers_view')->get('transfers', [TransfersController::class, 'index']);
    Route::middleware('permission:transfers_create')->post('transfers', [TransfersController::class, 'store']);
    Route::middleware('permission:transfers_update')->put('transfers/{id}', [TransfersController::class, 'update']);
    Route::middleware('permission:transfers_delete')->delete('transfers/{id}', [TransfersController::class, 'destroy']);

    // sales
    Route::middleware(['permission:sales_view'])->get('sales', [SaleController::class, 'index']);
    Route::middleware(['permission:sales_create'])->post('sales', [SaleController::class, 'store']);
    Route::middleware(['permission:sales_delete'])->delete('sales/{id}', [SaleController::class, 'destroy']);
    Route::middleware('permission:sales_view')->get('sales/{id}', [SaleController::class, 'show']);

    // orders
    Route::middleware('permission:orders_view')->get('orders', [OrderController::class, 'index']);
    Route::middleware('permission:orders_create')->post('orders', [OrderController::class, 'store']);
    Route::middleware('permission:orders_update')->put('orders/{id}', [OrderController::class, 'update']);
    Route::middleware('permission:orders_delete')->delete('orders/{id}', [OrderController::class, 'destroy']);
    Route::middleware('permission:orders_update')->post('orders/batch-status', [OrderController::class, 'batchUpdateStatus']);
    Route::middleware('permission:orders_view')->get('orders/{id}', [OrderController::class, 'show']);

    // order transactions
    Route::middleware('permission:orders_update')->post('orders/{orderId}/transactions', [OrderTransactionController::class, 'linkTransaction']);
    Route::middleware('permission:orders_update')->delete('orders/{orderId}/transactions/{transactionId}', [OrderTransactionController::class, 'unlinkTransaction']);
    Route::middleware('permission:orders_view')->get('orders/{orderId}/transactions', [OrderTransactionController::class, 'getOrderTransactions']);

    // order statuses
    Route::middleware('permission:order_statuses_view')->get('order_statuses', [OrderStatusController::class, 'index']);
    Route::middleware('permission:order_statuses_view')->get('order_statuses/all', [OrderStatusController::class, 'all']);
    Route::middleware('permission:order_statuses_create')->post('order_statuses', [OrderStatusController::class, 'store']);
    Route::middleware('permission:order_statuses_update')->put('order_statuses/{id}', [OrderStatusController::class, 'update']);
    Route::middleware('permission:order_statuses_delete')->delete('order_statuses/{id}', [OrderStatusController::class, 'destroy']);

    // order_status_categories
    Route::middleware('permission:order_status_categories_view')->get('order_status_categories', [OrderStatusCategoryController::class, 'index']);
    Route::middleware('permission:order_status_categories_view')->get('order_status_categories/all', [OrderStatusCategoryController::class, 'all']);
    Route::middleware('permission:order_status_categories_create')->post('order_status_categories', [OrderStatusCategoryController::class, 'store']);
    Route::middleware('permission:order_status_categories_update')->put('order_status_categories/{id}', [OrderStatusCategoryController::class, 'update']);
    Route::middleware('permission:order_status_categories_delete')->delete('order_status_categories/{id}', [OrderStatusCategoryController::class, 'destroy']);

    // order_categories
    Route::middleware('permission:order_categories_view')->get('order_categories', [OrderCategoryController::class, 'index']);
    Route::middleware('permission:order_categories_view')->get('order_categories/all', [OrderCategoryController::class, 'all']);
    Route::middleware('permission:order_categories_create')->post('order_categories', [OrderCategoryController::class, 'store']);
    Route::middleware('permission:order_categories_update')->put('order_categories/{id}', [OrderCategoryController::class, 'update']);
    Route::middleware('permission:order_categories_delete')->delete('order_categories/{id}', [OrderCategoryController::class, 'destroy']);

    // comments
    Route::get('comments', [CommentController::class, 'index']);
    Route::post('comments', [CommentController::class, 'store']);
    Route::put('comments/{id}', [CommentController::class, 'update']);
    Route::delete('comments/{id}', [CommentController::class, 'destroy']);
    Route::get('comments/timeline', [CommentController::class, 'timeline']);

    // settings
    Route::middleware('permission:system_settings_view')->get('settings', [SettingsController::class, 'index']);
    Route::middleware('permission:system_settings_update')->post('settings', [SettingsController::class, 'update']);
});
