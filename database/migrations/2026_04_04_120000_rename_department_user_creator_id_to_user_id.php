<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('department_user')) {
            return;
        }

        if (!Schema::hasColumn('department_user', 'creator_id') || Schema::hasColumn('department_user', 'user_id')) {
            return;
        }

        $db = DB::getDatabaseName();
        $table = 'department_user';

        $fks = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$db, $table, 'FOREIGN KEY']
        );
        foreach ($fks as $fk) {
            DB::statement('ALTER TABLE '.$table.' DROP FOREIGN KEY '.$fk->CONSTRAINT_NAME);
        }

        $indexes = DB::select(
            "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND INDEX_NAME != 'PRIMARY'",
            [$db, $table, 'creator_id']
        );
        foreach ($indexes as $idx) {
            DB::statement('ALTER TABLE '.$table.' DROP INDEX '.$idx->INDEX_NAME);
        }

        DB::statement('ALTER TABLE '.$table.' CHANGE creator_id user_id BIGINT UNSIGNED NOT NULL');

        DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT department_user_department_id_foreign FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT department_user_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE '.$table.' ADD UNIQUE KEY department_user_department_id_user_id_unique (department_id, user_id)');
    }

    public function down(): void
    {
    }
};
