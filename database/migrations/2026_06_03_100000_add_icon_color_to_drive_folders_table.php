<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drive_folders', function (Blueprint $table) {
            $table->string('icon_color', 7)->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('drive_folders', function (Blueprint $table) {
            $table->dropColumn('icon_color');
        });
    }
};
