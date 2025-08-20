<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->addSalesIndexes();
        $this->addClientsIndexes();
        $this->addProductsIndexes();
        $this->addTransactionsIndexes();
        $this->addOrdersIndexes();
        $this->addWarehousesIndexes();
        $this->addWarehouseStocksIndexes();
        $this->addCommentsAndActivityLogIndexes();
        $this->addProjectsIndexes();
        $this->addUsersIndexes();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropSalesIndexes();
        $this->dropClientsIndexes();
        $this->dropProductsIndexes();
        $this->dropTransactionsIndexes();
        $this->dropOrdersIndexes();
        $this->dropWarehousesIndexes();
        $this->dropWarehouseStocksIndexes();
        $this->dropCommentsAndActivityLogIndexes();
        $this->dropProjectsIndexes();
        $this->dropUsersIndexes();
    }

    /**
     * Добавляет индексы для таблицы sales
     */
    private function addSalesIndexes(): void
    {
        if (!Schema::hasTable('sales')) return;

        Schema::table('sales', function (Blueprint $table) {
            $this->addIndexIfNotExists($table, 'sales_date_index', 'date');
            $this->addIndexIfNotExists($table, 'sales_client_id_index', 'client_id');
            $this->addIndexIfNotExists($table, 'sales_warehouse_id_index', 'warehouse_id');
            $this->addIndexIfNotExists($table, 'sales_cash_id_index', 'cash_id');
            $this->addIndexIfNotExists($table, 'sales_user_id_index', 'user_id');
            $this->addIndexIfNotExists($table, 'sales_created_at_index', 'created_at');
        });

        // Составные индексы
        $this->addCompositeIndexIfNotExists('sales', 'sales_date_user_warehouse_index', ['date', 'user_id', 'warehouse_id']);
        $this->addCompositeIndexIfNotExists('sales', 'sales_client_date_index', ['client_id', 'date']);
        $this->addCompositeIndexIfNotExists('sales', 'sales_warehouse_date_index', ['warehouse_id', 'date']);
    }

    /**
     * Добавляет индексы для таблицы clients и связанных таблиц
     */
    private function addClientsIndexes(): void
    {
        if (!Schema::hasTable('clients')) return;

        Schema::table('clients', function (Blueprint $table) {
            $this->addIndexIfNotExists($table, 'clients_first_name_index', 'first_name');
            $this->addIndexIfNotExists($table, 'clients_last_name_index', 'last_name');
            $this->addIndexIfNotExists($table, 'clients_contact_person_index', 'contact_person');
            $this->addIndexIfNotExists($table, 'clients_client_type_index', 'client_type');
            $this->addIndexIfNotExists($table, 'clients_status_index', 'status');
            $this->addIndexIfNotExists($table, 'clients_created_at_index', 'created_at');
        });

        // Составные индексы
        $this->addCompositeIndexIfNotExists('clients', 'clients_name_search_index', ['first_name', 'last_name']);
        $this->addCompositeIndexIfNotExists('clients', 'clients_type_status_index', ['client_type', 'status']);
        $this->addCompositeIndexIfNotExists('clients', 'clients_created_status_index', ['created_at', 'status']);

        // Индексы для связанных таблиц
        if (Schema::hasTable('clients_phones')) {
            Schema::table('clients_phones', function (Blueprint $table) {
                $this->addIndexIfNotExists($table, 'clients_phones_phone_index', 'phone');
                $this->addCompositeIndexIfNotExists('clients_phones', 'clients_phones_client_phone_index', ['client_id', 'phone']);
            });
        }

        if (Schema::hasTable('clients_emails')) {
            Schema::table('clients_emails', function (Blueprint $table) {
                $this->addIndexIfNotExists($table, 'clients_emails_email_index', 'email');
                $this->addCompositeIndexIfNotExists('clients_emails', 'clients_emails_client_email_index', ['client_id', 'email']);
            });
        }

        if (Schema::hasTable('client_balances')) {
            Schema::table('client_balances', function (Blueprint $table) {
                $this->addIndexIfNotExists($table, 'client_balances_balance_index', 'balance');
                $this->addCompositeIndexIfNotExists('client_balances', 'client_balances_client_balance_index', ['client_id', 'balance']);
            });
        }
    }

    /**
     * Добавляет индексы для таблицы products и связанных таблиц
     */
    private function addProductsIndexes(): void
    {
        if (!Schema::hasTable('products')) return;

        Schema::table('products', function (Blueprint $table) {
            $this->addIndexIfNotExists($table, 'products_name_index', 'name');
            $this->addIndexIfNotExists($table, 'products_sku_index', 'sku');
            $this->addIndexIfNotExists($table, 'products_barcode_index', 'barcode');
            $this->addIndexIfNotExists($table, 'products_type_index', 'type');
            $this->addIndexIfNotExists($table, 'products_category_id_index', 'category_id');
            $this->addIndexIfNotExists($table, 'products_unit_id_index', 'unit_id');
            $this->addIndexIfNotExists($table, 'products_created_at_index', 'created_at');
        });

        // Составные индексы
        $this->addCompositeIndexIfNotExists('products', 'products_name_type_index', ['name', 'type']);
        $this->addCompositeIndexIfNotExists('products', 'products_category_type_index', ['category_id', 'type']);
        $this->addCompositeIndexIfNotExists('products', 'products_name_category_index', ['name', 'category_id']);

        // Индексы для связанных таблиц
        if (Schema::hasTable('product_prices')) {
            Schema::table('product_prices', function (Blueprint $table) {
                $this->addIndexIfNotExists($table, 'product_prices_retail_price_index', 'retail_price');
                $this->addIndexIfNotExists($table, 'product_prices_wholesale_price_index', 'wholesale_price');
                $this->addIndexIfNotExists($table, 'product_prices_purchase_price_index', 'purchase_price');
                $this->addCompositeIndexIfNotExists('product_prices', 'product_prices_product_retail_index', ['product_id', 'retail_price']);
            });
        }

        if (Schema::hasTable('categories')) {
            Schema::table('categories', function (Blueprint $table) {
                $this->addIndexIfNotExists($table, 'categories_name_index', 'name');
            });
        }

        if (Schema::hasTable('units')) {
            Schema::table('units', function (Blueprint $table) {
                $this->addIndexIfNotExists($table, 'units_name_index', 'name');
                $this->addIndexIfNotExists($table, 'units_short_name_index', 'short_name');
            });
        }

        if (Schema::hasTable('category_users')) {
            Schema::table('category_users', function (Blueprint $table) {
                $this->addCompositeIndexIfNotExists('category_users', 'category_users_category_user_index', ['category_id', 'user_id']);
                $this->addIndexIfNotExists($table, 'category_users_user_index', 'user_id');
            });
        }
    }

    /**
     * Добавляет индексы для таблицы transactions
     */
    private function addTransactionsIndexes(): void
    {
        if (!Schema::hasTable('transactions')) return;

        Schema::table('transactions', function (Blueprint $table) {
            $this->addIndexIfNotExists($table, 'transactions_date_index', 'date');
            $this->addIndexIfNotExists($table, 'transactions_type_index', 'type');
            $this->addIndexIfNotExists($table, 'transactions_amount_index', 'amount');
            $this->addIndexIfNotExists($table, 'transactions_user_id_index', 'user_id');
            $this->addIndexIfNotExists($table, 'transactions_cash_id_index', 'cash_id');
            $this->addIndexIfNotExists($table, 'transactions_category_id_index', 'category_id');
            $this->addIndexIfNotExists($table, 'transactions_client_id_index', 'client_id');
            $this->addIndexIfNotExists($table, 'transactions_project_id_index', 'project_id');
            $this->addIndexIfNotExists($table, 'transactions_currency_id_index', 'currency_id');
            $this->addIndexIfNotExists($table, 'transactions_created_at_index', 'created_at');
        });

        // Составные индексы
        $this->addCompositeIndexIfNotExists('transactions', 'transactions_date_type_index', ['date', 'type']);
        $this->addCompositeIndexIfNotExists('transactions', 'transactions_date_user_index', ['date', 'user_id']);
        $this->addCompositeIndexIfNotExists('transactions', 'transactions_date_cash_index', ['date', 'cash_id']);
        $this->addCompositeIndexIfNotExists('transactions', 'transactions_client_date_index', ['client_id', 'date']);
        $this->addCompositeIndexIfNotExists('transactions', 'transactions_project_date_index', ['project_id', 'date']);
        $this->addCompositeIndexIfNotExists('transactions', 'transactions_amount_date_index', ['amount', 'date']);
        $this->addCompositeIndexIfNotExists('transactions', 'transactions_currency_date_index', ['currency_id', 'date']);
    }

    /**
     * Добавляет индексы для таблицы orders
     */
    private function addOrdersIndexes(): void
    {
        if (!Schema::hasTable('orders')) return;

        Schema::table('orders', function (Blueprint $table) {
            // Составные индексы для основных полей
            $this->addCompositeIndexIfNotExists('orders', 'idx_orders_user_created', ['user_id', 'created_at']);
            $this->addCompositeIndexIfNotExists('orders', 'idx_orders_client_created', ['client_id', 'created_at']);
            $this->addCompositeIndexIfNotExists('orders', 'idx_orders_warehouse_created', ['warehouse_id', 'created_at']);
            $this->addCompositeIndexIfNotExists('orders', 'idx_orders_status_created', ['status_id', 'created_at']);
            $this->addCompositeIndexIfNotExists('orders', 'idx_orders_category_created', ['category_id', 'created_at']);
            $this->addCompositeIndexIfNotExists('orders', 'idx_orders_project_created', ['project_id', 'created_at']);
            $this->addCompositeIndexIfNotExists('orders', 'idx_orders_cash_created', ['cash_id', 'created_at']);

            // Составные индексы для сложных запросов
            $this->addCompositeIndexIfNotExists('orders', 'idx_orders_user_status_created', ['user_id', 'status_id', 'created_at']);
            $this->addCompositeIndexIfNotExists('orders', 'idx_orders_warehouse_status_created', ['warehouse_id', 'status_id', 'created_at']);
            $this->addCompositeIndexIfNotExists('orders', 'idx_orders_client_status_created', ['client_id', 'status_id', 'created_at']);

            // Индексы для поиска по датам
            $this->addCompositeIndexIfNotExists('orders', 'idx_orders_date_created', ['date', 'created_at']);
            $this->addCompositeIndexIfNotExists('orders', 'idx_orders_timestamps', ['created_at', 'updated_at']);
        });
    }

    /**
     * Добавляет индексы для таблицы warehouses и связанных таблиц
     */
    private function addWarehousesIndexes(): void
    {
        if (!Schema::hasTable('warehouses')) return;

        Schema::table('warehouses', function (Blueprint $table) {
            $this->addIndexIfNotExists($table, 'idx_warehouses_created', 'created_at');
            $this->addIndexIfNotExists($table, 'idx_warehouses_updated', 'updated_at');
            $this->addIndexIfNotExists($table, 'idx_warehouses_name', 'name');
        });

        // Индексы для связующей таблицы wh_users
        if (Schema::hasTable('wh_users')) {
            Schema::table('wh_users', function (Blueprint $table) {
                $this->addCompositeIndexIfNotExists('wh_users', 'idx_wh_users_warehouse_user', ['warehouse_id', 'user_id']);
                $this->addIndexIfNotExists($table, 'idx_wh_users_user', 'user_id');
                $this->addIndexIfNotExists($table, 'idx_wh_users_warehouse', 'warehouse_id');
            });
        }
    }

    /**
     * Добавляет индексы для таблицы warehouse_stocks
     */
    private function addWarehouseStocksIndexes(): void
    {
        if (!Schema::hasTable('warehouse_stocks')) return;

        Schema::table('warehouse_stocks', function (Blueprint $table) {
            // Основные индексы для поиска и фильтрации
            $this->addCompositeIndexIfNotExists('warehouse_stocks', 'idx_warehouse_stocks_warehouse_product', ['warehouse_id', 'product_id']);
            $this->addCompositeIndexIfNotExists('warehouse_stocks', 'idx_warehouse_stocks_product_warehouse', ['product_id', 'warehouse_id']);
            $this->addIndexIfNotExists($table, 'idx_warehouse_stocks_warehouse', 'warehouse_id');
            $this->addIndexIfNotExists($table, 'idx_warehouse_stocks_product', 'product_id');

            // Индексы для сортировки и фильтрации по времени
            $this->addIndexIfNotExists($table, 'idx_warehouse_stocks_created', 'created_at');
            $this->addIndexIfNotExists($table, 'idx_warehouse_stocks_updated', 'updated_at');

            // Составные индексы для сложных запросов
            $this->addCompositeIndexIfNotExists('warehouse_stocks', 'idx_warehouse_stocks_warehouse_created', ['warehouse_id', 'created_at']);
            $this->addCompositeIndexIfNotExists('warehouse_stocks', 'idx_warehouse_stocks_product_created', ['product_id', 'created_at']);

            // Индекс для поиска по количеству
            $this->addIndexIfNotExists($table, 'idx_warehouse_stocks_quantity', 'quantity');
        });
    }

    /**
     * Добавляет индексы для таблиц comments и activity_log
     */
    private function addCommentsAndActivityLogIndexes(): void
    {
        // Индексы для таблицы комментариев
        if (Schema::hasTable('comments')) {
            Schema::table('comments', function (Blueprint $table) {
                $this->addCompositeIndexIfNotExists('comments', 'comments_commentable_index', ['commentable_type', 'commentable_id']);
                $this->addIndexIfNotExists($table, 'comments_user_index', 'user_id');
                $this->addIndexIfNotExists($table, 'comments_created_at_index', 'created_at');
                $this->addCompositeIndexIfNotExists('comments', 'comments_commentable_created_index', ['commentable_type', 'commentable_id', 'created_at']);
            });
        }

        // Индексы для таблицы activity_log
        if (Schema::hasTable('activity_log')) {
            Schema::table('activity_log', function (Blueprint $table) {
                $this->addCompositeIndexIfNotExists('activity_log', 'activity_log_subject_index', ['subject_type', 'subject_id']);
                $this->addIndexIfNotExists($table, 'activity_log_causer_index', 'causer_id');
                $this->addIndexIfNotExists($table, 'activity_log_name_index', 'log_name');
                $this->addIndexIfNotExists($table, 'activity_log_created_at_index', 'created_at');
                $this->addCompositeIndexIfNotExists('activity_log', 'activity_log_subject_created_index', ['subject_type', 'subject_id', 'created_at']);
                $this->addCompositeIndexIfNotExists('activity_log', 'activity_log_causer_created_index', ['causer_id', 'created_at']);
            });
        }

        // Индексы для связанных таблиц
        if (Schema::hasTable('order_products')) {
            Schema::table('order_products', function (Blueprint $table) {
                $this->addIndexIfNotExists($table, 'order_products_order_index', 'order_id');
                $this->addIndexIfNotExists($table, 'order_products_product_index', 'product_id');
            });
        }

        if (Schema::hasTable('order_transactions')) {
            Schema::table('order_transactions', function (Blueprint $table) {
                $this->addIndexIfNotExists($table, 'order_transactions_order_index', 'order_id');
                $this->addIndexIfNotExists($table, 'order_transactions_transaction_index', 'transaction_id');
            });
        }
    }

    /**
     * Добавляет индексы для таблицы projects
     */
    private function addProjectsIndexes(): void
    {
        if (!Schema::hasTable('projects')) return;

        Schema::table('projects', function (Blueprint $table) {
            $this->addIndexIfNotExists($table, 'projects_name_index', 'name');
            $this->addIndexIfNotExists($table, 'projects_user_id_index', 'user_id');
            $this->addIndexIfNotExists($table, 'projects_client_id_index', 'client_id');
            $this->addIndexIfNotExists($table, 'projects_date_index', 'date');
            $this->addIndexIfNotExists($table, 'projects_created_at_index', 'created_at');
        });

        // Составные индексы для projects
        $this->addCompositeIndexIfNotExists('projects', 'projects_user_date_index', ['user_id', 'date']);
        $this->addCompositeIndexIfNotExists('projects', 'projects_client_date_index', ['client_id', 'date']);
        $this->addCompositeIndexIfNotExists('projects', 'projects_name_user_index', ['name', 'user_id']);
    }

    /**
     * Добавляет индексы для таблицы users
     */
    private function addUsersIndexes(): void
    {
        if (!Schema::hasTable('users')) return;

        Schema::table('users', function (Blueprint $table) {
            $this->addIndexIfNotExists($table, 'users_email_index', 'email');
            $this->addIndexIfNotExists($table, 'users_active_index', 'is_active');
        });
    }

    /**
     * Добавляет индекс если он не существует
     */
    private function addIndexIfNotExists(Blueprint $table, string $indexName, string $column): void
    {
        if (!$this->indexExists($table->getTable(), $indexName)) {
            $table->index($column, $indexName);
        }
    }

    /**
     * Добавляет составной индекс если он не существует
     */
    private function addCompositeIndexIfNotExists(string $tableName, string $indexName, array $columns): void
    {
        if (!$this->indexExists($tableName, $indexName)) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName, $columns) {
                $table->index($columns, $indexName);
            });
        }
    }

    /**
     * Добавляет индекс для TEXT колонки с указанием длины
     */
    private function addTextIndexIfNotExists(string $tableName, string $indexName, string $column, int $length = 100): void
    {
        if (!$this->indexExists($tableName, $indexName)) {
            DB::statement("ALTER TABLE `{$tableName}` ADD INDEX `{$indexName}` (`{$column}`({$length}))");
        }
    }

    /**
     * Добавляет индекс для JSON колонки
     */
    private function addJsonIndexIfNotExists(string $tableName, string $indexName, string $column): void
    {
        if (!$this->indexExists($tableName, $indexName)) {
            DB::statement("ALTER TABLE `{$tableName}` ADD INDEX `{$indexName}` ((CAST(`{$column}` AS CHAR(100))))");
        }
    }

    /**
     * Проверяет существование индекса в таблице
     */
    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $indexes = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);
            return count($indexes) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    // Методы для удаления индексов (down)
    private function dropSalesIndexes(): void
    {
        if (!Schema::hasTable('sales')) return;

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndexIfExists('sales_date_index');
            $table->dropIndexIfExists('sales_client_id_index');
            $table->dropIndexIfExists('sales_warehouse_id_index');
            $table->dropIndexIfExists('sales_cash_id_index');
            $table->dropIndexIfExists('sales_user_id_index');
            $table->dropIndexIfExists('sales_created_at_index');
        });

        $this->dropCompositeIndexIfExists('sales', 'sales_date_user_warehouse_index');
        $this->dropCompositeIndexIfExists('sales', 'sales_client_date_index');
        $this->dropCompositeIndexIfExists('sales', 'sales_warehouse_date_index');
    }

    private function dropClientsIndexes(): void
    {
        if (!Schema::hasTable('clients')) return;

        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndexIfExists('clients_first_name_index');
            $table->dropIndexIfExists('clients_last_name_index');
            $table->dropIndexIfExists('clients_contact_person_index');
            $table->dropIndexIfExists('clients_client_type_index');
            $table->dropIndexIfExists('clients_status_index');
            $table->dropIndexIfExists('clients_created_at_index');
        });

        $this->dropCompositeIndexIfExists('clients', 'clients_name_search_index');
        $this->dropCompositeIndexIfExists('clients', 'clients_type_status_index');
        $this->dropCompositeIndexIfExists('clients', 'clients_created_status_index');

        if (Schema::hasTable('clients_phones')) {
            Schema::table('clients_phones', function (Blueprint $table) {
                $table->dropIndexIfExists('clients_phones_phone_index');
                $table->dropIndexIfExists('clients_phones_client_phone_index');
            });
        }

        if (Schema::hasTable('clients_emails')) {
            Schema::table('clients_emails', function (Blueprint $table) {
                $table->dropIndexIfExists('clients_emails_email_index');
                $table->dropIndexIfExists('clients_emails_client_email_index');
            });
        }

        if (Schema::hasTable('client_balances')) {
            Schema::table('client_balances', function (Blueprint $table) {
                $table->dropIndexIfExists('client_balances_balance_index');
                $table->dropIndexIfExists('client_balances_client_balance_index');
            });
        }
    }

    private function dropProductsIndexes(): void
    {
        if (!Schema::hasTable('products')) return;

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndexIfExists('products_name_index');
            $table->dropIndexIfExists('products_sku_index');
            $table->dropIndexIfExists('products_barcode_index');
            $table->dropIndexIfExists('products_type_index');
            $table->dropIndexIfExists('products_category_id_index');
            $table->dropIndexIfExists('products_unit_id_index');
            $table->dropIndexIfExists('products_created_at_index');
        });

        $this->dropCompositeIndexIfExists('products', 'products_name_type_index');
        $this->dropCompositeIndexIfExists('products', 'products_category_type_index');
        $this->dropCompositeIndexIfExists('products', 'products_name_category_index');

        if (Schema::hasTable('product_prices')) {
            Schema::table('product_prices', function (Blueprint $table) {
                $table->dropIndexIfExists('product_prices_retail_price_index');
                $table->dropIndexIfExists('product_prices_wholesale_price_index');
                $table->dropIndexIfExists('product_prices_purchase_price_index');
                $table->dropIndexIfExists('product_prices_product_retail_index');
            });
        }

        if (Schema::hasTable('categories')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropIndexIfExists('categories_name_index');
            });
        }

        if (Schema::hasTable('units')) {
            Schema::table('units', function (Blueprint $table) {
                $table->dropIndexIfExists('units_name_index');
                $table->dropIndexIfExists('units_short_name_index');
            });
        }

        if (Schema::hasTable('category_users')) {
            Schema::table('category_users', function (Blueprint $table) {
                $table->dropIndexIfExists('category_users_category_user_index');
                $table->dropIndexIfExists('category_users_user_index');
            });
        }
    }

    private function dropTransactionsIndexes(): void
    {
        if (!Schema::hasTable('transactions')) return;

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndexIfExists('transactions_date_index');
            $table->dropIndexIfExists('transactions_type_index');
            $table->dropIndexIfExists('transactions_amount_index');
            $table->dropIndexIfExists('transactions_user_id_index');
            $table->dropIndexIfExists('transactions_cash_id_index');
            $table->dropIndexIfExists('transactions_category_id_index');
            $table->dropIndexIfExists('transactions_client_id_index');
            $table->dropIndexIfExists('transactions_project_id_index');
            $table->dropIndexIfExists('transactions_currency_id_index');
            $table->dropIndexIfExists('transactions_created_at_index');
        });

        $this->dropCompositeIndexIfExists('transactions', 'transactions_date_type_index');
        $this->dropCompositeIndexIfExists('transactions', 'transactions_date_user_index');
        $this->dropCompositeIndexIfExists('transactions', 'transactions_date_cash_index');
        $this->dropCompositeIndexIfExists('transactions', 'transactions_client_date_index');
        $this->dropCompositeIndexIfExists('transactions', 'transactions_project_date_index');
        $this->dropCompositeIndexIfExists('transactions', 'transactions_amount_date_index');
        $this->dropCompositeIndexIfExists('transactions', 'transactions_currency_date_index');
    }

    private function dropOrdersIndexes(): void
    {
        if (!Schema::hasTable('orders')) return;

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndexIfExists('idx_orders_user_created');
            $table->dropIndexIfExists('idx_orders_client_created');
            $table->dropIndexIfExists('idx_orders_warehouse_created');
            $table->dropIndexIfExists('idx_orders_status_created');
            $table->dropIndexIfExists('idx_orders_category_created');
            $table->dropIndexIfExists('idx_orders_project_created');
            $table->dropIndexIfExists('idx_orders_cash_created');
            $table->dropIndexIfExists('idx_orders_user_status_created');
            $table->dropIndexIfExists('idx_orders_warehouse_status_created');
            $table->dropIndexIfExists('idx_orders_client_status_created');
            $table->dropIndexIfExists('idx_orders_date_created');
            $table->dropIndexIfExists('idx_orders_timestamps');
        });
    }

    private function dropWarehousesIndexes(): void
    {
        if (!Schema::hasTable('warehouses')) return;

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropIndexIfExists('idx_warehouses_created');
            $table->dropIndexIfExists('idx_warehouses_updated');
            $table->dropIndexIfExists('idx_warehouses_name');
        });

        if (Schema::hasTable('wh_users')) {
            Schema::table('wh_users', function (Blueprint $table) {
                $table->dropIndexIfExists('idx_wh_users_warehouse_user');
                $table->dropIndexIfExists('idx_wh_users_user');
                $table->dropIndexIfExists('idx_wh_users_warehouse');
            });
        }
    }

    private function dropWarehouseStocksIndexes(): void
    {
        if (!Schema::hasTable('warehouse_stocks')) return;

        Schema::table('warehouse_stocks', function (Blueprint $table) {
            $table->dropIndexIfExists('idx_warehouse_stocks_warehouse_product');
            $table->dropIndexIfExists('idx_warehouse_stocks_product_warehouse');
            $table->dropIndexIfExists('idx_warehouse_stocks_warehouse');
            $table->dropIndexIfExists('idx_warehouse_stocks_product');
            $table->dropIndexIfExists('idx_warehouse_stocks_created');
            $table->dropIndexIfExists('idx_warehouse_stocks_updated');
            $table->dropIndexIfExists('idx_warehouse_stocks_warehouse_created');
            $table->dropIndexIfExists('idx_warehouse_stocks_product_created');
            $table->dropIndexIfExists('idx_warehouse_stocks_quantity');
        });
    }

    private function dropCommentsAndActivityLogIndexes(): void
    {
        if (Schema::hasTable('comments')) {
            Schema::table('comments', function (Blueprint $table) {
                $table->dropIndexIfExists('comments_commentable_index');
                $table->dropIndexIfExists('comments_user_index');
                $table->dropIndexIfExists('comments_created_at_index');
                $table->dropIndexIfExists('comments_commentable_created_index');
            });
        }

        if (Schema::hasTable('activity_log')) {
            Schema::table('activity_log', function (Blueprint $table) {
                $table->dropIndexIfExists('activity_log_subject_index');
                $table->dropIndexIfExists('activity_log_causer_index');
                $table->dropIndexIfExists('activity_log_name_index');
                // Убираем удаление несуществующих индексов
                $table->dropIndexIfExists('activity_log_created_at_index');
                $table->dropIndexIfExists('activity_log_subject_created_index');
                $table->dropIndexIfExists('activity_log_causer_created_index');
                // Убираем удаление несуществующих индексов
            });
        }

        if (Schema::hasTable('order_products')) {
            Schema::table('order_products', function (Blueprint $table) {
                $table->dropIndexIfExists('order_products_order_index');
                $table->dropIndexIfExists('order_products_product_index');
            });
        }

        if (Schema::hasTable('order_transactions')) {
            Schema::table('order_transactions', function (Blueprint $table) {
                $table->dropIndexIfExists('order_transactions_order_index');
                $table->dropIndexIfExists('order_transactions_transaction_index');
            });
        }
    }

    private function dropProjectsIndexes(): void
    {
        if (!Schema::hasTable('projects')) return;

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndexIfExists('projects_name_index');
            $table->dropIndexIfExists('projects_user_id_index');
            $table->dropIndexIfExists('projects_client_id_index');
            $table->dropIndexIfExists('projects_date_index');
            $table->dropIndexIfExists('projects_created_at_index');
        });

        // Удаляем составные индексы
        $this->dropCompositeIndexIfExists('projects', 'projects_user_date_index');
        $this->dropCompositeIndexIfExists('projects', 'projects_client_date_index');
        $this->dropCompositeIndexIfExists('projects', 'projects_name_user_index');
    }

    private function dropUsersIndexes(): void
    {
        if (!Schema::hasTable('users')) return;

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndexIfExists('users_email_index');
            $table->dropIndexIfExists('users_active_index');
        });
    }

    private function dropCompositeIndexIfExists(string $tableName, string $indexName): void
    {
        try {
            if ($this->indexExists($tableName, $indexName)) {
                DB::statement("DROP INDEX `{$indexName}` ON `{$tableName}`");
            }
        } catch (\Exception $e) {
            // Игнорируем ошибки при удалении несуществующих индексов
        }
    }
};
