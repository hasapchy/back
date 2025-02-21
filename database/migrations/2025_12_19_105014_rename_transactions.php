<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('financial_transactions', 'transactions');
    }

    public function down(): void
    {
        Schema::rename('transactions', 'financial_transactions');
    }
};
