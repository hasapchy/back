<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('company_rounding_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('context'); // orders, receipts, sales, transactions
            $table->unsignedTinyInteger('decimals'); // 2..5
            $table->string('direction'); // standard, up, down, custom
            $table->decimal('custom_threshold', 3, 2)->nullable(); // e.g. 0.60 means >= 0.60 rounds up
            $table->timestamps();

            $table->unique(['company_id', 'context']);
            // company_id без FK (tenant DB)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_rounding_rules');
    }
};


