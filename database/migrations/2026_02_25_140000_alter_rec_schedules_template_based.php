<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable('rec_schedules')) {
            return;
        }

        if (! Schema::hasColumn('rec_schedules', 'cash_id')) {
            return;
        }

        Schema::table('rec_schedules', function (Blueprint $table) {
            if (! Schema::hasColumn('rec_schedules', 'template_id')) {
                $table->unsignedBigInteger('template_id')->nullable()->after('company_id');
            }
        });

        $fkName = 'rec_schedules_template_id_foreign';
        $fkExists = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'rec_schedules')
            ->where('CONSTRAINT_NAME', $fkName)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();

        if (! $fkExists) {
            Schema::table('rec_schedules', function (Blueprint $table) {
                $table->foreign('template_id')->references('id')->on('templates')->onDelete('cascade');
            });
        }

        Schema::table('rec_schedules', function (Blueprint $table) {
            foreach (['category_id', 'project_id', 'client_id', 'cash_id', 'currency_id'] as $column) {
                if (Schema::hasColumn('rec_schedules', $column)) {
                    $table->dropForeign([$column]);
                }
            }

            $columnsToDrop = array_values(array_filter(
                [
                    'name',
                    'type',
                    'orig_amount',
                    'cash_id',
                    'category_id',
                    'currency_id',
                    'project_id',
                    'client_id',
                    'note',
                    'is_debt',
                    'exchange_rate',
                ],
                fn (string $column): bool => Schema::hasColumn('rec_schedules', $column)
            ));

            if ($columnsToDrop !== []) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('rec_schedules', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->boolean('type');
            $table->unsignedDecimal('orig_amount', 15, 2);
            $table->foreignId('cash_id')->constrained('cash_registers')->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained('transaction_categories')->onDelete('set null');
            $table->foreignId('currency_id')->constrained('currencies')->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');
            $table->foreignId('client_id')->nullable()->constrained('clients')->onDelete('set null');
            $table->text('note')->nullable();
            $table->boolean('is_debt')->default(false);
            $table->unsignedDecimal('exchange_rate', 18, 8)->nullable();
        });

        Schema::table('rec_schedules', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
        });
    }
};
