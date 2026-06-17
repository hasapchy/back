<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('financial_accounts')) {
            return;
        }

        Schema::create('financial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 191);
            $table->string('type', 32);
            $table->boolean('is_system')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_contra')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_accounts');
    }
};
