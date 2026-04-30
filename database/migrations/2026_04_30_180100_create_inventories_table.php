<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('creator_id');
            $table->unsignedBigInteger('finalized_by')->nullable();
            $table->unsignedBigInteger('wh_receipt_id')->nullable();
            $table->unsignedBigInteger('wh_write_off_id')->nullable();
            $table->string('status', 32)->default('in_progress');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('category_ids')->nullable();
            $table->unsignedInteger('items_count')->default(0);
            $table->timestamps();

            $table->index(['warehouse_id', 'status']);
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('creator_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('finalized_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('wh_receipt_id')->references('id')->on('wh_receipts')->nullOnDelete();
            $table->foreign('wh_write_off_id')->references('id')->on('wh_write_offs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
