<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Переносим существующие связи из products.category_id в product_categories
        DB::statement("
            INSERT INTO product_categories (product_id, category_id, created_at, updated_at)
            SELECT 
                id as product_id, 
                category_id, 
                created_at, 
                updated_at
            FROM products 
            WHERE category_id IS NOT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Удаляем все записи из product_categories
        DB::table('product_categories')->truncate();
    }
};
