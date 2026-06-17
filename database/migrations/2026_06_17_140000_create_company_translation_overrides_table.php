<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_translation_overrides')) {
            return;
        }

        Schema::create('company_translation_overrides', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('domain', 64);
            $table->string('translation_key', 191);
            $table->string('locale', 8);
            $table->text('value');
            $table->timestamps();

            $table->unique(
                ['company_id', 'domain', 'translation_key', 'locale'],
                'company_translation_overrides_unique'
            );
            $table->index(['company_id', 'domain'], 'company_translation_overrides_company_domain_idx');
            $table->foreign('company_id', 'company_translation_overrides_company_fk')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_translation_overrides');
    }
};
