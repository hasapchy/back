<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_schedules', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_schedules', 'template_id')) {
                $table->unsignedBigInteger('template_id')->nullable()->after('company_id');
            }
            $table->foreign('template_id')->references('id')->on('templates')->onDelete('cascade');
        });

        Schema::table('rec_schedules', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropForeign(['project_id']);
            $table->dropForeign(['client_id']);
            $table->dropForeign(['cash_id']);
            $table->dropForeign(['currency_id']);
            $table->dropColumn([
                'name',
                'type',
                'orig_amount',
                'cash_id',
                'category_id',
                'currency_id',
                'project_id',
                'client_id',
                'note',
                'is_debt',
                'exchange_rate',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('rec_schedules', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->boolean('type');
            $table->unsignedDecimal('orig_amount', 15, 2);
            $table->foreignId('cash_id')->constrained('cash_registers')->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained('transaction_categories')->onDelete('set null');
            $table->foreignId('currency_id')->constrained('currencies')->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');
            $table->foreignId('client_id')->nullable()->constrained('clients')->onDelete('set null');
            $table->text('note')->nullable();
            $table->boolean('is_debt')->default(false);
            $table->unsignedDecimal('exchange_rate', 18, 8)->nullable();
        });

        Schema::table('rec_schedules', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
        });
    }
};
