<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('transaction_categories', 'parent_id')) {
            return;
        }

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
        if (!Schema::hasColumn('transaction_categories', 'parent_id')) {
            return;
        }

        Schema::table('transaction_categories', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }
};
