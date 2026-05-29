<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transaction_category_bindings') && ! Schema::hasTable('company_transaction_category_bindings')) {
            Schema::create('transaction_category_bindings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('binding_key', 191);
                $table->unsignedBigInteger('transaction_category_id');
                $table->timestamps();
            });
        }

        if (Schema::hasTable('transaction_category_bindings')) {
            Schema::table('transaction_category_bindings', function (Blueprint $table): void {
                $table->unique(['company_id', 'binding_key'], 'company_binding_key_unique');
                $table->foreign('company_id', 'ctcb_company_fk')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('transaction_category_id', 'ctcb_category_fk')->references('id')->on('transaction_categories')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_category_bindings');
    }
};
