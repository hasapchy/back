<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('timeline_read_states')) {
            return;
        }

        Schema::create('timeline_read_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('commentable_type');
            $table->unsignedBigInteger('commentable_id');
            $table->unsignedBigInteger('last_read_comment_id')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'company_id', 'commentable_type', 'commentable_id'],
                'timeline_read_states_user_company_entity_unique'
            );
            $table->index(['company_id', 'commentable_type', 'commentable_id'], 'timeline_read_states_company_entity_idx');
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('timeline_read_states');
    }
};
