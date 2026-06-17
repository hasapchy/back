<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('news', 'is_important')) {
            Schema::table('news', function (Blueprint $table) {
                $table->boolean('is_important')->default(false)->after('content');
            });
        }

        if (! Schema::hasTable('news_acknowledgements')) {
            Schema::create('news_acknowledgements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('news_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('company_id');
                $table->timestamp('acknowledged_at');
                $table->timestamps();

                $table->unique(['news_id', 'user_id', 'company_id'], 'news_ack_company_user_unique');
                $table->index(['company_id', 'news_id'], 'news_ack_company_news_index');
                $table->index(['company_id', 'news_id', 'acknowledged_at'], 'news_ack_company_news_ack_at_index');

                $table->foreign('news_id')->references('id')->on('news')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('news_views')) {
            Schema::create('news_views', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('news_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('company_id');
                $table->timestamp('viewed_at');
                $table->timestamps();

                $table->unique(['news_id', 'user_id', 'company_id'], 'news_view_company_user_unique');
                $table->index(['company_id', 'news_id'], 'news_view_company_news_index');

                $table->foreign('news_id')->references('id')->on('news')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('news_views');
        Schema::dropIfExists('news_acknowledgements');

        if (Schema::hasColumn('news', 'is_important')) {
            Schema::table('news', function (Blueprint $table) {
                $table->dropColumn('is_important');
            });
        }
    }
};
