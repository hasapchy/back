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
        if (Schema::hasColumn('users', 'ui_preferences')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->json('ui_preferences')->nullable()->after('profile_wallpaper');
            $table->unsignedBigInteger('ui_preferences_updated_at')->nullable()->after('ui_preferences');
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasColumn('users', 'ui_preferences')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ui_preferences', 'ui_preferences_updated_at']);
        });
    }
};
