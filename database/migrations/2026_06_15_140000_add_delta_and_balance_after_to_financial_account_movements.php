<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_account_movements', function (Blueprint $table): void {
            if (! Schema::hasColumn('financial_account_movements', 'delta')) {
                $table->decimal('delta', 20, 5)->nullable()->after('direction');
            }
            if (! Schema::hasColumn('financial_account_movements', 'balance_after')) {
                $table->decimal('balance_after', 20, 5)->nullable()->after('delta');
            }
        });

        Schema::table('financial_account_movements', function (Blueprint $table): void {
            if (! $this->indexExists('financial_account_movements', 'fam_account_ledger_idx')) {
                $table->index(['financial_account_id', 'transaction_date', 'id'], 'fam_account_ledger_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('financial_account_movements', function (Blueprint $table): void {
            if ($this->indexExists('financial_account_movements', 'fam_account_ledger_idx')) {
                $table->dropIndex('fam_account_ledger_idx');
            }
            if (Schema::hasColumn('financial_account_movements', 'balance_after')) {
                $table->dropColumn('balance_after');
            }
            if (Schema::hasColumn('financial_account_movements', 'delta')) {
                $table->dropColumn('delta');
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list('{$table}')");

            return collect($indexes)->contains(fn ($row) => ($row->name ?? null) === $index);
        }

        $database = $connection->getDatabaseName();
        $result = $connection->select(
            'SELECT COUNT(*) AS cnt FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $index]
        );

        return (int) ($result[0]->cnt ?? 0) > 0;
    }
};
