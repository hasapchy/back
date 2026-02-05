<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('supplier_id')->constrained('projects')->nullOnDelete();
        });
    }
    
    public function down()
    {
        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->dropColumn('project_id');
        });
    }
};
