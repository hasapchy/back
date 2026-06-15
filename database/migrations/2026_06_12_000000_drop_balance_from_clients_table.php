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
        if (Schema::hasColumn('clients', 'balance')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn('balance');
            });
        }
    }

    /**
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasColumn('clients', 'balance')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->decimal('balance', 20, 5)->default(0)->after('status');
            });
        }
    }
};
