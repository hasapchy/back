<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_balance_movements')) {
            return;
        }

        Schema::create('client_balance_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('client_balance_id');
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('client_id');
            $table->decimal('delta', 20, 5);
            $table->decimal('balance_after', 20, 5);
            $table->dateTime('ledger_at');
            $table->string('movement_hash', 64);
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('client_balance_id', 'cbm_balance_fk')
                ->references('id')
                ->on('client_balances')
                ->restrictOnDelete();
            $table->foreign('transaction_id', 'cbm_transaction_fk')
                ->references('id')
                ->on('transactions')
                ->restrictOnDelete();
            $table->foreign('client_id', 'cbm_client_fk')
                ->references('id')
                ->on('clients')
                ->restrictOnDelete();

            $table->unique('movement_hash', 'cbm_movement_hash_unique');
            $table->index(['client_balance_id', 'ledger_at', 'id'], 'cbm_balance_ledger_idx');
            $table->index(['transaction_id', 'client_balance_id'], 'cbm_tx_balance_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_balance_movements');
    }
};
