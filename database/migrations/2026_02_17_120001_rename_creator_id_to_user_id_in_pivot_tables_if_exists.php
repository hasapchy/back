<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::getDatabaseName();

        foreach (['wh_users', 'project_users'] as $table) {
            $hasCreatorId = DB::selectOne(
                "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = 'creator_id' LIMIT 1",
                [$db, $table]
            );
            if (!$hasCreatorId) {
                continue;
            }

            $fks = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                [$db, $table]
            );
            foreach ($fks as $fk) {
                DB::statement('ALTER TABLE ' . $table . ' DROP FOREIGN KEY ' . $fk->CONSTRAINT_NAME);
            }

            $indexes = DB::select(
                "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = 'creator_id' AND INDEX_NAME != 'PRIMARY'",
                [$db, $table]
            );
            foreach ($indexes as $idx) {
                DB::statement('ALTER TABLE ' . $table . ' DROP INDEX ' . $idx->INDEX_NAME);
            }

            $pk = $table === 'wh_users' ? 'warehouse_id' : 'project_id';
            DB::statement('ALTER TABLE ' . $table . ' CHANGE creator_id user_id BIGINT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE ' . $table . ' ADD UNIQUE KEY ' . $table . '_' . $pk . '_user_id_unique (' . $pk . ', user_id)');

            if ($table === 'wh_users') {
                DB::statement('ALTER TABLE wh_users ADD CONSTRAINT wh_users_warehouse_id_foreign FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE');
                DB::statement('ALTER TABLE wh_users ADD CONSTRAINT wh_users_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
            } else {
                DB::statement('ALTER TABLE project_users ADD CONSTRAINT project_users_project_id_foreign FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE');
                DB::statement('ALTER TABLE project_users ADD CONSTRAINT project_users_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
            }
        }
    }

    public function down(): void
    {
    }
};
