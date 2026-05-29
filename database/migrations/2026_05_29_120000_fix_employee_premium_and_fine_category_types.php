<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        DB::table('transaction_categories')
            ->where('id', 26)
            ->where('type', 1)
            ->update(['type' => 0]);

        DB::table('transaction_categories')
            ->where('id', 27)
            ->where('type', 0)
            ->update(['type' => 1]);
    }

    /**
     * @return void
     */
    public function down(): void
    {
        DB::table('transaction_categories')
            ->where('id', 26)
            ->where('type', 0)
            ->update(['type' => 1]);

        DB::table('transaction_categories')
            ->where('id', 27)
            ->where('type', 1)
            ->update(['type' => 0]);
    }
};
