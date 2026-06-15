<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('financial_account_rules')) {
            return;
        }

        Schema::create('financial_account_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('binding_key', 191)->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('source_type', 191)->nullable();
            $table->unsignedTinyInteger('type')->nullable();
            $table->boolean('is_debt')->nullable();
            $table->unsignedBigInteger('financial_account_id');
            $table->string('direction', 16);
            $table->integer('priority')->default(0);
            $table->boolean('stop_processing')->default(false);
            $table->timestamps();

            $table->foreign('financial_account_id', 'far_account_fk')
                ->references('id')
                ->on('financial_accounts')
                ->restrictOnDelete();
            $table->foreign('category_id', 'far_category_fk')
                ->references('id')
                ->on('transaction_categories')
                ->nullOnDelete();

            $table->unique(
                ['binding_key', 'category_id', 'type', 'is_debt', 'financial_account_id', 'direction'],
                'financial_account_rules_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_account_rules');
    }
};
