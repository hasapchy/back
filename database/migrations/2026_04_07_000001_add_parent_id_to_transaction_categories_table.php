<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_categories', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('creator_id')
                ->constrained('transaction_categories')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transaction_categories', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
        });
    }
};
