<?php

use App\Http\Controllers\Api\AppController;
use App\Http\Controllers\Api\CashRegistersController;
use App\Http\Controllers\Api\CategoriesController;
use App\Http\Controllers\api\ClientController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProjectsController;
use App\Http\Controllers\Api\TransactionsController;
use App\Http\Controllers\Api\TransfersController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UsersController;
use App\Http\Controllers\Api\WarehouseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TableOrderController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::post('user/login', [UserController::class, 'login']);
Route::post('user/refresh', [UserController::class, 'refresh']);

Route::middleware('auth:api')->group(function () {
    // app
    Route::get('app/currency', [AppController::class, 'getCurrencyList']);
    Route::get('app/units', [AppController::class, 'getUnitsList']);
    Route::get('app/product_statuses', [AppController::class, 'getProductStatuses']);
    Route::get('app/transaction_categories', [AppController::class, 'getTransactionCategories']);

    // user
    Route::post('user/logout', [UserController::class, 'logout']);
    Route::get('user/me', [UserController::class, 'me']);
    
    // users
    Route::get('admin/users', [UsersController::class, 'getAllUsers']);
    
    // warehouses
    Route::get('warehouses', [WarehouseController::class, 'index']);
    Route::post('warehouses', [WarehouseController::class, 'store']);
    Route::put('warehouses/{id}', [WarehouseController::class, 'update']);
    Route::delete('warehouses/{id}', [WarehouseController::class, 'destroy']);
    
    // categories
    Route::get('categories', [CategoriesController::class, 'index']);
    Route::get('categories/all', [CategoriesController::class, 'all']);
    Route::post('categories', [CategoriesController::class, 'store']);
    Route::put('categories/{id}', [CategoriesController::class, 'update']);
    Route::delete('categories/{id}', [CategoriesController::class, 'destroy']);
    
    // products
    Route::get('products', [ProductController::class, 'products']);
    Route::get('services', [ProductController::class, 'services']);
    Route::post('products', [ProductController::class, 'store']);
    Route::post('products/{id}', [ProductController::class, 'update']);
    
    //clients
    Route::get('clients', [ClientController::class, 'getClients']);
    Route::get('clients/search', [ClientController::class, 'search']);
    Route::post('clients', [ClientController::class, 'createClient']);
    Route::put('clients/{id}', [ClientController::class, 'updateClient']);
    
    // cash registers
    Route::get('cash_registers', [CashRegistersController::class, 'index']);
    Route::get('cash_registers/all', [CashRegistersController::class, 'all']);
    Route::get('cash_registers/balance', [CashRegistersController::class, 'getCashBalance']);
    Route::post('cash_registers', [CashRegistersController::class, 'store']);
    Route::put('cash_registers/{id}', [CashRegistersController::class, 'update']);
    Route::delete('cash_registers/{id}', [CashRegistersController::class, 'destroy']);

    // projects
    Route::get('projects', [ProjectsController::class, 'index']);
    Route::get('projects/all', [ProjectsController::class, 'all']);
    Route::post('projects', [ProjectsController::class, 'store']);
    Route::put('projects/{id}', [ProjectsController::class, 'update']);
    // Route::delete('projects/{id}', [ProjectsController::class, 'destroy']);

    // transactions
    Route::get('transactions', [TransactionsController::class, 'index']);
    // Route::get('transactions/all', [TransactionsController::class, 'all']);
    Route::post('transactions', [TransactionsController::class, 'store']);
    Route::put('transactions/{id}', [TransactionsController::class, 'update']);
    // Route::delete('transactions/{id}', [TransactionsController::class, 'destroy']);
    
    // transfers
    Route::get('transfers', [TransfersController::class, 'index']);
    // Route::get('transactions/all', [TransactionsController::class, 'all']);
    Route::post('transfers', [TransfersController::class, 'store']);
    // Route::put('transactions/{id}', [TransactionsController::class, 'update']);
    // Route::delete('transactions/{id}', [TransactionsController::class, 'destroy']);
});