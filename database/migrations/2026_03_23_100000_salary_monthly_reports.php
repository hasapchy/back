<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_monthly_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->date('month');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_monthly_reports');
    }
};
