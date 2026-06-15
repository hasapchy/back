<?php

use App\Models\Company;
use App\Models\TransactionCategory;
use App\Support\TransactionCategoryBindingDefaults;
use App\Support\TransactionCategoryBindingKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transaction_category_bindings')) {
            return;
        }

        $keys = [
            TransactionCategoryBindingKeys::WAREHOUSE_RETURN_PAYABLE_REDUCTION,
            TransactionCategoryBindingKeys::WAREHOUSE_RETURN_SUPPLIER_CREDIT,
        ];

        $validCategoryIds = TransactionCategory::query()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->all();

        $defaults = TransactionCategoryBindingDefaults::all();
        $now = now();

        foreach (Company::query()->pluck('id') as $companyId) {
            $existingKeys = DB::table('transaction_category_bindings')
                ->where('company_id', $companyId)
                ->pluck('binding_key')
                ->all();

            $rows = [];
            foreach ($keys as $bindingKey) {
                if (in_array($bindingKey, $existingKeys, true)) {
                    continue;
                }
                $defaultCategoryId = (int) ($defaults[$bindingKey] ?? 0);
                if ($defaultCategoryId <= 0 || ! isset($validCategoryIds[$defaultCategoryId])) {
                    continue;
                }
                $rows[] = [
                    'company_id' => (int) $companyId,
                    'binding_key' => $bindingKey,
                    'transaction_category_id' => $defaultCategoryId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('transaction_category_bindings')->insert($rows);
            }
        }
    }

    public function down(): void
    {
    }
};
