<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('financial_account_movements')) {
            return;
        }

        Schema::create('financial_account_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('financial_account_id');
            $table->unsignedBigInteger('financial_account_rule_id');
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('company_id');
            $table->string('direction', 16);
            $table->decimal('amount_orig', 20, 6);
            $table->decimal('amount_def', 20, 6)->nullable();
            $table->unsignedBigInteger('currency_id');
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->dateTime('transaction_date');
            $table->string('source_type', 191)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('movement_hash', 64);
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('financial_account_id', 'fam_account_fk')
                ->references('id')
                ->on('financial_accounts')
                ->restrictOnDelete();
            $table->foreign('financial_account_rule_id', 'fam_rule_fk')
                ->references('id')
                ->on('financial_account_rules')
                ->restrictOnDelete();
            $table->foreign('transaction_id', 'fam_transaction_fk')
                ->references('id')
                ->on('transactions')
                ->restrictOnDelete();
            $table->foreign('company_id', 'fam_company_fk')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();
            $table->foreign('currency_id', 'fam_currency_fk')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete();

            $table->unique('movement_hash', 'fam_movement_hash_unique');
            $table->index(['transaction_id', 'financial_account_id', 'direction'], 'fam_tx_account_direction_idx');
            $table->index('company_id', 'fam_company_idx');
            $table->index('financial_account_id', 'fam_account_idx');
            $table->index('transaction_date', 'fam_transaction_date_idx');
            $table->index(['financial_account_id', 'company_id', 'is_deleted'], 'fam_account_company_deleted_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_account_movements');
    }
};
