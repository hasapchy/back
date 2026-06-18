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
        if (Schema::hasColumn('users', 'profile_wallpaper')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('profile_wallpaper', 64)->nullable()->after('photo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('users', 'profile_wallpaper')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('profile_wallpaper');
        });
    }
};
