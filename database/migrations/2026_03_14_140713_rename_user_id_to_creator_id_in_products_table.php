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
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'user_id')) {
            return;
        }

        $this->dropForeignKeysOnColumn('products', 'user_id');

        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('user_id', 'creator_id');
        });

        if (!$this->hasForeignKeyOnColumn('products', 'creator_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreign('creator_id')->references('id')->on('users')->onDelete('set null');
            });
        }
    }

    /**
     * @return void
     */
    public function down(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        if (!Schema::hasColumn('products', 'creator_id') || Schema::hasColumn('products', 'user_id')) {
            return;
        }

        $this->dropForeignKeysOnColumn('products', 'creator_id');

        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('creator_id', 'user_id');
        });

        if (!$this->hasForeignKeyOnColumn('products', 'user_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            });
        }
    }

    /**
     * @param string $table
     * @param string $column
     * @return void
     */
    private function dropForeignKeysOnColumn(string $table, string $column): void
    {
        $database = Schema::getConnection()->getDatabaseName();
        $rows = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$database, $table, $column]
        );
        foreach ($rows as $row) {
            DB::statement('ALTER TABLE `' . $table . '` DROP FOREIGN KEY `' . $row->CONSTRAINT_NAME . '`');
        }
    }

    /**
     * @param string $table
     * @param string $column
     * @return bool
     */
    private function hasForeignKeyOnColumn(string $table, string $column): bool
    {
        $database = Schema::getConnection()->getDatabaseName();
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$database, $table, $column]
        );

        return $row && (int) $row->c > 0;
    }
};
