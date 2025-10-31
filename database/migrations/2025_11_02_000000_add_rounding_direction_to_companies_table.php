<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('rounding_direction', 20)->default('standard')->after('rounding_enabled');
            $table->decimal('rounding_custom_threshold', 10, 5)->nullable()->after('rounding_direction');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['rounding_direction', 'rounding_custom_threshold']);
        });
    }
};

