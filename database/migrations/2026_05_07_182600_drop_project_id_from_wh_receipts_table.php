<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wh_receipts', function (Blueprint $table): void {
            if (! Schema::hasColumn('wh_receipts', 'project_id')) {
                return;
            }

            $table->dropForeign(['project_id']);
            $table->dropColumn('project_id');
        });
    }

    public function down(): void
    {
        Schema::table('wh_receipts', function (Blueprint $table): void {
            if (Schema::hasColumn('wh_receipts', 'project_id')) {
                return;
            }

            $table->foreignId('project_id')->nullable()->after('supplier_id')->constrained('projects')->nullOnDelete();
        });
    }
};
