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
            $table->string('context');
            $table->unsignedTinyInteger('decimals');
            $table->string('direction');
            $table->decimal('custom_threshold', 3, 2)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'context']);
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_rounding_rules');
    }
};
