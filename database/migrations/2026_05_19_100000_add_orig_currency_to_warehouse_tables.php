<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('wh_purchases', 'orig_amount')) {
            Schema::table('wh_purchases', function (Blueprint $table) {
                $table->decimal('orig_amount', 20, 5)->nullable()->after('amount');
            });
        }

        if (! Schema::hasColumn('wh_purchases', 'orig_currency_id')) {
            Schema::table('wh_purchases', function (Blueprint $table) {
                $table->foreignId('orig_currency_id')->nullable()->after('orig_amount')->constrained('currencies')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('wh_receipts', 'orig_amount')) {
            Schema::table('wh_receipts', function (Blueprint $table) {
                $table->decimal('orig_amount', 20, 5)->nullable()->after('amount');
            });
        }

        if (! Schema::hasColumn('wh_receipts', 'orig_currency_id')) {
            Schema::table('wh_receipts', function (Blueprint $table) {
                $table->foreignId('orig_currency_id')->nullable()->after('orig_amount')->constrained('currencies')->nullOnDelete();
            });
        }

        foreach (['wh_purchase_products', 'wh_receipt_products'] as $lineTable) {
            if (! Schema::hasColumn($lineTable, 'orig_unit_price')) {
                Schema::table($lineTable, function (Blueprint $table) {
                    $table->decimal('orig_unit_price', 20, 5)->nullable()->after('price');
                });
            }

            if (! Schema::hasColumn($lineTable, 'orig_currency_id')) {
                Schema::table($lineTable, function (Blueprint $table) {
                    $table->foreignId('orig_currency_id')->nullable()->after('orig_unit_price')->constrained('currencies')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['wh_purchase_products', 'wh_receipt_products'] as $lineTable) {
            if (Schema::hasColumn($lineTable, 'orig_currency_id')) {
                Schema::table($lineTable, function (Blueprint $table) {
                    $table->dropForeign(['orig_currency_id']);
                });
            }

            $columns = array_values(array_filter(
                ['orig_unit_price', 'orig_currency_id'],
                fn (string $column) => Schema::hasColumn($lineTable, $column)
            ));

            if ($columns !== []) {
                Schema::table($lineTable, function (Blueprint $table) use ($columns) {
                    $table->dropColumn($columns);
                });
            }
        }

        if (Schema::hasColumn('wh_receipts', 'orig_currency_id')) {
            Schema::table('wh_receipts', function (Blueprint $table) {
                $table->dropForeign(['orig_currency_id']);
            });
        }

        $receiptColumns = array_values(array_filter(
            ['orig_amount', 'orig_currency_id'],
            fn (string $column) => Schema::hasColumn('wh_receipts', $column)
        ));

        if ($receiptColumns !== []) {
            Schema::table('wh_receipts', function (Blueprint $table) use ($receiptColumns) {
                $table->dropColumn($receiptColumns);
            });
        }

        if (Schema::hasColumn('wh_purchases', 'orig_currency_id')) {
            Schema::table('wh_purchases', function (Blueprint $table) {
                $table->dropForeign(['orig_currency_id']);
            });
        }

        $purchaseColumns = array_values(array_filter(
            ['orig_amount', 'orig_currency_id'],
            fn (string $column) => Schema::hasColumn('wh_purchases', $column)
        ));

        if ($purchaseColumns !== []) {
            Schema::table('wh_purchases', function (Blueprint $table) use ($purchaseColumns) {
                $table->dropColumn($purchaseColumns);
            });
        }
    }
};
