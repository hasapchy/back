<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Используем сырые SQL для совместимости без doctrine/dbal
        $statements = [
            // transactions
            "ALTER TABLE transactions MODIFY COLUMN amount DECIMAL(20,5) NOT NULL",
            "ALTER TABLE transactions MODIFY COLUMN orig_amount DECIMAL(20,5) NOT NULL",

            // orders
            "ALTER TABLE orders MODIFY COLUMN price DECIMAL(20,5) NOT NULL DEFAULT 0",
            "ALTER TABLE orders MODIFY COLUMN discount DECIMAL(20,5) NOT NULL DEFAULT 0",

            // order_products
            "ALTER TABLE order_products MODIFY COLUMN quantity DECIMAL(20,5) NOT NULL DEFAULT 0",
            "ALTER TABLE order_products MODIFY COLUMN price DECIMAL(20,5) NOT NULL DEFAULT 0",
            "ALTER TABLE order_products MODIFY COLUMN discount DECIMAL(20,5) NOT NULL DEFAULT 0",

            // sales
            "ALTER TABLE sales MODIFY COLUMN price DECIMAL(20,5) NOT NULL DEFAULT 0",
            "ALTER TABLE sales MODIFY COLUMN discount DECIMAL(20,5) NOT NULL DEFAULT 0",

            // sales_products
            "ALTER TABLE sales_products MODIFY COLUMN price DECIMAL(20,5) NOT NULL DEFAULT 0",

            // wh_receipts
            "ALTER TABLE wh_receipts MODIFY COLUMN amount DECIMAL(20,5) NOT NULL DEFAULT 0",

            // wh_receipt_products
            "ALTER TABLE wh_receipt_products MODIFY COLUMN price DECIMAL(20,5) NOT NULL DEFAULT 0",

            // product_prices
            "ALTER TABLE product_prices MODIFY COLUMN retail_price DECIMAL(20,5) NOT NULL DEFAULT 0",
            "ALTER TABLE product_prices MODIFY COLUMN wholesale_price DECIMAL(20,5) NOT NULL DEFAULT 0",
            "ALTER TABLE product_prices MODIFY COLUMN purchase_price DECIMAL(20,5) NOT NULL DEFAULT 0",
        ];

        foreach ($statements as $sql) {
            try {
                DB::statement($sql);
            } catch (\Throwable $e) {
                // Пропускаем, если столбца/таблицы нет в конкретной установке
            }
        }
    }

    public function down(): void
    {
        $statements = [
            // transactions
            "ALTER TABLE transactions MODIFY COLUMN amount DECIMAL(20,2) NOT NULL",
            "ALTER TABLE transactions MODIFY COLUMN orig_amount DECIMAL(20,2) NOT NULL",

            // orders
            "ALTER TABLE orders MODIFY COLUMN price DECIMAL(20,2) NOT NULL DEFAULT 0",
            "ALTER TABLE orders MODIFY COLUMN discount DECIMAL(20,2) NOT NULL DEFAULT 0",

            // order_products
            "ALTER TABLE order_products MODIFY COLUMN quantity DECIMAL(20,2) NOT NULL DEFAULT 0",
            "ALTER TABLE order_products MODIFY COLUMN price DECIMAL(20,2) NOT NULL DEFAULT 0",
            "ALTER TABLE order_products MODIFY COLUMN discount DECIMAL(20,2) NOT NULL DEFAULT 0",

            // sales
            "ALTER TABLE sales MODIFY COLUMN price DECIMAL(20,2) NOT NULL DEFAULT 0",
            "ALTER TABLE sales MODIFY COLUMN discount DECIMAL(20,2) NOT NULL DEFAULT 0",

            // sales_products
            "ALTER TABLE sales_products MODIFY COLUMN price DECIMAL(20,2) NOT NULL DEFAULT 0",

            // wh_receipts
            "ALTER TABLE wh_receipts MODIFY COLUMN amount DECIMAL(20,2) NOT NULL DEFAULT 0",

            // wh_receipt_products
            "ALTER TABLE wh_receipt_products MODIFY COLUMN price DECIMAL(20,2) NOT NULL DEFAULT 0",

            // product_prices
            "ALTER TABLE product_prices MODIFY COLUMN retail_price DECIMAL(20,2) NOT NULL DEFAULT 0",
            "ALTER TABLE product_prices MODIFY COLUMN wholesale_price DECIMAL(20,2) NOT NULL DEFAULT 0",
            "ALTER TABLE product_prices MODIFY COLUMN purchase_price DECIMAL(20,2) NOT NULL DEFAULT 0",
        ];

        foreach ($statements as $sql) {
            try {
                DB::statement($sql);
            } catch (\Throwable $e) {
                // no-op
            }
        }
    }
};


