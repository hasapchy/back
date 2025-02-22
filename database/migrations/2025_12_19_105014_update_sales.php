<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        // 1. Переименовываем колонки
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'discount_price')) {
                $table->renameColumn('discount_price', 'discount');
            }
            if (Schema::hasColumn('sales', 'total_amount')) {
                $table->renameColumn('total_amount', 'total_price');
            }
            if (Schema::hasColumn('sales', 'transaction_date')) {
                $table->renameColumn('transaction_date', 'date');
            }
        });

        // 2. Меняем тип колонки date (отдельно от rename)
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'date')) {
                $table->dateTime('date')->change();
            }
        });

        // 3. Удаляем внешний ключ cash_register_id перед удалением колонки
        if (Schema::hasColumn('sales', 'cash_register_id')) {
            $foreignKeys = DB::select("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_NAME = 'sales' AND COLUMN_NAME = 'cash_register_id'");
            if (!empty($foreignKeys)) {
                Schema::table('sales', function (Blueprint $table) {
                    try {
                        $table->dropForeign(['cash_register_id']);
                    } catch (\Throwable $th) {
                        //throw $th;
                    }
                });
            }
            
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('cash_register_id');
            });
        }
        if (Schema::hasColumn('sales', 'cash_id')) {
            $foreignKeys = DB::select("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_NAME = 'sales' AND COLUMN_NAME = 'cash_id'");
            if (!empty($foreignKeys)) {
                Schema::table('sales', function (Blueprint $table) {
                    try {
                        $table->dropForeign(['cash_id']);
                    } catch (\Throwable $th) {
                        //throw $th;
                    }
                });
            }

            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('cash_id');
            });
        }

        // 4. Добавляем колонку cash_id (если её нет)
        if (!Schema::hasColumn('sales', 'cash_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->unsignedBigInteger('cash_id')->nullable();
            });

            Schema::table('sales', function (Blueprint $table) {
                $table->foreign('cash_id')->references('id')->on('cash_registers')->onDelete('cascade');
            });
        }

        // 5. Добавляем orig_currency_id (если его нет)
        if (!Schema::hasColumn('sales', 'orig_currency_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->foreignId('orig_currency_id')->constrained('currencies')->onDelete('cascade');
            });
        }

        // 6. Добавляем orig_price (если его нет)
        if (!Schema::hasColumn('sales', 'orig_price')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->decimal('orig_price', 15, 2)->nullable();
            });
        }
    }

    public function down(): void
    {

        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'orig_currency_id')) {
                $table->dropForeign(['orig_currency_id']);
                $table->dropColumn(['orig_currency_id', 'orig_price']);
            }
            // $table->dropForeign(['cash_id']);
            // $table->unsignedBigInteger('cash_id')->nullable(false)->change();
            // $table->foreign('cash_id')->references('id')->on('cash_registers')->onDelete('cascade');
            // if (Schema::hasColumn('sales', 'cash_id')) {
            //     // Проверяем существование внешнего ключа перед удалением
            //     try {
            //         $table->dropForeign(['cash_id']);
            //     } catch (\Exception $e) {
            //         // Игнорируем ошибку, если ключа нет
            //     }

            //     // Меняем колонку и добавляем внешний ключ заново
            //     $table->unsignedBigInteger('cash_id')->nullable(false)->change();
            //     $table->foreign('cash_id')->references('id')->on('cash_registers')->onDelete('cascade');
            // }
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->date('date')->change();
        });

        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'discount')) {
                $table->renameColumn('discount', 'discount_price');
            }
            if (Schema::hasColumn('sales', 'total_price')) {
                $table->renameColumn('total_price', 'total_amount');
            }
            // if (Schema::hasColumn('sales', 'cash_id')) {
            //     $table->renameColumn('cash_id', 'cash_register_id');
            // }else{
            //     $table->unsignedBigInteger('cash_register_id')->nullable(false)->change();
            //     $table->foreignId('cash_register_id')->nullable(false)->references('id')->default(1)->on('cash_registers')->onDelete('cascade');
            // }
            if (Schema::hasColumn('sales', 'date')) {
                $table->renameColumn('date', 'transaction_date');
            }
        });
        // Schema::table('sales', function (Blueprint $table) {
        //     $table->dropForeign(['orig_currency_id']);
        //     $table->dropColumn(['orig_currency_id', 'orig_price']);
        //     $table->dropForeign(['cash_id']);
        //     $table->unsignedBigInteger('cash_id')->nullable(false)->change();
        //     $table->foreign('cash_id')->references('id')->on('cash_registers')->onDelete('cascade');
        // });


        // Schema::table('sales', function (Blueprint $table) {
        //     $table->date('date')->change();
        // });


        // Schema::table('sales', function (Blueprint $table) {
        //     $table->renameColumn('date', 'transaction_date');
        //     $table->renameColumn('cash_id', 'cash_register_id');
        //     $table->renameColumn('total_price', 'total_amount');
        //     $table->renameColumn('discount', 'discount_price');
        // });
    }
};
