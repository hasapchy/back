<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::getDatabaseName();

        $tables = [
            'company_user' => ['company_id', 'companies'],
            'category_users' => ['category_id', 'categories'],
        ];

        foreach ($tables as $table => [$fkColumn, $refTable]) {
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
                DB::statement('ALTER TABLE `' . $table . '` DROP FOREIGN KEY `' . $fk->CONSTRAINT_NAME . '`');
            }

            $indexes = DB::select(
                "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = 'creator_id' AND INDEX_NAME != 'PRIMARY'",
                [$db, $table]
            );
            foreach ($indexes as $idx) {
                DB::statement('ALTER TABLE `' . $table . '` DROP INDEX `' . $idx->INDEX_NAME . '`');
            }

            DB::statement('ALTER TABLE `' . $table . '` CHANGE `creator_id` `user_id` BIGINT UNSIGNED NOT NULL');
            $uniqueName = $table === 'company_user' ? 'company_user_company_id_user_id_unique' : 'category_users_category_id_user_id_unique';
            DB::statement('ALTER TABLE `' . $table . '` ADD UNIQUE KEY `' . $uniqueName . '` (`' . $fkColumn . '`, `user_id`)');
            DB::statement('ALTER TABLE `' . $table . '` ADD CONSTRAINT `' . $table . '_' . $fkColumn . '_foreign` FOREIGN KEY (`' . $fkColumn . '`) REFERENCES `' . $refTable . '`(id) ON DELETE CASCADE');
            DB::statement('ALTER TABLE `' . $table . '` ADD CONSTRAINT `' . $table . '_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users`(id) ON DELETE CASCADE');
        }
    }

    public function down(): void
    {
    }
};
