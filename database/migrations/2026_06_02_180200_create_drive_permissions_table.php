<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drive_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('resource_type', 20);
            $table->unsignedBigInteger('resource_id');
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->string('ability', 20);
            $table->string('effect', 10);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['company_id', 'resource_type', 'resource_id', 'subject_type', 'subject_id', 'ability'],
                'drive_permissions_unique_rule'
            );
            $table->index(['company_id', 'resource_type', 'resource_id'], 'drive_permissions_resource_idx');
            $table->index(['company_id', 'subject_type', 'subject_id'], 'drive_permissions_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_permissions');
    }
};
