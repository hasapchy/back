<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_contracts', function (Blueprint $table) {
            $table->decimal('paid_amount', 15, 5)->default(0)->after('amount');
        });

        $sourceType = 'App\Models\ProjectContract';
        DB::statement("
            UPDATE project_contracts pc
            SET pc.paid_amount = COALESCE(
                (SELECT SUM(t.orig_amount) FROM transactions t
                 WHERE t.source_type = ? AND t.source_id = pc.id AND t.is_debt = 0
                   AND (t.is_deleted = 0 OR t.is_deleted IS NULL)),
                0
            )
        ", [$sourceType]);

        Schema::table('project_contracts', function (Blueprint $table) {
            $table->dropColumn('is_paid');
        });
    }

    public function down(): void
    {
        Schema::table('project_contracts', function (Blueprint $table) {
            $table->boolean('is_paid')->default(false)->after('returned');
        });

        DB::statement('
            UPDATE project_contracts pc
            SET pc.is_paid = (pc.paid_amount >= pc.amount)
        ');

        Schema::table('project_contracts', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
        });
    }
};
