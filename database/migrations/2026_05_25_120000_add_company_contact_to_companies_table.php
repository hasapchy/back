<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'address')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('address', 500)->nullable()->after('name');
            });
        }

        if (! Schema::hasColumn('companies', 'phone')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('phone', 64)->nullable()->after('address');
            });
        }

        if (! Schema::hasColumn('companies', 'registration_number')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('registration_number', 128)->nullable()->after('phone');
            });
        }

        if (! Schema::hasColumn('companies', 'email')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('email', 255)->nullable()->after('registration_number');
            });
        }

        if (! Schema::hasColumn('companies', 'warehouse_number')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('warehouse_number', 128)->nullable()->after('email');
            });
        }
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $columns = array_values(array_filter(
            ['warehouse_number', 'email', 'registration_number', 'phone', 'address'],
            static fn (string $column): bool => Schema::hasColumn('companies', $column),
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }
};
