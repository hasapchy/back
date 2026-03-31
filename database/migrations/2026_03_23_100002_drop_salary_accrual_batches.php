<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('salary_accrual_items');
        Schema::dropIfExists('salary_accruals');
    }

    public function down(): void
    {
    }
};
