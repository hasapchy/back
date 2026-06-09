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

        $validCategoryIds = TransactionCategory::query()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->all();

        $now = now();
        $companyIds = Company::query()->pluck('id');

        foreach ($companyIds as $companyId) {
            $existingKeys = DB::table('transaction_category_bindings')
                ->where('company_id', $companyId)
                ->pluck('binding_key')
                ->all();

            $rows = [];
            foreach (TransactionCategoryBindingDefaults::all() as $bindingKey => $defaultCategoryId) {
                if (! TransactionCategoryBindingKeys::has($bindingKey)) {
                    continue;
                }
                if (in_array($bindingKey, $existingKeys, true)) {
                    continue;
                }
                if (! isset($validCategoryIds[(int) $defaultCategoryId])) {
                    continue;
                }

                $rows[] = [
                    'company_id' => (int) $companyId,
                    'binding_key' => $bindingKey,
                    'transaction_category_id' => (int) $defaultCategoryId,
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
