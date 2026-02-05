<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('project_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->string('number')->comment('Номер контракта');
            $table->decimal('amount', 15, 2)->comment('Сумма контракта');
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->onDelete('set null');
            $table->date('date')->comment('Дата контракта');
            $table->boolean('returned')->default(false)->comment('Контракт возвращен');
            $table->json('files')->nullable()->comment('Файлы контракта');
            $table->timestamps();

            $table->index(['project_id']);
            $table->index(['currency_id']);
            $table->index(['date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_contracts');
    }
};
