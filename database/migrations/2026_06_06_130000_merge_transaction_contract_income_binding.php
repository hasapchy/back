<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_KEY = 'transaction.contract.income';

    private const CANONICAL_KEY = 'contract';

    public function up(): void
    {
        if (! Schema::hasTable('transaction_category_bindings')) {
            return;
        }

        $companyIds = DB::table('transaction_category_bindings')
            ->where('binding_key', self::LEGACY_KEY)
            ->distinct()
            ->pluck('company_id');

        foreach ($companyIds as $companyId) {
            $legacyCategoryId = DB::table('transaction_category_bindings')
                ->where('company_id', $companyId)
                ->where('binding_key', self::LEGACY_KEY)
                ->value('transaction_category_id');

            if (! $legacyCategoryId) {
                continue;
            }

            $hasCanonical = DB::table('transaction_category_bindings')
                ->where('company_id', $companyId)
                ->where('binding_key', self::CANONICAL_KEY)
                ->exists();

            if (! $hasCanonical) {
                DB::table('transaction_category_bindings')->updateOrInsert(
                    ['company_id' => $companyId, 'binding_key' => self::CANONICAL_KEY],
                    [
                        'transaction_category_id' => (int) $legacyCategoryId,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            DB::table('transaction_category_bindings')
                ->where('company_id', $companyId)
                ->where('binding_key', self::LEGACY_KEY)
                ->delete();
        }
    }

    public function down(): void
    {
    }
};
