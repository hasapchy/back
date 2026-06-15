<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'price') && ! Schema::hasColumn('orders', 'def_price')) {
            DB::statement('ALTER TABLE orders
                CHANGE price def_price DECIMAL(20,5) NOT NULL DEFAULT 0,
                CHANGE discount def_discount DECIMAL(20,5) NOT NULL DEFAULT 0,
                CHANGE total_price def_total_price DECIMAL(20,5) NOT NULL DEFAULT 0');
        }

        if (Schema::hasColumn('orders', 'orig_price') && ! Schema::hasColumn('orders', 'price')) {
            DB::statement('ALTER TABLE orders
                CHANGE orig_price price DECIMAL(20,5) NOT NULL DEFAULT 0,
                CHANGE orig_discount discount DECIMAL(20,5) NOT NULL DEFAULT 0,
                CHANGE orig_total_price total_price DECIMAL(20,5) NOT NULL DEFAULT 0');
        }

        if (Schema::hasColumn('orders', 'orig_currency_id') && ! Schema::hasColumn('orders', 'currency_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropForeign(['orig_currency_id']);
            });
            DB::statement('ALTER TABLE orders CHANGE orig_currency_id currency_id BIGINT UNSIGNED NULL');
            Schema::table('orders', function (Blueprint $table) {
                $table->foreign('currency_id')->references('id')->on('currencies')->nullOnDelete();
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'price')) {
                $table->decimal('price', 20, 5)->default(0);
            }
            if (! Schema::hasColumn('orders', 'discount')) {
                $table->decimal('discount', 20, 5)->default(0);
            }
            if (! Schema::hasColumn('orders', 'total_price')) {
                $table->decimal('total_price', 20, 5)->default(0);
            }
            if (! Schema::hasColumn('orders', 'currency_id')) {
                $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            }
            if (! Schema::hasColumn('orders', 'def_currency_id')) {
                $table->foreignId('def_currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            }
            if (! Schema::hasColumn('orders', 'rep_price')) {
                $table->decimal('rep_price', 20, 5)->nullable();
            }
            if (! Schema::hasColumn('orders', 'rep_discount')) {
                $table->decimal('rep_discount', 20, 5)->nullable();
            }
            if (! Schema::hasColumn('orders', 'rep_total_price')) {
                $table->decimal('rep_total_price', 20, 5)->nullable();
            }
            if (! Schema::hasColumn('orders', 'rep_currency_id')) {
                $table->foreignId('rep_currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            }
        });

        $this->dropLegacyOrigColumns();

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->reorderColumn('client_balance_id', 'BIGINT UNSIGNED NULL', 'client_id');
        $this->reorderColumn('creator_id', 'BIGINT UNSIGNED NOT NULL', 'client_balance_id');
        $this->reorderColumn('status_id', 'BIGINT UNSIGNED NOT NULL', 'creator_id');
        $this->reorderColumn('category_id', 'BIGINT UNSIGNED NULL', 'status_id');
        $this->reorderColumn('project_id', 'BIGINT UNSIGNED NULL', 'category_id');
        $this->reorderColumn('warehouse_id', 'BIGINT UNSIGNED NULL', 'project_id');
        $this->reorderColumn('cash_id', 'BIGINT UNSIGNED NULL', 'warehouse_id');
        $this->reorderColumn('note', 'TEXT NULL', 'cash_id');
        $this->reorderColumn('description', 'TEXT NULL', 'note');
        $this->reorderColumn('date', 'DATETIME NOT NULL', 'description');
        $this->reorderColumn('price', 'DECIMAL(20,5) NOT NULL DEFAULT 0', 'date');
        $this->reorderColumn('discount', 'DECIMAL(20,5) NOT NULL DEFAULT 0', 'price');
        $this->reorderColumn('total_price', 'DECIMAL(20,5) NOT NULL DEFAULT 0', 'discount');
        $this->reorderColumn('currency_id', 'BIGINT UNSIGNED NULL', 'total_price');
        $this->reorderColumn('def_price', 'DECIMAL(20,5) NOT NULL DEFAULT 0', 'currency_id');
        $this->reorderColumn('def_discount', 'DECIMAL(20,5) NOT NULL DEFAULT 0', 'def_price');
        $this->reorderColumn('def_total_price', 'DECIMAL(20,5) NOT NULL DEFAULT 0', 'def_discount');
        $this->reorderColumn('def_currency_id', 'BIGINT UNSIGNED NULL', 'def_total_price');
        $this->reorderColumn('rep_price', 'DECIMAL(20,5) NULL', 'def_currency_id');
        $this->reorderColumn('rep_discount', 'DECIMAL(20,5) NULL', 'rep_price');
        $this->reorderColumn('rep_total_price', 'DECIMAL(20,5) NULL', 'rep_discount');
        $this->reorderColumn('rep_currency_id', 'BIGINT UNSIGNED NULL', 'rep_total_price');
        $this->reorderColumn('paid_amount', 'DECIMAL(20,5) NOT NULL DEFAULT 0', 'rep_currency_id');
        $this->reorderColumn('created_at', 'TIMESTAMP NULL', 'paid_amount');
        $this->reorderColumn('updated_at', 'TIMESTAMP NULL', 'created_at');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $this->reorderColumn('creator_id', 'BIGINT UNSIGNED NOT NULL', 'client_id');
            $this->reorderColumn('status_id', 'BIGINT UNSIGNED NOT NULL', 'creator_id');
            $this->reorderColumn('note', 'TEXT NULL', 'status_id');
            $this->reorderColumn('description', 'TEXT NULL', 'paid_amount');
            $this->reorderColumn('date', 'DATETIME NOT NULL', 'description');
            $this->reorderColumn('created_at', 'TIMESTAMP NULL', 'date');
            $this->reorderColumn('updated_at', 'TIMESTAMP NULL', 'created_at');
            $this->reorderColumn('warehouse_id', 'BIGINT UNSIGNED NULL', 'updated_at');
            $this->reorderColumn('cash_id', 'BIGINT UNSIGNED NULL', 'warehouse_id');
            $this->reorderColumn('client_balance_id', 'BIGINT UNSIGNED NULL', 'cash_id');
            $this->reorderColumn('project_id', 'BIGINT UNSIGNED NULL', 'client_balance_id');
            $this->reorderColumn('category_id', 'BIGINT UNSIGNED NULL', 'project_id');
        }

        if (Schema::hasColumn('orders', 'currency_id') && ! Schema::hasColumn('orders', 'orig_currency_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropForeign(['currency_id']);
            });
            DB::statement('ALTER TABLE orders CHANGE currency_id orig_currency_id BIGINT UNSIGNED NULL');
            Schema::table('orders', function (Blueprint $table) {
                $table->foreign('orig_currency_id')->references('id')->on('currencies')->nullOnDelete();
            });
        }

        if (Schema::hasColumn('orders', 'price') && ! Schema::hasColumn('orders', 'orig_price')) {
            DB::statement('ALTER TABLE orders
                CHANGE price orig_price DECIMAL(20,5) NOT NULL DEFAULT 0,
                CHANGE discount orig_discount DECIMAL(20,5) NOT NULL DEFAULT 0,
                CHANGE total_price orig_total_price DECIMAL(20,5) NOT NULL DEFAULT 0');
        }

        if (Schema::hasColumn('orders', 'def_price') && ! Schema::hasColumn('orders', 'price')) {
            DB::statement('ALTER TABLE orders
                CHANGE def_price price DECIMAL(20,5) NOT NULL DEFAULT 0,
                CHANGE def_discount discount DECIMAL(20,5) NOT NULL DEFAULT 0,
                CHANGE def_total_price total_price DECIMAL(20,5) NOT NULL DEFAULT 0');
        }

        Schema::table('orders', function (Blueprint $table) {
            $columns = [
                'rep_currency_id',
                'rep_total_price',
                'rep_discount',
                'rep_price',
                'def_currency_id',
                'currency_id',
                'orig_currency_id',
                'orig_total_price',
                'orig_discount',
                'orig_price',
            ];

            foreach ($columns as $column) {
                if (! Schema::hasColumn('orders', $column)) {
                    continue;
                }

                if (str_ends_with($column, '_currency_id')) {
                    $table->dropForeign([$column]);
                }
                $table->dropColumn($column);
            }
        });
    }

    /**
     * @return void
     */
    private function dropLegacyOrigColumns(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['orig_currency_id', 'orig_total_price', 'orig_discount', 'orig_price'] as $column) {
                if (! Schema::hasColumn('orders', $column)) {
                    continue;
                }

                if ($column === 'orig_currency_id') {
                    $table->dropForeign([$column]);
                }
                $table->dropColumn($column);
            }
        });
    }

    /**
     * @param  string  $column
     * @param  string  $definition
     * @param  string  $after
     * @return void
     */
    private function reorderColumn(string $column, string $definition, string $after): void
    {
        if (! Schema::hasColumn('orders', $column)) {
            return;
        }

        DB::statement("ALTER TABLE orders MODIFY `{$column}` {$definition} AFTER `{$after}`");
    }
};
