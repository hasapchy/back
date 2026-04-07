<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('supplier_id')->constrained('projects')->nullOnDelete();
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });
        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->dropColumn('project_id');
        });
    }
};
